<?php declare(strict_types=1);

use Indexer\Database;
use Indexer\Evm;
use Indexer\RustRocksDBProxy;
use Indexer\SparseMerkleTree;
use Indexer\LockFileUtils;
use Indexer\Protocol;

/**
 * The cached anchored root, or null when there is no usable one.
 *
 * Null means "unknown", and the caller must treat it that way rather than reaching for a
 * fallback: past the TTL this deliberately reports nothing instead of the last value it
 * holds, because an old root presented as the contract's current one is exactly the false
 * reassurance the anchored-root check exists to prevent.
 */
function getAnchoredRoot(string $fileName): ?string
{
    clearstatcache(true, $fileName);
    // A root is 64 hex characters, so anything else is a torn or corrupt file rather than a
    // short value worth using - file_put_contents() truncates in place, so a reader can
    // catch a writer mid-write.
    if (!is_file($fileName) || filesize($fileName) !== 64) {
        return null;
    }

    // Fresh means RECENT. This comparison used to run the other way, which served the value
    // only once it was at least TTL old and threw it away while it was still trustworthy.
    // Because a miss rewrites the file and resets its mtime, under steady traffic it could
    // never age far enough to be served at all - a 0% hit rate exactly under the load the
    // cache was added for.
    if (($_SERVER['REQUEST_TIME'] - filemtime($fileName)) >= ANCHOR_CACHE_TTL_SEC) {
        return null;
    }

    $root = file_get_contents($fileName);

    return ($root !== false && preg_match('/^[0-9a-f]{64}$/', $root) === 1) ? $root : null;
}

/**
 * @var array $uri
 * @var Database $db
 */

if (isset($uri[2])) {
    throw new RuntimeException(API_ERROR_WRONG_API_METHOD, INT_EXC_API_ERROR);
}

$extended = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($raw = file_get_contents('php://input')) !== '') {
        $input = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
    }
    $extended = $input['extended'] ?? false;
}

$out = getParams(
    [
        PARAM_LAST_PROCESSED_COIN_BLOCK
    ],
    $db
);

$qr = $db->doSelect(
    'checkpoints',
    '*',
    [],
    'ORDER BY `idx` DESC LIMIT 1',
);
if ($qr === false) {
    throw new RuntimeException(API_ERROR_INTERNAL, INT_EXC_DB);
}
if ($qr->num_rows !== 0) {
    $out['last_checkpoint'] = $qr->fetch_assoc();
}

// get latest indexer SMT Root
$rocksDb = new RustRocksDBProxy(INDEXER_ROCKSDB_PROXY_SOCKET_PATH);
$smt = new SparseMerkleTree($rocksDb);
$out['indexer_smt_root'] = $smt->getRootHex();

$infoLockFile = __DIR__ . '/../../resources/api_info.lock';
$anchoredRootCacheFile = __DIR__ . '/../../resources/api_anchored_root_cache.txt';
if (($out['anchored_smt_root'] = getAnchoredRoot($anchoredRootCacheFile)) === null) {
    if (LockFileUtils::setLock($infoLockFile)) {
        // Measured against the root the CONTRACT currently attests to, not against the newest
        // local checkpoint. Those two go stale together: if discovery stalls, both freeze and
        // agree, and the node would report itself synced while falling arbitrarily far behind.
        try {
            // Constructed inside the try: the constructor resolves an endpoint from rpc_list and
            // throws when that table holds no row for EVM_NETWORK.chain_id. Outside, that turned a
            // recoverable "chain unreachable" into a failure of the whole endpoint, so a node with
            // a misfiled rpc_list could not even report the state it had computed locally.
            $evm = new Evm(EVM_NETWORK, $db);
            // Read at the same finalised height discovery ingests from. Against the chain tip this
            // would report "not synced" whenever a checkpoint is anchored but not yet final —
            // flapping on a node that is behaving exactly as designed.
            $evm->pinReadBlock();
            $out['anchored_smt_root'] = $evm->getCurrentRoot();
            file_put_contents($anchoredRootCacheFile, $out['anchored_smt_root']);
        } catch (Throwable) {
            // Unreachable chain is unknown, not synced - derived below from the null.
            $out['anchored_smt_root'] = null;
        }
        if (LockFileUtils::isOwnLock($infoLockFile)) {
            LockFileUtils::releaseLock($infoLockFile);
        }
    } else {
        $lockWait = 5;
        while (LockFileUtils::checkLock($infoLockFile) && ($lockWait--) > 0) {
            sleep(1);
        }
        $out['anchored_smt_root'] = getAnchoredRoot($anchoredRootCacheFile);
    }
}

// Derived once, after every branch above, so it is present no matter which one ran. It
// used to be assigned only inside the refresh branch, so a cache hit or a lost lock race
// returned a response with the field missing entirely - and the cache hit is the common
// case once caching works at all.
$out['indexer_synced'] = $out['anchored_smt_root'] !== null
    && $out['indexer_smt_root'] === $out['anchored_smt_root'];

$out['checkpoints_behind'] = isset($out['last_checkpoint']['smt_root'])
    && $out['anchored_smt_root'] !== null
    && $out['last_checkpoint']['smt_root'] !== $out['anchored_smt_root'];

// The anchor epoch this indexer serves under. The sync loop stops when the on-chain
// epoch moves past EVM_NETWORK['expected_epoch'] (a rules change or rollback the operator
// has not reviewed), so a running indexer only ever serves state under this epoch - or under an
// older one while it waits for this one to come into force (see the epoch gate in indexer.php).
// A wallet client pins the epoch it has reviewed and refuses a root served under any other.
$out['anchored_epoch'] = EVM_NETWORK['expected_epoch'];

// Whether the sync is stopped, waiting for its operator (see PARAM_SYNC_STOPPED): an on-chain
// epoch this node has not been updated for, after a rules change or a rollback. Its state stays
// served meanwhile, but it no longer follows the chain.
$out['sync_stopped'] = syncStopped($db);

$qr = $db->doSelect(
    'domains',
    'COUNT(*) AS `count`',
);
if ($qr === false) {
    throw new RuntimeException(API_ERROR_INTERNAL, INT_EXC_DB);
}
if ($qr->num_rows !== 0) {
    $out['domains_count'] = $qr->fetch_assoc()['count'];
}

// The zones a name can be registered in, for a client that talks only to an indexer. The
// registrar serves the same list from getPaymentAddress.
$out['zones'] = Protocol::getZones();

if ($extended) {
    $out['registrar_address'] = INCOME_WALLET;
    $out['registrar_viewing_key'] = INCOME_WALLET_VIEW_KEY;
}

apiAnswer('response', $out);
