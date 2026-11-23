#!/bin/bash
# Smoke test of a built indexer image. CI runs it before an image is pushed; by hand:
#
#   resources/docker/smoke-test.sh <image> [docker|podman]
#
# (An image built by podman keeps its HEALTHCHECK only in the docker format: `podman build
# --format docker`.)
#
# It needs no Kerrigan node and no EVM endpoint: the container is started with an RPC address
# nothing listens on and EVM endpoints nothing listens on, which is enough for every service to
# come up, the database to be created and the API to answer from it. What it checks:
#   - every PHP file in the image parses, and nothing of a working tree (the registrar, its API
#     server, tests, git) is in it;
#   - the configuration from the environment: EXPECTED_EPOCH "0" and "abc" refused, junk numbers
#     made safe, DEBUG read;
#   - a first start cut short (mysqld and rr-proxy killed before `indexer clean confirm`), then
#     taken up and finished by the next start;
#   - the first start: MySQL initialised, schema imported, `indexer clean confirm` run as
#     www-data, the EVM endpoints seeded (one that is not an http(s) URL skipped), all services
#     up, and the API answering from MySQL and rr-proxy;
#   - MySQL's root: the container's root user only, not www-data;
#   - no log holds an EVM endpoint's key (path, query, login), the entrypoint's or the sync's;
#   - nginx: CORS and nosniff headers on the API, a query string, a trailing slash and anything
#     outside the API 404, list lookups answered 429 past their burst;
#   - the image's HEALTHCHECK passes;
#   - files the sync writes are www-data's, so the API can still write its logs;
#   - a later start on the same volumes with a changed DB_PASSWORD (applied to MySQL), and
#     one without DB_PASSWORD (the stored password).
# Nothing is left behind: the container and its volumes are removed on exit.
set -euo pipefail

IMAGE="${1:?usage: smoke-test.sh <image> [docker|podman]}"
ENGINE="${2:-docker}"
NAME="kns-indexer-smoke-$$"
# Quotes, a backslash and a dollar sign: each one has broken quoting somewhere before
# (SQL in the entrypoint, the PHP config written by configure.php).
PASS1="sm'ke\\pw\$1\"x"
PASS2="second'pass\\word"
# EVM endpoints nothing listens on, with keys where paid endpoints have them; the second entry is
# not an http(s) URL and is skipped. No log may show any SmokeKey.
EVM_URLS="https://user:SmokeKey1@127.0.0.1:9/v1/SmokeKey2?k=SmokeKey3, ftp://SmokeKey4/x,http://127.0.0.2:9/SmokeKey5"
DB=kerrigan-name

failed=0
ok() { echo "  ok   $*"; }
bad() { echo "  FAIL $*"; failed=1; }

cleanup() {
    "$ENGINE" rm -f "$NAME" >/dev/null 2>&1 || true
    "$ENGINE" volume rm -f "$NAME-mysql" "$NAME-rr" "$NAME-logs" >/dev/null 2>&1 || true
}
trap cleanup EXIT

# What every run gets: the RPC settings the entrypoint requires.
RPC_ENV=(-e RPC_HOST=127.0.0.1 -e RPC_PORT=9 -e RPC_USER=smoke -e RPC_PASS=smoke)
CT_ARGS=(-v "$NAME-mysql:/var/lib/mysql" -v "$NAME-rr:/opt/rr-proxy/db" -v "$NAME-logs:/var/log/indexer"
    "${RPC_ENV[@]}")

# Starts the container on the test volumes; the extra arguments go to `run`.
start() {
    "$ENGINE" run -d --name "$NAME" "${CT_ARGS[@]}" "$@" "$IMAGE" >/dev/null
}

# Whether the entrypoint logged this line. The log is read whole first: `logs | grep -q` fails
# under pipefail whenever grep stops reading before the engine has written all of it.
logged() {
    grep -qxF -- "[entrypoint] $1" <<<"$("$ENGINE" logs "$NAME" 2>&1)"
}

# Waits until the entrypoint reports every service up; false when the container stops first
# or it takes longer than 300 s (the first start initialises MySQL).
wait_up() {
    for _ in $(seq 1 300); do
        if logged 'all services up'; then
            return 0
        fi
        [ "$("$ENGINE" inspect -f '{{.State.Running}}' "$NAME" 2>/dev/null)" = true ] || return 1
        sleep 1
    done
    return 1
}

# Fails the test when $2 shows any of the EVM endpoints' keys.
no_key_in() {
    if grep -q 'SmokeKey' <<<"$2"; then
        bad "$1 shows an EVM endpoint's key: $(grep -m3 'SmokeKey' <<<"$2")"
    else
        ok "$1 shows no EVM endpoint's key"
    fi
}

stop() {
    "$ENGINE" stop -t 60 "$NAME" >/dev/null
    logged 'shutting down' \
        && ok "stops cleanly on SIGTERM" || bad "no clean shutdown in the log"
    no_key_in "the entrypoint's log" "$("$ENGINE" logs "$NAME" 2>&1)"
    "$ENGINE" rm "$NAME" >/dev/null
}

in_ct() { "$ENGINE" exec "$NAME" "$@"; }

# The API's answer to GET /v1.0/info, checked for the fields a node without a working EVM
# endpoint has.
check_info() {
    local body
    body="$(in_ct curl -fsS http://127.0.0.1/v1.0/info)" || { bad "$1: GET /v1.0/info failed"; return; }
    if in_ct php -r '
        $a = json_decode($argv[1], true);
        $r = $a["response"] ?? null;
        exit(is_array($r) && preg_match("/^[0-9a-f]{64}$/D", (string)($r["indexer_smt_root"] ?? "")) === 1
            && array_key_exists("anchored_smt_root", $r) && $r["anchored_smt_root"] === null
            && $r["indexer_synced"] === false && is_int($r["domains_count"]) ? 0 : 1);' "$body"; then
        ok "$1: /v1.0/info answers from MySQL and rr-proxy"
    else
        bad "$1: /v1.0/info answer: $body"
    fi
}

# Whether the image's HEALTHCHECK passes: podman runs it on demand; docker runs it on its own
# schedule, so its status is waited for.
healthy() {
    if [ "$ENGINE" = podman ]; then
        "$ENGINE" healthcheck run "$NAME" >/dev/null 2>&1
        return
    fi
    for _ in $(seq 1 120); do
        [ "$("$ENGINE" inspect -f '{{.State.Health.Status}}' "$NAME" 2>/dev/null)" = healthy ] && return 0
        sleep 1
    done
    return 1
}

echo "=== image: $IMAGE"
if "$ENGINE" run --rm --entrypoint sh "$IMAGE" -c \
    'cd /opt/kerrigan-name-indexer && find . -name "*.php" -not -path "./vendor/*" -print0 | xargs -0 -n1 php -l >/dev/null'; then
    ok "every PHP file parses"
else
    bad "php -l"
fi
held="$("$ENGINE" run --rm --entrypoint sh "$IMAGE" -c \
    'cd /opt/kerrigan-name-indexer && for p in registrar registrar-api resources/tests .git .github 1.php release_type; do
        [ -e "$p" ] && echo "$p"; done; true')"
[ -z "$held" ] && ok "nothing of a working tree in the image" || bad "the image holds: $held"

echo "=== configuration from the environment"
for epoch in 0 abc; do
    if out="$("$ENGINE" run --rm "${RPC_ENV[@]}" -e EXPECTED_EPOCH="$epoch" "$IMAGE" 2>&1)"; then
        bad "EXPECTED_EPOCH=$epoch: the container started"
    elif grep -q 'EXPECTED_EPOCH must be a positive integer' <<<"$out"; then
        ok "EXPECTED_EPOCH=$epoch refused"
    else
        bad "EXPECTED_EPOCH=$epoch: $out"
    fi
done
# The config configure.php writes for these values, without starting anything.
config_for() {
    "$ENGINE" run --rm --entrypoint sh "${RPC_ENV[@]}" "$@" "$IMAGE" -c \
        'php /usr/local/lib/configure.php >/dev/null && cat /opt/kerrigan-name-indexer/config/docker.inc.php'
}
cfg="$(config_for -e EVM_THROTTLING=junk -e EVM_BATCH_SIZE=-5 -e DEBUG=false -e EXPECTED_EPOCH=7)"
grep -qx 'const EVM_THROTTLING = 0;' <<<"$cfg" && grep -qx 'const EVM_BATCH_SIZE = 1;' <<<"$cfg" \
    && ok "junk EVM_THROTTLING is 0, a negative EVM_BATCH_SIZE is 1" \
    || bad "EVM_THROTTLING / EVM_BATCH_SIZE: $(grep -E 'EVM_(THROTTLING|BATCH_SIZE) =' <<<"$cfg")"
grep -qx 'const DEBUG = false;' <<<"$cfg" && grep -q "'expected_epoch' => 7," <<<"$cfg" \
    && ok "DEBUG=false and EXPECTED_EPOCH=7 written" || bad "DEBUG / EXPECTED_EPOCH not written"
cfg="$(config_for -e DEBUG=)"
grep -qx 'const DEBUG = true;' <<<"$cfg" && ok "an empty DEBUG keeps the debug logs" || bad "an empty DEBUG: $(grep 'DEBUG =' <<<"$cfg")"

echo "=== first start, cut short before the indexer is initialised"
# The image's own entrypoint, with mysqld and rr-proxy killed (SIGKILL: no clean stop) where it
# would initialise the indexer: the data directory, database, user, tables and endpoints are
# there, `indexer clean confirm` has not run.
"$ENGINE" run -d --name "$NAME" "${CT_ARGS[@]}" -e DB_PASSWORD="$PASS1" -e EVM_RPC_URL="$EVM_URLS" \
    --entrypoint bash "$IMAGE" -c \
    'sed "s/^\( *\)log \"initialising indexer state\"$/\1kill -KILL \"\$MYSQL_PID\" \"\$RR_PID\"; exit 3/" \
        /usr/local/bin/entrypoint.sh > /tmp/cut.sh && grep -q "exit 3" /tmp/cut.sh && exec bash /tmp/cut.sh' >/dev/null
for _ in $(seq 1 300); do
    [ "$("$ENGINE" inspect -f '{{.State.Running}}' "$NAME" 2>/dev/null)" = true ] || break
    sleep 1
done
if [ "$("$ENGINE" inspect -f '{{.State.ExitCode}}' "$NAME")" = 3 ] && logged 'importing schema' \
    && logged "restricting MySQL root to the container's root user (auth_socket)" \
    && ! logged 'initialising indexer state'; then
    ok "cut short after the schema, before the indexer state"
else
    bad "the cut start did not end where it should"
    "$ENGINE" logs "$NAME" 2>&1 | tail -20
fi
no_key_in "the cut start's log" "$("$ENGINE" logs "$NAME" 2>&1)"
"$ENGINE" rm "$NAME" >/dev/null

echo "=== first start, taken up"
start -e DB_PASSWORD="$PASS1" -e EVM_RPC_URL="$EVM_URLS"
if ! wait_up; then
    bad "the services did not come up"
    "$ENGINE" logs "$NAME" 2>&1 | tail -40
    exit 1
fi
ok "all services up"
! logged 'initialising the MySQL data directory' && logged 'creating database and user' && logged 'importing schema' \
    && logged 'initialising indexer state' \
    && ok "the setup is done again on the data directory there, and finished" || bad "the setup was not taken up"
logged 'adding EVM endpoint for chain 56 (priority 0): https://127.0.0.1:9' \
    && logged 'skipping EVM endpoint 2 of EVM_RPC_URL: not an http(s) URL' \
    && logged 'adding EVM endpoint for chain 56 (priority 1): http://127.0.0.2:9' \
    && ok "EVM endpoints seeded in order, logged by scheme and host, a non-http one skipped" \
    || bad "EVM endpoint lines: $("$ENGINE" logs "$NAME" 2>&1 | grep 'EVM endpoint')"
rows="$(in_ct mysql --protocol=socket -u root -N -e "SELECT rpc_url FROM \`$DB\`.rpc_list WHERE chain_id = 56 ORDER BY rpc_url")"
[ "$rows" = $'http://127.0.0.2:9/SmokeKey5\nhttps://user:SmokeKey1@127.0.0.1:9/v1/SmokeKey2?k=SmokeKey3' ] \
    && ok "rpc_list: the two endpoints, whole, once each" || bad "rpc_list: $rows"
[ "$(in_ct mysql --protocol=socket -u root -N -e "SELECT plugin FROM mysql.user WHERE user = 'root'")" = auth_socket ] \
    && ok "MySQL root: auth_socket only" || bad "MySQL root's plugin"
if in_ct setpriv --reuid=www-data --regid=www-data --clear-groups \
    mysql --protocol=socket -u root -e 'SELECT 1' >/dev/null 2>&1; then
    bad "www-data logs in as MySQL root"
else
    ok "www-data cannot log in as MySQL root"
fi
check_info "first start"

headers="$(in_ct curl -sS -o /dev/null -D - -X OPTIONS http://127.0.0.1/v1.0/info | tr -d '\r')"
grep -q '^HTTP/1.1 204' <<<"$headers" && grep -qi '^X-Content-Type-Options: nosniff$' <<<"$headers" \
    && grep -qi '^Access-Control-Allow-Origin: \*$' <<<"$headers" \
    && ok "preflight: 204 with CORS and nosniff" || bad "preflight headers: $headers"
headers="$(in_ct curl -sS -o /dev/null -D - http://127.0.0.1/v1.0/info | tr -d '\r')"
grep -qi '^X-Content-Type-Options: nosniff$' <<<"$headers" && grep -qi '^Content-Type: application/json' <<<"$headers" \
    && ok "API answer: JSON with nosniff" || bad "API answer headers: $headers"
for path in /config.php /indexer.php /resources/schema.sql /vendor/autoload.php / '/v1.0/info?x' /v1.0/info/; do
    code="$(in_ct curl -sS -o /dev/null -w '%{http_code}' "http://127.0.0.1$path")"
    [ "$code" = 404 ] && ok "$path: 404" || bad "$path: $code"
done
# 100 list lookups at once: the node's bucket takes 40 past the first, then 20 a second.
codes="$(in_ct curl -sS --parallel --parallel-immediate --parallel-max 100 -o /dev/null -w '%{http_code}\n' \
    'http://127.0.0.1/v1.0/resolve/reverse/smoke[1-100]')"
n429="$(grep -c '^429$' <<<"$codes" || true)"
nother="$(grep -vc '^\(200\|429\)$' <<<"$codes" || true)"
[ "$n429" -ge 1 ] && [ "$n429" -le 59 ] && [ "$nother" = 0 ] \
    && ok "list lookups past the burst: $n429 of 100 answered 429" || bad "list lookups: $n429 answered 429, $nother neither 200 nor 429"
sleep 3
healthy && ok "the HEALTHCHECK passes" || bad "the HEALTHCHECK does not pass"

# A sync as cron runs it. It fails here (no Kerrigan node, no EVM endpoint), which is fine: what
# matters is that what it writes stays writable for the API (PHP-FPM runs as www-data), and that
# what it logs of the endpoints is their host.
in_ct setpriv --reuid=www-data --regid=www-data --clear-groups /usr/local/bin/indexer sync >/dev/null 2>&1 || true
grep -q ' www-data /usr/local/bin/indexer sync' <<<"$(in_ct cat /etc/cron.d/indexer-sync)" \
    && ok "cron runs the sync as www-data" || bad "the cron entry does not run as www-data"
owned="$(in_ct find /var/log/indexer /opt/kerrigan-name-indexer/resources -not -user www-data)"
[ -z "$owned" ] && ok "logs and resources/ are all www-data's" || bad "not www-data's: $owned"
logs="$(in_ct sh -c 'cat /var/log/indexer/*.log')"
grep -q 'Checkpoint discovery stopped early: .*127\.0\.0\.[12]:9' <<<"$logs" \
    && ok "the sync logged the endpoint's failure, by its host" || bad "no endpoint failure in the sync's logs"
no_key_in "the indexer's logs" "$logs"
check_info "after a failed sync"
stop

echo "=== second start, DB_PASSWORD changed"
start -e DB_PASSWORD="$PASS2"
if wait_up; then
    ok "all services up"
    logged 'applying the database password' && ! logged 'creating database and user' \
        && ok "password applied on a later start, no setup again" || bad "a later start's setup lines"
    check_info "changed password"
    [ "$(in_ct cat /var/lib/mysql/.indexer_db_password)" = "$PASS2" ] \
        && ok "stored password updated" || bad "the stored password is not the new one"
    [ "$(in_ct stat -c %a /var/lib/mysql/.indexer_db_password)" = 600 ] \
        && ok "stored password file is 0600" || bad "stored password file mode"
else
    bad "the services did not come up"
    "$ENGINE" logs "$NAME" 2>&1 | tail -40
fi
stop

echo "=== third start, no DB_PASSWORD"
start
if wait_up; then
    ok "all services up"
    logged 'using the stored generated database password' \
        && ok "stored password used" || bad "no 'using the stored generated database password' in the log"
    check_info "stored password"
else
    bad "the services did not come up"
    "$ENGINE" logs "$NAME" 2>&1 | tail -40
fi
stop

echo
[ "$failed" = 0 ] && echo "smoke test passed" || echo "smoke test FAILED"
exit "$failed"
