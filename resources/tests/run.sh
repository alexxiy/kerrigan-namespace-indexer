#!/bin/bash
# The indexer's tests. Needs php (the indexer's own version and extensions, `composer install` done)
# and mysqld + mysql (MySQL 8.4 or 9.7). Starts a throwaway mysqld of its own under a temporary directory,
# loads resources/schema.sql into it, runs every test, and removes it all again: nothing of a real
# deployment (config, database, rr-proxy) is read or touched.
#
#   bash resources/tests/run.sh
#   RR_PROXY=/path/to/rr-proxy bash resources/tests/run.sh   # also the PHP client and the API against a real daemon
set -uo pipefail
HERE=$(cd "$(dirname "$0")" && pwd)
BASE=$(cd "$HERE/../.." && pwd)
T=$(mktemp -d /tmp/indexer-tests.XXXXXX)
PORT=${TEST_DB_PORT:-33316}
cleanup() {
    [ -f "$T/mysqld.pid" ] && kill "$(cat "$T/mysqld.pid")" 2>/dev/null
    for _ in $(seq 1 60); do [ -e "$T/mysqld.pid" ] || break; sleep 0.5; done
    rm -rf "$T"
}
trap cleanup EXIT
mkdir -p "$T"/{mysql,logs,tmp}

mysqld --no-defaults --initialize-insecure --datadir="$T/mysql" > "$T/logs/init.log" 2>&1 ||
    { cat "$T/logs/init.log"; exit 1; }
mysqld --no-defaults --datadir="$T/mysql" --socket="$T/mysql.sock" --port="$PORT" --bind-address=127.0.0.1 \
    --mysqlx=OFF --pid-file="$T/mysqld.pid" --log-error="$T/logs/mysqld.err" --tmpdir="$T/tmp" &
for _ in $(seq 1 60); do [ -S "$T/mysql.sock" ] && break; sleep 0.5; done
[ -S "$T/mysql.sock" ] || { echo "mysqld did not start"; cat "$T/logs/mysqld.err"; exit 1; }
M="mysql -uroot -S $T/mysql.sock"

# A fresh database from the schema, for each test that needs one.
fresh_db() {
    $M -e "DROP DATABASE IF EXISTS t; CREATE DATABASE t; CREATE USER IF NOT EXISTS t@'127.0.0.1' IDENTIFIED BY 't';
        GRANT ALL ON t.* TO t@'127.0.0.1'; GRANT XA_RECOVER_ADMIN ON *.* TO t@'127.0.0.1';
        CREATE USER IF NOT EXISTS tro@'127.0.0.1' IDENTIFIED BY 'tro'; GRANT SELECT ON t.* TO tro@'127.0.0.1';" &&
        $M t < "$BASE/resources/schema.sql"
}
export TEST_DB_HOST=127.0.0.1 TEST_DB_PORT=$PORT TEST_DB_NAME=t TEST_LOGS=$T/logs

FAILED=0
run() {
    echo "##### $*"
    if ! "$@"; then
        FAILED=$((FAILED + 1))
    fi
}

for net in mainnet testnet; do
    run env NET=$net php "$HERE/addr_validation_test.php"
    run env NET=$net php "$HERE/addr_corpus_check.php"
    fresh_db && run env NET=$net php "$HERE/protocol_test.php"
done
run php "$HERE/evm_redact_test.php"
run php "$HERE/telegram_redact_test.php"
fresh_db && run php "$HERE/db_test.php"
# A log of its own: it kills its own connections, and the queries that then fail are logged on purpose.
mkdir -p "$T/logs/xa" && fresh_db && run env TEST_LOGS="$T/logs/xa" php "$HERE/xa_test.php"
fresh_db && run env TEST_TMP="$T" php "$HERE/epoch_gate_test.php"

if [ -n "${RR_PROXY:-}" ]; then
    mkdir -p "$T/rr/db"
    cat > "$T/rr/rr.toml" <<EOF
[server]
socket_path = "$T/rr/rr.sock"
socket_perms = "0600"
[logging]
log_file = "$T/logs/rr-proxy.log"
level = "info"
[database]
db_path = "$T/rr/db/t"
create_if_missing = true
[tuning]
max_background_jobs = 2
write_buffer_size_mb = 8
max_open_files = 100
max_input_size = 16777216
sync_on_commit = true
max_batch_entries = 1000000
idle_timeout_secs = 2
EOF
    # The test starts, restarts and stops the daemon itself.
    run env RR_CONFIG="$T/rr/rr.toml" RR_SOCKET="unix://$T/rr/rr.sock" php "$HERE/rrproxy_client_test.php"
    fresh_db && run env TEST_TMP="$T" RR_CONFIG="$T/rr/rr.toml" RR_SOCKET="unix://$T/rr/rr.sock" \
        php "$HERE/api_test.php"
else
    echo "##### rrproxy_client_test.php, api_test.php skipped: set RR_PROXY to an rr-proxy binary to run them"
fi

[ -s "$T/logs/error_db.log" ] && { echo '##### error_db.log'; cut -c1-300 "$T/logs/error_db.log"; }
echo
[ "$FAILED" -eq 0 ] && echo "ALL PASSED" || echo "$FAILED test file(s) FAILED"
exit $((FAILED != 0))
