#!/bin/bash
# Boots and supervises the indexer appliance.
#
# Supervision is the part that matters. The previous version launched each service with
# `&` and then ran nginx in the foreground, so if MySQL or rr-proxy died the container
# stayed "up" serving HTTP while the indexer was dead — silently, indefinitely. Here every
# service is watched, and the death of any one of them terminates the container so the
# restart policy can act on it.
set -euo pipefail

log() { echo "[entrypoint] $*"; }

DB_USER="${DB_USER:-namedbuser}"
DB_NAME="${DB_NAME:-kerrigan-name}"
# Chain the anchor contract is deployed on. 56 = BNB Smart Chain. This must match
# EVM_NETWORK.chain_id in the config: rpc_list is queried BY chain id, so endpoints filed
# under the wrong one are invisible and the indexer never syncs a checkpoint — with no
# error, because "no rows" is indistinguishable from "nothing to do".
EVM_CHAIN_ID="${EVM_CHAIN_ID:-56}"

# --- required configuration ---------------------------------------------------------------
# The indexer reads every command from a Kerrigan Core wallet, so these have no usable default.
# Failing here beats booting a container that comes up healthy and then cannot scan a single
# block, which is what an empty RPC user looks like from the outside.
for _v in RPC_HOST RPC_PORT RPC_USER RPC_PASS; do
    if [ -z "${!_v:-}" ]; then
        log "FATAL: $_v is not set — Kerrigan Core wallet RPC settings are required"
        log "       set RPC_HOST, RPC_PORT, RPC_USER and RPC_PASS (see resources/docker/env.sample)"
        exit 1
    fi
done

# /var/log/mysql and /var/log/rr-proxy are recreated here, not just in the image: the
# image deletes the packaged /var/log/mysql to keep the layer clean, and either path can
# also be masked by a volume mount. mysqld refuses to even initialise if it cannot open
# its error log, so this must run before anything starts.
mkdir -p /var/log/indexer /var/log/mysql /var/log/rr-proxy /var/log/nginx \
         /opt/rr-proxy/db /run/php /run/mysqld /run/rr-proxy
chown -R www-data:www-data /var/log/indexer /var/log/rr-proxy /opt/rr-proxy /run/php /run/rr-proxy
chown -R mysql:mysql /run/mysqld /var/log/mysql /var/lib/mysql 2>/dev/null || true

# --- database password ------------------------------------------------------------------
# Generated once and kept beside the data, so it survives a container replacement while
# the volume persists.
PW_FILE=/var/lib/mysql/.indexer_db_password
if [ -z "${DB_PASSWORD:-}" ]; then
    if [ -s "$PW_FILE" ]; then
        DB_PASSWORD="$(cat "$PW_FILE")"
        log "using the stored generated database password"
    else
        # `tr < /dev/urandom | head -c 32` looks natural and is a trap: head exits at 32
        # bytes, tr gets SIGPIPE, and under `set -o pipefail` that kills the script with
        # 141 before a single line is logged. Bounding the read FIRST means every stage
        # sees a clean EOF.
        DB_PASSWORD="$(head -c 256 /dev/urandom | LC_ALL=C tr -dc 'A-Za-z0-9' | cut -c1-32)"
        log "generated a database password"
    fi
fi
export DB_PASSWORD

# --- configuration ----------------------------------------------------------------------
php /usr/local/lib/configure.php
printf 'docker' > /opt/kerrigan-name-indexer/release_type

# --- MySQL ------------------------------------------------------------------------------
FIRST_RUN=false
if [ ! -d /var/lib/mysql/mysql ]; then
    log "initialising the MySQL data directory"
    mysqld --initialize-insecure --user=mysql --datadir=/var/lib/mysql
    FIRST_RUN=true
fi

log "starting MySQL"
mysqld --user=mysql &
MYSQL_PID=$!

log "waiting for MySQL"
for _ in $(seq 1 120); do
    mysqladmin ping --silent 2>/dev/null && break
    sleep 1
done
mysqladmin ping --silent 2>/dev/null || { log "FATAL: MySQL did not become ready"; exit 1; }

if [ "$FIRST_RUN" = true ]; then
    [ -s "$PW_FILE" ] || { umask 077; printf '%s' "$DB_PASSWORD" > "$PW_FILE"; }
    log "creating database and user"
    mysql --protocol=socket -u root <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`;
CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASSWORD}';
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'127.0.0.1';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
-- Required to read XA RECOVER, which is how the indexer resolves a crash between the
-- MySQL and RocksDB halves of a checkpoint commit.
GRANT XA_RECOVER_ADMIN ON *.* TO '${DB_USER}'@'127.0.0.1';
GRANT XA_RECOVER_ADMIN ON *.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL
    log "importing schema"
    mysql --protocol=socket -u root "$DB_NAME" < /opt/kerrigan-name-indexer/resources/schema.sql

    if [ -n "${EVM_RPC_URL:-}" ]; then
        # issue_ts is seeded 0,1,2,… rather than all-zero.
        #
        # getRpcUrl() picks the row with the lowest issue_ts and has no tiebreaker, so
        # identical values leave the choice to MySQL — the selected endpoint then varies
        # between calls, which makes any endpoint-specific problem unreproducible. Distinct
        # seeds give a deterministic preference order instead.
        #
        # It stays correct once endpoints start failing: setRpcUrl() stamps time() (~1.8e9)
        # on a bad row, so anything that has ever failed sorts far behind these seeds, and
        # once all have failed they order by least-recently-failed. Two rows can only
        # collide if they failed within the same second.
        seq_ts=0
        for url in $(echo "$EVM_RPC_URL" | tr ',' ' '); do
            url="$(echo "$url" | tr -d '[:space:]')"
            [ -n "$url" ] || continue
            log "adding EVM endpoint for chain ${EVM_CHAIN_ID} (priority ${seq_ts}): ${url}"
            mysql --protocol=socket -u root "$DB_NAME" -e \
              "INSERT IGNORE INTO rpc_list (chain_id, rpc_url, issue_ts) VALUES (${EVM_CHAIN_ID}, '${url}', ${seq_ts});"
            seq_ts=$((seq_ts + 1))
        done
    fi
fi

# --- rr-proxy ---------------------------------------------------------------------------
# create_if_missing is off in the shipped config so a lost volume fails loudly rather than
# silently serving an empty tree. That protection is only meaningful once the database
# exists, so the very first start is the one place it must be turned on.
RR_ARGS=(--config /etc/rr-proxy.toml)
CREATED_DB=false
if [ ! -d /opt/rr-proxy/db/indexer_rocksdb ]; then
    log "no SMT database yet — enabling create_if_missing for this start only"
    sed -i 's/^create_if_missing = .*/create_if_missing = true/' /etc/rr-proxy.toml
    CREATED_DB=true
else
    sed -i 's/^create_if_missing = .*/create_if_missing = false/' /etc/rr-proxy.toml
fi

log "starting rr-proxy"
setpriv --reuid=www-data --regid=www-data --clear-groups \
    /opt/rr-proxy/rr-proxy "${RR_ARGS[@]}" &
RR_PID=$!

for _ in $(seq 1 60); do
    [ -S /run/rr-proxy/kerrigan-indexer.sock ] && break
    sleep 1
done
[ -S /run/rr-proxy/kerrigan-indexer.sock ] || { log "FATAL: rr-proxy socket never appeared"; exit 1; }

# The database is open now, so restore the guard immediately rather than leaving it on
# until the next start. rr-proxy read the file at startup and does not re-read it, so this
# cannot disturb the running daemon — it only ensures that what is on disk is the value we
# actually want in force, including for anyone who inspects it mid-run.
if [ "$CREATED_DB" = true ]; then
    sed -i 's/^create_if_missing = .*/create_if_missing = false/' /etc/rr-proxy.toml
    log "SMT database created; create_if_missing restored to false"
fi

if [ "$FIRST_RUN" = true ]; then
    log "initialising indexer state"
    /usr/local/bin/indexer clean confirm
fi

# --- web + scheduler --------------------------------------------------------------------
log "starting PHP-FPM"
php-fpm8.5 --nodaemonize &
FPM_PID=$!

log "starting cron"
cron -f &
CRON_PID=$!

log "starting nginx"
nginx -g 'daemon off;' &
NGINX_PID=$!

# --- supervision ------------------------------------------------------------------------
shutdown() {
    log "shutting down"
    kill -TERM "$NGINX_PID" "$CRON_PID" "$FPM_PID" "$RR_PID" 2>/dev/null || true
    # rr-proxy flushes RocksDB on SIGTERM and MySQL needs a clean stop, so both get time.
    wait "$RR_PID" 2>/dev/null || true
    mysqladmin --protocol=socket -u root shutdown 2>/dev/null || kill -TERM "$MYSQL_PID" 2>/dev/null || true
    wait "$MYSQL_PID" 2>/dev/null || true
    exit 0
}
trap shutdown TERM INT

log "all services up"

# `wait -n` returns as soon as ANY child exits. A service dying is a container failure, not
# something to survive quietly: the restart policy is a better recovery mechanism than a
# half-running appliance that still answers health checks.
wait -n
log "FATAL: a supervised service exited — stopping the container"
shutdown
