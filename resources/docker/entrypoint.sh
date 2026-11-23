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

# These land in SQL below: the names unquoted or in backticks, so they are held to plain
# characters, and the chain id to digits. Values in quotes go through sql_str() instead.
[[ "$DB_NAME" =~ ^[A-Za-z0-9_-]{1,64}$ ]] || { log "FATAL: DB_NAME may hold only A-Z a-z 0-9 _ - (1-64 characters)"; exit 1; }
[[ "$DB_USER" =~ ^[A-Za-z0-9_.-]{1,32}$ ]] || { log "FATAL: DB_USER may hold only A-Z a-z 0-9 _ . - (1-32 characters)"; exit 1; }
[[ "$EVM_CHAIN_ID" =~ ^[0-9]{1,10}$ ]] || { log "FATAL: EVM_CHAIN_ID must be a number"; exit 1; }

# A value as a quoted SQL string literal: backslashes doubled (MySQL reads \ as an escape inside
# quotes), single quotes doubled. A password or URL with either in it then reaches MySQL as it is
# instead of ending the literal early.
sql_str() {
    local s="${1//\\/\\\\}"
    printf "'%s'" "${s//\'/\'\'}"
}

# An endpoint URL as its scheme and host only, for the log: a paid endpoint carries its API key
# in the path or query (or a login before the host), and `docker logs` is no place for it.
url_origin() {
    sed -E 's|^([A-Za-z][A-Za-z0-9+.-]*://)([^/?#@]*@)?([^/?#]*).*|\1\3|' <<<"$1"
}

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
if [ ! -d /var/lib/mysql/mysql ]; then
    log "initialising the MySQL data directory"
    mysqld --initialize-insecure --user=mysql --datadir=/var/lib/mysql
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

# MySQL's root is the container's root user only. --initialize-insecure leaves it without a
# password, and the socket is open to every user in the container, so PHP-FPM's www-data could
# log in as MySQL root (FILE, every database). auth_socket lets in only the OS user of the same
# name: this script runs as root, so it goes on as before, and nothing else in the image logs in
# as MySQL root. Checked on every start, so a data directory made before this is moved over too.
ROOT_PLUGIN=$(mysql --protocol=socket -u root -N -e \
  "SELECT plugin FROM mysql.user WHERE user = 'root' AND host = 'localhost';")
if [ "$ROOT_PLUGIN" != "auth_socket" ]; then
    log "restricting MySQL root to the container's root user (auth_socket)"
    SOCKET_PLUGIN=$(mysql --protocol=socket -u root -N -e \
      "SELECT COUNT(*) FROM information_schema.plugins WHERE plugin_name = 'auth_socket';")
    if [ "$SOCKET_PLUGIN" = "0" ]; then
        # Kept in mysql.plugin, so it is loaded on every start after.
        mysql --protocol=socket -u root -e "INSTALL PLUGIN auth_socket SONAME 'auth_socket.so';"
    fi
    mysql --protocol=socket -u root -e "ALTER USER 'root'@'localhost' IDENTIFIED WITH auth_socket;"
fi

# Whether the first start's setup is done: its last step, `indexer clean confirm` (below), leaves
# the last_processed_coin_block param. Taken from the database, not from the data directory
# being new: a first start that dies part-way through (the user made but the schema not
# imported, or the clean not run) is then taken up again by the next start, where it used to be
# skipped for good, and the node never synced. Until that param is there nothing has been
# synced, so the import below drops nothing of value. A query that fails stops the start
# (set -e) instead of reading as "not set up", which would import the schema over a synced
# database.
PARAMS_TABLE=$(mysql --protocol=socket -u root -N -e \
  "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '${DB_NAME}' AND table_name = 'params';")
SET_UP=0
if [ "$PARAMS_TABLE" = "1" ]; then
    SET_UP=$(mysql --protocol=socket -u root -N -e \
      "SELECT COUNT(*) FROM \`${DB_NAME}\`.params WHERE param = 'last_processed_coin_block';")
fi
FIRST_RUN=false
[ "$SET_UP" = "1" ] || FIRST_RUN=true

# Stored before the user is made with it, so a start that dies in between finds it. A subshell,
# so umask 077 holds for this file only: in braces it stayed for the rest of this script, and
# every service started below (cron and its sync runs among them) got it.
if [ "$(cat "$PW_FILE" 2>/dev/null)" != "$DB_PASSWORD" ]; then
    (umask 077; printf '%s' "$DB_PASSWORD" > "$PW_FILE")
fi

if [ "$FIRST_RUN" = true ]; then
    log "creating database and user"
    mysql --protocol=socket -u root <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`;
CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY $(sql_str "$DB_PASSWORD");
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY $(sql_str "$DB_PASSWORD");
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
        entry=0
        # Split at commas and white space (new lines too: -d '' reads it whole, and read's
        # status at its end is 1), as `for url in $(...)` did, but not glob-expanded: a URL's `?`
        # or `*` is a glob character.
        IFS=$', \t\n' read -r -d '' -a urls <<<"$EVM_RPC_URL" || true
        for url in "${urls[@]}"; do
            url="$(echo "$url" | tr -d '[:space:]')"
            [ -n "$url" ] || continue
            entry=$((entry + 1))
            if [[ ! "$url" =~ ^https?://[^/?#]+ ]]; then
                # Not even the host is shown: what is not a URL may be a key pasted in its place.
                log "skipping EVM endpoint ${entry} of EVM_RPC_URL: not an http(s) URL"
                continue
            fi
            log "adding EVM endpoint for chain ${EVM_CHAIN_ID} (priority ${seq_ts}): $(url_origin "$url")"
            mysql --protocol=socket -u root "$DB_NAME" -e \
              "INSERT IGNORE INTO rpc_list (chain_id, rpc_url, issue_ts) VALUES (${EVM_CHAIN_ID}, $(sql_str "$url"), ${seq_ts});"
            seq_ts=$((seq_ts + 1))
        done
    fi
fi

# CREATE USER IF NOT EXISTS above leaves the password of a user that is there already: one made
# by an earlier start, before DB_PASSWORD changed. That password would reach the generated config
# but not MySQL, and every connection would be refused. So it is set on each start (a no-op when
# unchanged), and kept as the stored password too (above), so a later start without DB_PASSWORD
# goes on with it.
[ "$FIRST_RUN" = true ] || log "applying the database password"
mysql --protocol=socket -u root <<SQL
ALTER USER IF EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY $(sql_str "$DB_PASSWORD");
ALTER USER IF EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY $(sql_str "$DB_PASSWORD");
SQL

# Schema changes for a database created before them. The import above runs on the first start
# only, so a node that already has its tables gets each change here. Each is checked first,
# so this is a no-op on every start after.
SMT_ROOT_KEY=$(mysql --protocol=socket -u root -N -e \
  "SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = '${DB_NAME}' AND table_name = 'checkpoints' AND index_name = 'smt_root';")
if [ "$SMT_ROOT_KEY" = "0" ]; then
    log "adding the smt_root key to checkpoints"
    mysql --protocol=socket -u root "$DB_NAME" -e 'ALTER TABLE `checkpoints` ADD KEY `smt_root` (`smt_root`);'
fi
# The tree key of each name, which Protocol::pathTaken() looks a REG up by.
DOMAIN_KEY_COLUMN=$(mysql --protocol=socket -u root -N -e \
  "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = '${DB_NAME}' AND table_name = 'domains' AND column_name = 'domain_key';")
if [ "$DOMAIN_KEY_COLUMN" = "0" ]; then
    log "adding the domain_key column to domains"
    mysql --protocol=socket -u root "$DB_NAME" -e 'ALTER TABLE `domains` ADD COLUMN `domain_key` binary(16) GENERATED ALWAYS AS (unhex(left(sha2(`domain_name`,256),32))) STORED NOT NULL, ADD UNIQUE KEY `domain_key` (`domain_key`);'
fi
DOMAIN_BLOCK_KEY=$(mysql --protocol=socket -u root -N -e \
  "SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = '${DB_NAME}' AND table_name = 'domains_history' AND index_name = 'domain_block_id';")
# No release has a key on domain_block_id: nothing reads by block range (see schema.sql).
# Dropped if a node has one.
if [ "$DOMAIN_BLOCK_KEY" != "0" ]; then
    log "dropping the unused domain_block_id key of domains_history"
    mysql --protocol=socket -u root "$DB_NAME" -e 'ALTER TABLE `domains_history` DROP KEY `domain_block_id`;'
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
    # As www-data, like the sync in cron (see indexer-cron): a log file it creates is then the
    # API's to write as well.
    setpriv --reuid=www-data --regid=www-data --clear-groups /usr/local/bin/indexer clean confirm
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
