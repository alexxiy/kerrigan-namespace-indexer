<?php declare(strict_types=1);

// Database's XA transaction as indexer.php and the registrar's batchProcessor run it: started,
// written, prepared (xaTXFinalize(skipCommit: true)), then committed (xaTXCommit()). And what each
// step answers when nothing was written, or when the connection is lost on the way: a COMMIT that
// fails is answered false - the transaction stays PREPARED on the server, holding its locks, for
// checkUncommitedXATX() to settle on the next run - where it was answered true, and the sync went
// on to write behind those locks.
//
// Every warning fails the test. Run on a throwaway database only (run.sh's): it kills its own
// connections.

require __DIR__ . '/bootstrap.php';

use Indexer\Database;

set_error_handler(static function (int $no, string $str, string $file, int $line): never {
    throw new ErrorException($str, 0, $no, $file, $line);
});

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$m = mysqli_init();
// Integers as integers, as the indexer reads them.
$m->options(MYSQLI_OPT_INT_AND_FLOAT_NATIVE, true);
[$dbHost, $dbPort] = explode(':', DB_CONFIG['host']);
$m->real_connect($dbHost, DB_CONFIG['user'], DB_CONFIG['pass'], DB_CONFIG['db_name'], (int)$dbPort);

// The XA transactions the server holds PREPARED, as [gtrid, bqual].
$prepared = static function () use ($m): array {
    $out = [];
    foreach ($m->query('XA RECOVER')->fetch_all(MYSQLI_ASSOC) as $r) {
        $out[] = [substr($r['data'], 0, $r['gtrid_length']), substr($r['data'], $r['gtrid_length'], $r['bqual_length'])];
    }
    return $out;
};
$value = static fn(string $param): ?int =>
    $m->query('SELECT `value` FROM `params` WHERE `param` = \'' . $m->real_escape_string($param) . '\'')->fetch_assoc()['value'] ?? null;
$errorLogSize = static function (): int {
    clearstatcache();
    return is_file(WORKLOGS_PATH . '/error_db.log') ? filesize(WORKLOGS_PATH . '/error_db.log') : 0;
};
// The connection a Database runs its transaction on, and that connection killed from another one.
$connectionOf = static fn(Database $db): int => $db->query('SELECT CONNECTION_ID() AS `id`')->fetch_assoc()['id'];
$kill = static function (int $id) use ($m): void {
    $m->query('KILL ' . $id);
    usleep(200000);
};
// Runs $fn, a Throwable it throws shown and answered false.
$quiet = static function (callable $fn): bool {
    try {
        return $fn();
    } catch (Throwable $e) {
        echo '    ' . get_class($e) . ': ' . $e->getMessage() . PHP_EOL;
        return false;
    }
};

section('A write: prepared, then committed');
$db = new Database();
check($db->xaTXStart(['root-a', 'xatest']) === true, 'started');
check($db->query("INSERT INTO `params` (`param`, `value`) VALUES ('xa_a', 1)") === true, 'written');
check($db->xaTXFinalize(skipCommit: true) === true, 'prepared');
check($prepared() === [['root-a', PROJECT_NAME . '-xatest']], 'held PREPARED on the server');
check($value('xa_a') === null, 'not seen before the commit');
check($db->xaTXCommit() === true, 'committed');
check($prepared() === [] && $value('xa_a') === 1, 'seen, and nothing left PREPARED');

section('Nothing written: rolled back, and no COMMIT tried');
$logBefore = $errorLogSize();
check($db->xaTXStart(['root-b', 'xatest']) === true, 'started');
check($db->query('SELECT 1') instanceof mysqli_result, 'read only');
check($db->xaTXFinalize() === true, 'finalized');
check($errorLogSize() === $logBefore, 'no failed query logged (an XA COMMIT of what was rolled back)');
check($prepared() === [], 'nothing PREPARED');
// As indexer.php runs it: prepared without the commit, then committed.
check($db->xaTXStart(['root-b2', 'xatest']) === true && $db->xaTXFinalize(skipCommit: true) === true &&
    $db->xaTXCommit() === true, 'the same in two steps');
check($errorLogSize() === $logBefore && $prepared() === [], '... no failed query logged, nothing PREPARED');

section('The connection lost between the PREPARE and the COMMIT');
$db = new Database();
check($db->xaTXStart(['root-c', 'xatest']) === true, 'started');
$id = $connectionOf($db);
check($db->query("UPDATE `params` SET `value` = 2 WHERE `param` = 'xa_a'") === true, 'written');
check($db->xaTXFinalize(skipCommit: true) === true, 'prepared');
$kill($id);
check($quiet(static fn() => $db->xaTXCommit() === false), 'the COMMIT that failed is answered false');
check($prepared() === [['root-c', PROJECT_NAME . '-xatest']], 'still PREPARED on the server, for checkUncommitedXATX()');
// What the sync went on to while it was answered true: a write behind that transaction's lock.
$m->query('SET SESSION `innodb_lock_wait_timeout` = 1');
try {
    $m->query("UPDATE `params` SET `value` = 3 WHERE `param` = 'xa_a'");
    $waited = false;
} catch (mysqli_sql_exception $e) {
    $waited = $e->getCode() === 1205;
}
check($waited, 'it holds its locks: a write to its row waits them out');
// The same Database starts the next one on a new connection, under the id it is given.
check($quiet(static fn() => $db->xaTXStart(['root-d', 'xatest']) === true), 'the next one starts, under its own id');
check($quiet(static fn() => $db->query("INSERT INTO `params` (`param`, `value`) VALUES ('xa_d', 4)") === true &&
    $db->xaTXFinalize(skipCommit: true) === true && $db->xaTXCommit() === true), '... and commits');
check($value('xa_d') === 4, '... seen');
// As checkUncommitedXATX() settles it on the next run.
$m->query("XA COMMIT 'root-c', '" . PROJECT_NAME . "-xatest'");
check($prepared() === [] && $value('xa_a') === 2, 'committed afterwards, as it was written');

section('The connection lost with nothing written');
$db = new Database();
check($db->xaTXStart(['root-e', 'xatest']) === true, 'started');
$kill($connectionOf($db));
check($quiet(static fn() => $db->xaTXFinalize(skipCommit: true) === true && $db->xaTXCommit() === true),
    'nothing to finalize: answered true, with no error thrown');
check($prepared() === [], 'nothing PREPARED: a transaction not prepared ends with its connection');
check($quiet(static fn() => $db->xaTXStart(['root-f', 'xatest']) === true && $db->xaTXFinalize() === true), 'the next one runs');

done();
