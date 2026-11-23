<?php declare(strict_types=1);

const PROJECT_NAME = 'KNS';

if (!is_file($releaseTypeFN = (__DIR__ . '/release_type'))) {
    echo 'release_type is not found in the root of the project';
    exit;
}

$releaseTypeFN
    |> file_get_contents(...)
    |> trim(...)
    |> (static fn($x) => define('RELEASE_TYPE', $x));

if (!is_file($releaseTypeFN = (__DIR__ . '/config/' . RELEASE_TYPE . '.inc.php'))) {
    echo 'release config is not found in ' . $releaseTypeFN;
    exit;
}
require $releaseTypeFN;
unset($releaseTypeFN);

// --- Coin network parameterisation ----------------------------------------------
//
// COIN_NETWORK selects the Kerrigan network this deployment speaks, and MUST match the
// circuit the prover was built with:
//
//   'mainnet' -> plain `cargo prove build`
//   'testnet' -> `cargo prove build --features testnet`
//
// It is a separate axis from RELEASE_TYPE, which selects a deployment profile: a local
// deployment may legitimately point at either network. Declare it in
// config/<RELEASE_TYPE>.inc.php.
//
// Two things below are derived from it and both are part of the wire protocol - compare
// kns-core's MESSAGE_PREFIX, EXPECTED_HRP and EXPECTED_ADDRESS_LEN. A mismatch means the
// indexer accepts commands the circuit rejects, which stalls every batch.
if (!defined('COIN_NETWORK')) {
    define('COIN_NETWORK', 'mainnet');
}
if (COIN_NETWORK !== 'mainnet' && COIN_NETWORK !== 'testnet') {
    echo 'COIN_NETWORK must be "mainnet" or "testnet", got "' . COIN_NETWORK . '"';
    exit;
}

// Marker at the head of every signed command. Deliberately NOT PROJECT_NAME: that is a
// display name used for log filenames, XA transaction ids and notification text, and must
// stay stable across networks. The wire marker must not - the prefix split is what stops a
// testnet signature being replayed onto mainnet.
const PROTOCOL_MARKER = COIN_NETWORK === 'testnet' ? 't' . PROJECT_NAME : PROJECT_NAME;

if (!defined('WORKLOGS_PATH')) {
    define('WORKLOGS_PATH', dirname(__DIR__, 2) . '/php-worklogs');
}

// The connection api.php uses: DB_CONFIG unless the release config gives one. The API never
// writes, so it can have a user of its own that may only read (GRANT SELECT): a fault in the API
// can then change nothing in the database. See config/release.inc.php.sample.
if (!defined('DB_CONFIG_API')) {
    define('DB_CONFIG_API', DB_CONFIG);
}

// --- EVM reorg safety -------------------------------------------------------------
//
// Both halves must refuse to treat an EVM block as settled until the chain says it is.
// The registrar uses this before crediting balances; the indexer uses it to cap the log
// range it reads, so a checkpoint can never be published from a block that later
// vanishes - a resolve() answer citing a root no third party can find on-chain is worse
// than a late one.
//
// Only consulted when the endpoint does not serve the `finalized` tag. Depth is a
// probabilistic substitute for a real finality signal, so it is deliberately generous.
const EVM_FINALITY_FALLBACK_CONFIRMATIONS = 30;

// How long /v1.0/info may reuse the anchored root it last read from the contract.
//
// This is a ceiling, not just a refresh interval: past it the endpoint reports the root as
// unknown rather than serving the last value it happens to hold. Serving an arbitrarily old
// root as what the contract "currently attests to" is the stale-but-confident failure this
// whole measurement exists to avoid - a node reporting itself synced while sitting well
// behind, because both sides of the comparison were frozen at the same value.
const ANCHOR_CACHE_TTL_SEC = 30;

// The refresh of that root in /v1.0/info. One request reads the contract while it holds the
// lock, each of its two EVM calls limited to ANCHOR_RPC_TIMEOUT_SEC rather than the 30 s the sync
// allows; the others wait up to ANCHOR_LOCK_WAIT_SEC for its answer, checking every 0.1 s, and
// report the root as unknown past it. A slow endpoint then holds a worker for seconds, not a
// minute, and the requests waiting on it cannot fill the PHP-FPM pool for the other endpoints.
const ANCHOR_RPC_TIMEOUT_SEC = 4.0;
const ANCHOR_LOCK_WAIT_SEC = 2;

// Internal Exceptions error codes
const INT_EXC_API_ERROR = -97;
const INT_EXC_DB = -98;
const INT_EXC_FATAL = -99;

// How long a held sync lock may go without progress before it is reported as stalled. The
// lock file is created when a run acquires the lock and unlinked when it releases it, and
// the holder moves the file's mtime forward each time it gets further (see SyncProgress),
// so the mtime is when the run currently holding it last made progress - the start time of
// the run until its first step.
const SYNC_LOCK_STALE_SEC = 600;
// How often a running sync saves its place and refreshes the lock when it has got further.
// Well under SYNC_LOCK_STALE_SEC, so a run that keeps moving is never taken for a stalled one.
const SYNC_PROGRESS_SAVE_SEC = 60;
// How often a running sync logs how far it has got (indexer.debug).
const SYNC_PROGRESS_LOG_SEC = 300;
// A stall is reported once, then again every this many seconds for as long as it lasts.
// So is a node that does not follow the chain (watchNodeLag()), and a FATAL error of the sync that
// repeats (fatalToBeTold()).
const SYNC_STALL_REMIND_SEC = 21600;

// Time limit of one coin node RPC call, used unless RPC_CONFIG sets its own 'timeout'. The
// RPC client's default is no limit at all, so a connection that died mid-answer held the
// run - and the lock - forever. With it the run fails, and the next one carries on from the
// place saved last. Generous, yet well under SYNC_LOCK_STALE_SEC.
const COIN_RPC_TIMEOUT_SEC = 120;

// How old the Kerrigan node's newest block may be while it counts as following the chain
// (nodeLag()). Kerrigan makes a block every two minutes, so twenty minutes (ten blocks) without
// one is a node that is not getting them, not a quiet chain. Read against this server's clock.
const INDEXER_NODE_STALE_SEC = 1200;
// How long the node may go without following the chain - no peers, still syncing, or its newest
// block older than INDEXER_NODE_STALE_SEC - before the admin is told (watchNodeLag()). Long
// enough for a restart of the node, or for peers it lost for a moment.
const INDEXER_NODE_ALERT_SEC = 900;

const INDEXER_DB_TABLES = ['checkpoints', 'domains', 'domains_history', 'params', 'marketplace'];

// params
const PARAM_LAST_PROCESSED_COIN_BLOCK = 'last_processed_coin_block';
// Present while the sync is stopped (see syncStopped()): an on-chain epoch this node has not been
// updated for raises INT_EXC_FATAL marked :EPOCH-STOP:, and is then held here instead of failing
// - and alerting - on every run. Stored as the on-chain epoch that raised it - the lower of two
// endpoints' readings, as one endpoint alone never makes a stop - and lifted as soon as the
// config's expected_epoch reaches it, i.e. once the operator has reviewed that epoch.
// Earlier code stored the expected_epoch instead, so a node updated before its epoch came into
// force stopped for good; read the same way, that stop is not above expected_epoch, so it is
// lifted and the next run checks the chain again.
// In the database rather than a file, so it outlives a recreated container.
const PARAM_SYNC_STOPPED = 'sync_stopped';
// The anchor epoch under which the chain last confirmed this node's state. A rollback always
// moves the epoch, so a checkpoint the chain no longer attests to while this still equals
// expected_epoch cannot be one - the chain reads are wrong. Once the operator has deployed a new
// expected_epoch, it is the rollback they reviewed, and the node rebuilds itself (see the
// reconcile in indexer.php).
const PARAM_STATE_EPOCH = 'state_epoch';
// Present while the node waits for the epoch it was updated for: deployed before that change
// came into force, it finds the on-chain epoch below expected_epoch, and waits instead of
// stopping (see the epoch gate in indexer.php). Stored as the expected_epoch it waits for, so the
// operator is told once rather than on every run, and removed by the first run that finds the
// chain at that epoch.
const PARAM_EPOCH_WAIT = 'epoch_wait';
// Present while the Kerrigan node does not follow the chain (see watchNodeLag()): since when, and
// when the admin was last told about it (absent until then). Removed by the first run that
// finds it following the chain again.
const PARAM_NODE_LAG_SINCE = 'node_lag_since';
const PARAM_NODE_LAG_TOLD = 'node_lag_told';
// Present while a FATAL error the admin was told about may still repeat (see fatalToBeTold()): the
// CRC-32 of its message, and when they were told. A sync run every minute that fails the same way
// tells it again only every SYNC_STALL_REMIND_SEC; another message is told at once. Removed by the
// first run that ends without an error.
const PARAM_FATAL_TOLD_HASH = 'fatal_told_hash';
const PARAM_FATAL_TOLD_TS = 'fatal_told_ts';

// The most items one page of an API list may hold (getParamCount()). Served to clients by the
// registrar's getPaymentAddress, so they need not hardcode it.
const API_MAX_COUNT = 100;

// The most names one reverse or owner lookup answers with, and its default: each name costs a
// Merkle proof from rr-proxy. A longer list is read in pages, each `after` the last name of the
// one before (see api/v1.0/resolve.php).
const API_RESOLVE_MAX_COUNT = 500;

// A resolve whose rows and proofs come from two sides of a sync commit (see api/v1.0/resolve.php)
// is read again, up to this many times in all, this long apart: the tree and MySQL commit within
// milliseconds of each other.
const API_RESOLVE_STATE_ATTEMPTS = 3;
const API_RESOLVE_STATE_RETRY_USEC = 100_000;

// API Errors
const API_ERROR_INTERNAL = 'Internal Error';
const API_ERROR_WRONG_API_METHOD = 'Wrong API method';
const API_ERROR_INVALID_INCOMING_JSON = 'Invalid incoming JSON';
const API_ERROR_WRONG_INCOMING_PARAM_VALUE = 'One or several params provided have wrong value';
const API_ERROR_NOT_FOUND = 'Domain not found';
const API_ERROR_STATE_CHANGING = 'The names are being updated, repeat the request';

// DEBUG (write debug log): set in the release config (see config/release.inc.php.sample). A config
// made before it was there keeps the debug logs, as it always did.
if (!defined('DEBUG')) {
    define('DEBUG', true);
}
