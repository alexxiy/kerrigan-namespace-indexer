<?php declare(strict_types=1);

// The PHP client (RustRocksDBProxy, SparseMerkleTree) against a real rr-proxy, which this test
// starts, restarts and stops itself: RR_PROXY is the binary, RR_CONFIG a config of a throwaway
// database with idle_timeout_secs = 2 and RR_SOCKET its socket (run.sh writes them).
//
// One writer at a time (BUSY), the idle close and the client's reconnect after it, a batch kept
// open across idle time and not silently replaced when its connection is lost, and the tree: the
// same records give the same root in any order, a state verifies only as it was written.

require __DIR__ . '/bootstrap.php';

use Indexer\RustRocksDBProxy;
use Indexer\RustRocksDBProxyException;
use Indexer\SparseMerkleTree;

$daemon = null;
$start = static function () use (&$daemon): void {
    $daemon = startRrProxy();
};
$stop = static function () use (&$daemon): void {
    stopProcess($daemon);
};
$start();

$pk = str_repeat("\x07", 32);
$pk2 = str_repeat("\x09", 32);
$addr = address(0);
$a = new RustRocksDBProxy(getenv('RR_SOCKET'));
$b = new RustRocksDBProxy(getenv('RR_SOCKET'));

try {
    section('idle close and reconnect');
    check($a->ping(), 'ping');
    $empty = $a->smtRoot();
    sleep(3); // past idle_timeout_secs: the daemon closed a's connection
    check($a->ping(), 'the next call after an idle close reconnects');
    $root1 = $a->smtUpdate('a.krgn', $pk, $addr, 0, 1);
    check(strlen($root1) === 32 && $root1 !== $empty, 'an update outside a batch after the reconnect');

    section('one writer at a time');
    $a->beginTransaction();
    $a->smtUpdate('b.krgn', $pk, $addr, 0, 1);
    sleep(3);
    check($a->ping(), 'a batch keeps its connection across idle time');
    try {
        $b->smtUpdate('c.krgn', $pk, $addr, 0, 1);
        check(false, 'a second writer is refused (BUSY)');
    } catch (RustRocksDBProxyException $e) {
        check(str_contains($e->getMessage(), '(BUSY)'), 'a second writer is refused (BUSY)');
    }
    try {
        $b->beginTransaction();
        check(false, 'a second batch is refused (BUSY)');
    } catch (RustRocksDBProxyException $e) {
        check(str_contains($e->getMessage(), '(BUSY)'), 'a second batch is refused (BUSY)');
    }
    check($b->smtRoot() === $root1, 'reads are not refused, and do not see the open batch');
    check($b->clear() === false, 'clear is refused while another connection writes');
    $a->commitTransaction();
    $root2 = $b->smtUpdate('c.krgn', $pk, $addr, 0, 1);
    check(strlen($root2) === 32 && $root2 !== $root1, 'after the commit the other connection writes');
    check($a->smtLeaf('b.krgn') !== null && $a->smtRoot() === $root2, 'and the first sees all of it');

    section('the tree');
    $smt = new SparseMerkleTree($a);
    check($smt->verifyDomainState('c.krgn', $pk, $addr, 0, 1), 'a state verifies as written');
    check(!$smt->verifyDomainState('c.krgn', $pk2, $addr, 0, 1) && !$smt->verifyDomainState('c.krgn', $pk, $addr, 1, 1) &&
        !$smt->verifyDomainState('c.krgn', $pk, $addr, 0, 2) && !$smt->verifyDomainState('c.krgn', $pk, address(1), 0, 1),
        'and not with any one field changed');
    check(!$smt->verifyDomainState('zz.krgn', $pk, $addr, 0, 1) && $smt->getLeaf('zz.krgn') === null,
        'a name never written: no leaf');
    $proof = $a->smtProof('c.krgn');
    check($proof['root'] === $root2 && $proof['proof']['depth'] === count($proof['proof']['siblings']),
        'a proof comes with the root it was built on, one sibling per level');
    $names = [];
    for ($i = 0; $i < 40; $i++) {
        $names[] = 'n' . $i . '.krgn';
    }
    $build = static function (array $order) use ($a, $pk, $addr): string {
        check($a->clear(), 'cleared');
        $a->beginTransaction();
        foreach ($order as $i => $n) {
            $a->smtUpdate($n, $pk, $addr, 0, 1);
        }
        $a->commitTransaction();
        return $a->smtRoot();
    };
    $forward = $build($names);
    $backward = $build(array_reverse($names));
    check($forward === $backward && $forward !== $empty, '40 names: the same root in either order');
    $a->smtUpdate('n0.krgn', $pk2, $addr, 0, 2);
    check($a->smtRoot() !== $forward, 'one change moves the root');
    $a->smtUpdate('n0.krgn', $pk, $addr, 0, 1);
    check($a->smtRoot() === $forward, 'and changing it back brings the root back');

    section('a batch whose connection is lost is not silently replaced');
    $a->beginTransaction();
    $a->smtUpdate('lost.krgn', $pk, $addr, 0, 1);
    $stop();
    $start();
    try {
        $a->commitTransaction();
        check(false, 'its commit fails');
    } catch (RustRocksDBProxyException $e) {
        check(true, 'its commit fails: ' . $e->getMessage());
    }
    check($a->ping() && $a->smtLeaf('lost.krgn') === null, 'then a fresh connection works, without that update');
    check($a->clear() && $a->smtRoot() === $empty, 'clear empties the tree');
} finally {
    $a->disconnect();
    $b->disconnect();
    $stop();
}

done();
