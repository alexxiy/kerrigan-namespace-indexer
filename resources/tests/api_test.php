<?php declare(strict_types=1);

// The API of a copy of the indexer, served with php -S, on the database of run.sh and a real
// rr-proxy (RR_PROXY, RR_CONFIG, RR_SOCKET, as for rrproxy_client_test.php), with a fake anchor
// endpoint (fake_anchor.php).
//
// /v1.0/resolve: the names are read from MySQL and their proofs from the tree, and an answer is
// given only when the two are of one state - the proofs' root is the root of the checkpoint the
// rows were committed at. The sync commits the tree first and MySQL right after, so the tree ahead
// of MySQL is what a lookup landing between the two commits sees; here it is held there, then
// MySQL catches up. /v1.0/info: the anchored root it caches is written whole. The method is that
// of the path as nginx matched it. /v1.0/checkpoints and /v1.0/history: a block height is an
// integer or a string of digits, nothing else. The API connects as a user that may only read
// (DB_CONFIG_API).

require __DIR__ . '/bootstrap.php';

use Indexer\RustRocksDBProxy;

const API_PORT = 18613;
const ANCHOR_PORT = 18614;

$tmp = getenv('TEST_TMP');
$logs = $tmp . '/resolve-logs';
$idx = indexerCopy($tmp . '/resolve', getenv('RR_SOCKET'), $logs, 2);
// The API reads with a user of its own that may only read (DB_CONFIG_API; run.sh's tro), as a
// deployment can have it: every answer below comes through it.
file_put_contents($idx . '/config/test.inc.php', 'const DB_CONFIG_API = ' .
    var_export(['user' => 'tro', 'pass' => 'tro'] + DB_CONFIG, true) . ";\n", FILE_APPEND);
$daemon = startRrProxy();
$api = spawn(['php', '-S', '127.0.0.1:' . API_PORT, $idx . '/api.php'], [], $logs . '/php-s.out');
@mkdir($tmp . '/fanchor-api');
file_put_contents($tmp . '/fanchor-api/state.json', json_encode(['tip' => 1, 'epoch' => 2]));
$anchor = spawn(['php', '-S', '127.0.0.1:' . ANCHOR_PORT, __DIR__ . '/fake_anchor.php'], ['FANCHOR_DIR' => $tmp . '/fanchor-api',
    'FANCHOR_VENDOR' => $idx . '/vendor', 'FANCHOR_ABI' => $idx . '/resources/evm-anchor.abi']);
usleep(800000);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = mysqli_init();
$db->options(MYSQLI_OPT_INT_AND_FLOAT_NATIVE, true);
[$dbHost, $dbPort] = explode(':', DB_CONFIG['host']);
$db->real_connect($dbHost, DB_CONFIG['user'], DB_CONFIG['pass'], DB_CONFIG['db_name'], (int)$dbPort);

/** One API call: the decoded answer and how long it took, in seconds. */
$call = static function (string $path, ?array $body = null): array {
    $t = microtime(true);
    $raw = file_get_contents('http://127.0.0.1:' . API_PORT . $path, false, stream_context_create(['http' => [
        'method' => $body === null ? 'GET' : 'POST',
        'header' => 'Content-Type: application/json',
        'content' => $body === null ? '' : json_encode($body),
        'ignore_errors' => true,
    ]]));

    return [json_decode((string)$raw, true), microtime(true) - $t];
};

$pk = signer('resolve state owner')['pub'];
$rr = new RustRocksDBProxy(getenv('RR_SOCKET'));
/** A name as the sync leaves it: its record in the tree, and returns the new root in hex. */
$toTree = static fn(string $name, int $nonce, int $price = 0): string =>
    bin2hex($rr->smtUpdate($name, hex2bin($pk), address(0), $price, $nonce));
/** The same name in MySQL, changed in block $block. */
$toDb = static function (string $name, int $nonce, int $block, int $price = 0) use ($db, $pk): void {
    $db->query("INSERT INTO domains (domain_name, created_block_id, updated_block_id, created_ts, updated_ts, nonce)
        VALUES ('$name', $block, $block, 1, 1, $nonce)
        ON DUPLICATE KEY UPDATE updated_block_id = $block, nonce = $nonce");
    $db->query("INSERT INTO domains_history (domain_name, domain_block_id, domain_ts, target_address, owner_pubkey,
        domain_tx, op, nonce, price) VALUES ('$name', $block, 1, '" . address(0) . "', '$pk', '" .
        hash('sha256', $name . $nonce) . "', '" . ($nonce === 1 ? 'REG' : 'UPD') . "', $nonce, $price)");
};
$checkpoint = static fn(int $i, int $block, string $root) =>
    $db->query("INSERT INTO checkpoints (idx, block_id, smt_root) VALUES ($i, $block, '$root')");
$height = static fn(int $block) =>
    $db->query("UPDATE params SET value = $block WHERE param = 'last_processed_coin_block'");

try {
    // The genesis checkpoint and height, and an empty tree.
    exec('php ' . escapeshellarg($idx . '/indexer.php') . ' clean confirm 2>&1', $out, $rc);
    check($rc === 0 && $rr->smtRoot() === str_repeat("\0", 32), 'a clean node');

    section('one state on both sides');
    $toTree('alice.krgn', 1);
    $root1 = $toTree('bob.krgn', 1);
    $toDb('alice.krgn', 1, 100);
    $toDb('bob.krgn', 1, 100);
    $checkpoint(1, 100, $root1);
    $height(100);
    [$r] = $call('/v1.0/resolve/alice.krgn');
    check(($r['response']['smt_root'] ?? '') === $root1 && ($r['response']['nonce'] ?? 0) === 1 &&
        !array_key_exists('state_block', $r['response']), 'alice.krgn at the root of checkpoint 1, nothing else added');
    [$r] = $call('/v1.0/resolve/reverse/' . address(0), ['with_checkpoint' => true]);
    check(array_column($r['response'] ?? [], 'domain_name') === ['alice.krgn', 'bob.krgn'] &&
        ($r['response'][1]['checkpoint']['idx'] ?? null) === 1, 'the reverse list, with its checkpoint');

    section('a height between two checkpoints (SyncProgress), the next one discovered ahead');
    $checkpoint(2, 200, hash('sha256', 'a root the tree has not reached yet'));
    $height(150);
    [$r] = $call('/v1.0/resolve/bob.krgn');
    check(($r['response']['smt_root'] ?? '') === $root1, 'proved at the root of checkpoint 1');

    section('the tree committed, MySQL not yet');
    $root2 = $toTree('alice.krgn', 2, 5);
    [$r, $took] = $call('/v1.0/resolve/alice.krgn');
    check(($r['error']['error_message'] ?? '') === 'The names are being updated, repeat the request',
        'no answer that pairs nonce 1 with a proof made at nonce 2');
    check($took >= 0.2, 'asked again twice before giving up (' . round($took, 2) . ' s)');
    [$r] = $call('/v1.0/resolve/owner/' . $pk, ['count' => 1]);
    check(isset($r['error']), 'a list lookup the same');
    [$r] = $call('/v1.0/resolve/nobody.krgn');
    check(($r['error']['error_message'] ?? '') === 'Domain not found', 'a name that is not there is still not found');

    section('MySQL committed');
    $db->query('DELETE FROM checkpoints WHERE idx = 2');
    $checkpoint(2, 200, $root2);
    $toDb('alice.krgn', 2, 200, 5);
    $height(200);
    [$r, $took] = $call('/v1.0/resolve/alice.krgn', ['with_checkpoint' => true]);
    check(($r['response']['smt_root'] ?? '') === $root2 && ($r['response']['nonce'] ?? 0) === 2 &&
        ($r['response']['price'] ?? 0) === 5 && ($r['response']['checkpoint']['idx'] ?? null) === 2,
        'alice.krgn at nonce 2, proved at the root of checkpoint 2');
    check($took < 0.2, 'at once');
    check($rr->smtVerify('alice.krgn', hex2bin($pk), address(0), 5, 2), 'the tree holds what the answer says');

    section('/v1.0/info: the anchored root cache');
    $db->query("INSERT INTO rpc_list (chain_id, rpc_url, issue_ts) VALUES (56, 'http://127.0.0.1:" . ANCHOR_PORT . "/', 1)");
    $cache = $idx . '/resources/api_anchored_root_cache.txt';
    @unlink($cache);
    [$r] = $call('/v1.0/info');
    check(($r['response']['anchored_smt_root'] ?? '') === hash('sha256', 'root1'), 'the root the anchor names');
    check(@file_get_contents($cache) === hash('sha256', 'root1') && glob($cache . '.*') === [],
        'cached whole, nothing left beside it');

    section('the method: of the path as nginx matches it to a location');
    [$r] = $call('//v9/v1.0/info');
    check(($r['error']['error_message'] ?? '') === 'Wrong API method',
        '//v9/v1.0/info, which nginx serves as /v9/v1.0/info: none (parse_url() read host v9, path /v1.0/info)');
    [$r] = $call('/v1.0/info?x=1');
    check(isset($r['response']['anchored_smt_root']), 'the path before a query: /v1.0/info');

    section('/v1.0/checkpoints and /v1.0/history: a block height');
    $wrong = 'One or several params provided have wrong value';
    foreach (['12abc', [1], -1, 1.5, true, '99999999999999999999', ' 100'] as $bad) {
        [$r] = $call('/v1.0/checkpoints', ['from_block' => $bad, 'order' => 'asc']);
        [$h] = $call('/v1.0/history/alice.krgn', ['from_block_id' => $bad, 'order' => 'asc']);
        check(($r['error']['error_message'] ?? '') === $wrong && ($h['error']['error_message'] ?? '') === $wrong,
            json_encode($bad) . ' is refused');
    }
    foreach ([100, '100', '0100'] as $good) {
        [$r] = $call('/v1.0/checkpoints', ['from_block' => $good, 'order' => 'asc']);
        [$h] = $call('/v1.0/history/alice.krgn', ['from_block_id' => $good, 'order' => 'asc']);
        check(array_column($r['response'] ?? [], 'block_id') === [200] &&
            array_column($h['response'] ?? [], 'domain_block_id') === [200], json_encode($good) . ': the rows after block 100');
    }
    [$r] = $call('/v1.0/checkpoints', ['from_block' => 0, 'order' => 'asc']);
    [$h] = $call('/v1.0/history/alice.krgn', ['from_block_id' => null]);
    check(array_column($r['response'] ?? [], 'block_id') === [1, 100, 200] &&
        array_column($h['response'] ?? [], 'domain_block_id') === [200, 100], '0 and null: from the start');

    section('the API\'s database user (DB_CONFIG_API)');
    try {
        (new mysqli($dbHost, 'tro', 'tro', DB_CONFIG['db_name'], (int)$dbPort))
            ->query("UPDATE params SET value = value WHERE param = 'last_processed_coin_block'");
        check(false, 'tro may only read');
    } catch (mysqli_sql_exception $e) {
        check(str_contains($e->getMessage(), 'denied'), 'tro may only read: ' . $e->getMessage());
    }
    // Every answer above came through tro. With its password wrong, the API has no database: it
    // connects as that user, not as DB_CONFIG's.
    $conf = $idx . '/config/test.inc.php';
    $confText = file_get_contents($conf);
    file_put_contents($conf, str_replace("'pass' => 'tro'", "'pass' => 'wrong'", $confText));
    [$r] = $call('/v1.0/checkpoints', ['from_block' => 0, 'order' => 'asc']);
    check(!isset($r['response']), 'its password wrong: no rows (' . json_encode($r) . ')');
    file_put_contents($conf, $confText);
    [$r] = $call('/v1.0/checkpoints', ['from_block' => 0, 'order' => 'asc']);
    check(array_column($r['response'] ?? [], 'block_id') === [1, 100, 200], 'right again: the rows');

    section('a commit landing while lookups run');
    // Lookups in a loop while the tree and MySQL move on, the tree first: every answer is of one
    // state, whichever it is.
    $results = $tmp . '/resolve-race';
    @mkdir($results);
    $racer = spawn(['php', '-r', '
        $end = microtime(true) + 3; $n = 0;
        while (microtime(true) < $end) {
            $r = json_decode((string)file_get_contents("http://127.0.0.1:' . API_PORT . '/v1.0/resolve/alice.krgn"), true);
            file_put_contents("' . $results . '/" . $n++, json_encode($r));
        }']);
    $roots = [$root2 => 2];
    for ($nonce = 3; $nonce <= 12; $nonce++) {
        usleep(100000);
        $root = $toTree('alice.krgn', $nonce, $nonce);
        usleep(20000);
        $checkpoint($nonce, $nonce * 100, $root);
        $db->begin_transaction();
        $toDb('alice.krgn', $nonce, $nonce * 100, $nonce);
        $height($nonce * 100);
        $db->commit();
        $roots[$root] = $nonce;
    }
    stopProcess($racer);
    $answers = 0;
    $paired = 0;
    $held = 0;
    foreach (glob($results . '/*') as $f) {
        $r = json_decode((string)file_get_contents($f), true);
        if (isset($r['response'])) {
            $answers++;
            if (($roots[$r['response']['smt_root']] ?? -1) === $r['response']['nonce']) {
                $paired++;
            }
        } elseif (($r['error']['error_message'] ?? '') === 'The names are being updated, repeat the request') {
            $held++;
        }
    }
    check($answers > 10 && $paired === $answers, $answers . ' answers, each proved at the root of its own nonce' .
        ($held > 0 ? ' (' . $held . ' held back)' : ''));
} finally {
    $rr->disconnect();
    stopProcess($api);
    stopProcess($anchor);
    stopProcess($daemon);
}

done();
