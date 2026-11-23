<?php declare(strict_types=1);

// The epoch gate of `indexer.php sync` against two fake anchor endpoints (fake_anchor.php), A and B,
// in rpc_list in that order: an epoch other than the reviewed one is acted on only when both read
// it on the same side of the reviewed one, and a stop is held at the lower of their two readings.
// Also the alert of a FATAL error that repeats on every run: told once, then held (fatalToBeTold()).
// Runs a copy of the indexer with a test config under TEST_TMP, on the database of run.sh, with a
// fake rr-proxy and a fake coin node (fake_node.php); the anchor's tip stays at genesis, so a run
// that passes the gate has nothing to sync and ends without an error.

require __DIR__ . '/bootstrap.php';

const EXPECTED = 2;
const PORT_A = 18611;
const PORT_B = 18612;
const PORT_NODE = 18615;

$tmp = getenv('TEST_TMP');
$logs = $tmp . '/gate-logs';
$sock = $tmp . '/gate-rr.sock';
$idx = indexerCopy($tmp . '/gate', 'unix://' . $sock, $logs, EXPECTED, PORT_NODE);

$procs = [spawn(['php', __DIR__ . '/fake_rrproxy.php', $sock, $tmp . '/gate-rr.ops', str_repeat('0', 64)])];
$procs[] = spawn(['php', '-S', '127.0.0.1:' . PORT_NODE, __DIR__ . '/fake_node.php'],
    ['FAKENODE_TIP' => '1000', 'FAKENODE_WALLET' => INCOME_WALLET]);
foreach (['a' => PORT_A, 'b' => PORT_B] as $name => $port) {
    @mkdir($tmp . '/fanchor-' . $name);
    $procs[] = spawn(['php', '-S', '127.0.0.1:' . $port, __DIR__ . '/fake_anchor.php'], ['FANCHOR_DIR' => $tmp . '/fanchor-' . $name,
        'FANCHOR_VENDOR' => $idx . '/vendor', 'FANCHOR_ABI' => $idx . '/resources/evm-anchor.abi']);
}
usleep(800000);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = mysqli_init();
$db->options(MYSQLI_OPT_INT_AND_FLOAT_NATIVE, true);
[$dbHost, $dbPort] = explode(':', DB_CONFIG['host']);
$db->real_connect($dbHost, DB_CONFIG['user'], DB_CONFIG['pass'], DB_CONFIG['db_name'], (int)$dbPort);
exec('php ' . escapeshellarg($idx . '/indexer.php') . ' clean confirm 2>&1', $out, $rc);
$urlA = 'http://127.0.0.1:' . PORT_A . '/';
$urlB = 'http://127.0.0.1:' . PORT_B . '/';

/** Both endpoints' epochs (null: that endpoint is not in rpc_list), the params cleared; one sync run. */
$run = static function (int|string $a, int|string|null $b) use ($db, $tmp, $idx, $logs, $urlA, $urlB): string {
    foreach (['a' => $a, 'b' => $b] as $name => $epoch) {
        file_put_contents($tmp . '/fanchor-' . $name . '/state.json', json_encode(['tip' => 0, 'epoch' => $epoch ?? 0]));
    }
    $db->query("DELETE FROM params WHERE param IN ('sync_stopped', 'epoch_wait', 'state_epoch')");
    $db->query('DELETE FROM rpc_list');
    $db->query("INSERT INTO rpc_list (chain_id, rpc_url, issue_ts) VALUES (56, '$urlA', 1)" .
        ($b === null ? '' : ", (56, '$urlB', 2)"));
    // What the run wrote to the error log, then to the debug log (where a rotated endpoint is
    // told: "Checkpoint discovery stopped early").
    $files = [$logs . '/KNS-indexer.error.log', $logs . '/KNS-indexer.debug.log'];
    clearstatcache();
    $from = array_map(static fn(string $f): int => is_file($f) ? filesize($f) : 0, $files);
    exec('timeout 60 php ' . escapeshellarg($idx . '/indexer.php') . ' sync 2>&1');
    clearstatcache();
    $out = '';
    foreach ($files as $i => $f) {
        $out .= is_file($f) ? (string)file_get_contents($f, false, null, $from[$i]) : '';
    }

    return $out;
};
$param = static fn(string $p): ?int => $db->query("SELECT value FROM params WHERE param = '$p'")->fetch_row()[0] ?? null;
$inUse = static fn(): string => $db->query('SELECT rpc_url FROM rpc_list ORDER BY issue_ts ASC LIMIT 1')->fetch_row()[0];

try {
    section('the reviewed epoch: no gate');
    $log = $run(EXPECTED, EXPECTED);
    check(!str_contains($log, 'epoch') && $param('sync_stopped') === null && $inUse() === $urlA,
        'no epoch message, no stop, A still in use');
    check(!str_contains($log, 'Error') && !str_contains($log, 'Exception'), 'the run ends without an error');

    section('a higher epoch from the only endpoint: refused, but no stop held');
    $log = $run(3, null);
    check(str_contains($log, 'as the only endpoint that answered reads it') && str_contains($log, 'FATAL'),
        'FATAL: not confirmed by another endpoint');
    check($param('sync_stopped') === null, 'no stop held');
    $told = $param('fatal_told_hash');
    check($told !== null && !str_contains($log, 'Held alert'), 'the admin is told, and that is recorded');
    $log = $run(3, null);
    check(str_contains($log, 'as the only endpoint that answered reads it') && str_contains($log, 'Held alert'),
        'the same error on the next run: logged, the alert held');
    // SYNC_STALL_REMIND_SEC later
    $db->query("UPDATE params SET value = value - 21600 WHERE param = 'fatal_told_ts'");
    $log = $run(3, null);
    check(!str_contains($log, 'Held alert') && $param('fatal_told_ts') >= time() - 120, 'told again once SYNC_STALL_REMIND_SEC has passed');

    section('a higher epoch that another endpoint does not confirm');
    $log = $run(3, EXPECTED);
    check(str_contains($log, 'On-chain epoch 3 read, while another endpoint reads epoch 2') && !str_contains($log, 'FATAL'),
        'the endpoint in use is stale or wrong: no FATAL');
    check($param('sync_stopped') === null && $inUse() === $urlB, 'no stop; rotated to B');

    section('a higher epoch read on both');
    $log = $run(1000000, 3);
    check(str_contains($log, 'On-chain epoch 3 (read on two endpoints)') && str_contains($log, ':EPOCH-STOP:'),
        'EPOCH-STOP at the lower reading');
    check(!str_contains($log, 'Held alert') && $param('fatal_told_hash') !== $told, 'another FATAL error is told at once');
    check($param('sync_stopped') === 3, 'the stop is held at 3, not at what A named');
    $before = filesize($logs . '/KNS-indexer.error.log');
    exec('timeout 60 php ' . escapeshellarg($idx . '/indexer.php') . ' sync 2>&1');
    clearstatcache();
    check(filesize($logs . '/KNS-indexer.error.log') === $before, 'the next run is held quietly');

    section('an epoch past PHP_INT_MAX');
    $log = $run('18446744073709551616', 3);
    check(str_contains($log, 'anchoredState currentEpoch: 18446744073709551616 is out of range') && $param('sync_stopped') === null &&
        $inUse() === $urlB, 'refused as an RPC fault; rotated to B, no stop');

    section('a lower epoch that another endpoint does not confirm (a stale endpoint, fresh node)');
    $log = $run(1, EXPECTED);
    check(str_contains($log, 'On-chain epoch 1 read, while another endpoint reads epoch 2') && $param('epoch_wait') === null &&
        $inUse() === $urlB, 'rotated to B, no wait');

    section('a lower epoch read on both: the reviewed change is not in force yet');
    $log = $run(1, 1);
    check(str_contains($log, 'is below the reviewed') && str_contains($log, 'FATAL') && $param('epoch_wait') === EXPECTED,
        'the wait, reported once');
    $log = $run(1, null);
    check(str_contains($log, 'is below the reviewed'), 'the only endpoint reads it lower: the wait as before');

    section('a run that ends without an error');
    check($param('fatal_told_hash') !== null, 'a FATAL error told before');
    $log = $run(EXPECTED, EXPECTED);
    check(!str_contains($log, 'Error') && $param('fatal_told_hash') === null && $param('fatal_told_ts') === null,
        'is over: the next one is told at once');
} finally {
    foreach ($procs as $p) {
        stopProcess($p);
    }
}

done();
