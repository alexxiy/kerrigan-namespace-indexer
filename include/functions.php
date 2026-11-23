<?php declare(strict_types=1);

use Indexer\Database;
use Indexer\RustRocksDBProxy;
use Indexer\SparseMerkleTree;
use Denpa\Bitcoin\Client as RpcClient;
use Denpa\Bitcoin\Exceptions\BadRemoteCallException;
use Denpa\Bitcoin\Responses\BitcoindResponse;
use Telegram\Bot\Api;

function logEvent(string $text, string $tag = '', string $file = 'debug'): void
{
    if (DEBUG || str_contains($file, 'debug') === false) {
        file_put_contents(
            WORKLOGS_PATH . '/' . PROJECT_NAME . '-' . $file . '.log',
            date('Y-m-d H:i:s') . ($tag !== '' ? ' - ' . $tag : '') . ': ' . $text . PHP_EOL,
            FILE_APPEND
        );
    }
}

// stdClass for an answer that must read as an object even when it is empty: json_encode() turns
// an empty array into [].
function apiAnswer(string $head, string|array|int|stdClass $data): void
{
    if ($head === 'error') {
        $data = [
            'error_message' => $data
        ];
    }

    $out = [$head => $data];

    try {
        echo json_encode($out, JSON_THROW_ON_ERROR);
    } catch (Exception) {
        apiAnswer('error', API_ERROR_INTERNAL);
    }

    exit;
}

function getParamCount(mixed $input, int $def = 20, int $max = API_MAX_COUNT): int
{
    // An integer, or a string of digits. Nothing else is taken for a count: a cast read "10abc"
    // as 10, 1.9 as 1, true and any array as 1.
    if (is_string($input) && ctype_digit($input)) {
        $input = (int)$input;
    }
    if (!is_int($count = $input ?? 0) || $count < 0) {
        apiAnswer('error', API_ERROR_WRONG_INCOMING_PARAM_VALUE);
    }

    if ($count === 0) {
        $count = $def;
    } elseif ($count > $max) {
        $count = $max;
    }

    return $count;
}

/**
 * A block height from a request (0: none). An integer, or a string of digits, the way a count is
 * taken (getParamCount()): a cast read "12abc" as 12 and any array as 1, and 0 or less as no
 * height given. Nineteen digits and more are refused rather than cut to PHP_INT_MAX.
 */
function getParamBlock(mixed $input): int
{
    if (is_string($input) && ctype_digit($input) && strlen(ltrim($input, '0')) <= 18) {
        $input = (int)$input;
    }
    if (!is_int($input) || $input < 0) {
        apiAnswer('error', API_ERROR_WRONG_INCOMING_PARAM_VALUE);
    }

    return $input;
}

function getParams(array $params, Database $db): array
{
    $qr = $db->doSelect(
        'params',
        '*',
        ['param' => ['sign' => 'IN', 'value' => $params]]
    );
    if ($qr === false) {
        throw new RuntimeException('DB Error: can\'t get parameters from DB.');
    }
    $out = [];
    if ($qr->num_rows !== 0) {
        while ($r = $qr->fetch_assoc()) {
            $out[$r['param']] = $r['value'];
        }
    }
    return $out;
}

function setParams(array $params, Database $db): void
{
    $ins = [];
    foreach ($params as $param => $value) {
        $ins[] = [
            'param' => $param,
            'value' => $value
        ];
    }
    if ($db->doBulkInsert(
        'params',
        $ins,
        false,
        ['value']
    ) === false) {
        throw new RuntimeException('DB Error: can\'t set parameters to DB.');
    }
}

/**
 * Whether the sync is held by a stop (see PARAM_SYNC_STOPPED).
 *
 * The stop no longer holds once EVM_NETWORK['expected_epoch'] reaches the on-chain epoch it was
 * made at: the next run checks the new value against the chain, and stops again if it is still
 * not the on-chain epoch. Until then the epoch, which never goes down, is still past the reviewed
 * one, so holding needs no chain read. With $lift, such a stop is also removed.
 *
 * @param Database $db
 * @param bool $lift
 * @return bool
 * @throws RuntimeException
 */
function syncStopped(Database $db, bool $lift = false): bool
{
    $stop = getParams([PARAM_SYNC_STOPPED], $db)[PARAM_SYNC_STOPPED] ?? null;
    if ($stop === null) {
        return false;
    }
    if ($stop > EVM_NETWORK['expected_epoch']) {
        return true;
    }
    if ($lift && $db->doDelete('params', ['param' => PARAM_SYNC_STOPPED]) === false) {
        throw new RuntimeException('Failed to lift the epoch stop', INT_EXC_DB);
    }

    return false;
}

/**
 * Resets the node for a resync from genesis: empties its tables and its SMT, and seeds the
 * genesis checkpoint. What `indexer.php clean confirm` does, and what the node does by itself
 * after a rollback its operator has reviewed (see the reconcile in indexer.php). Nothing is
 * lost: all of it is derived from the chains again.
 *
 * @param Database $db
 * @param RustRocksDBProxy $rocksDb
 * @return void
 * @throws RuntimeException
 */
function resetIndexerState(Database $db, RustRocksDBProxy $rocksDb): void
{
    foreach (INDEXER_DB_TABLES as $oneTable) {
        if ($db->query('TRUNCATE TABLE `' . $oneTable . '`') === false) {
            throw new RuntimeException('Can\'t clean the table "' . $oneTable . '"', INT_EXC_DB);
        }
    }
    if ($db->doInsert(
        'checkpoints',
        [
            // idx 0 is the contract's genesis root (rootAt[0]) — the empty-registry root
            // it was deployed with. Discovery resumes from the highest idx, so this row is
            // the anchor the whole ascending walk hangs from.
            'idx' => 0,
            'block_id' => COIN_GENESIS_BLOCK,
            'smt_root' => SMT_GENESIS_ROOT,
        ]
    ) === false) {
        throw new RuntimeException('Can\'t insert Genesis Root to checkpoints', INT_EXC_DB);
    }

    setParams([
        PARAM_LAST_PROCESSED_COIN_BLOCK => COIN_GENESIS_BLOCK,
        // Rebuilt from genesis under the epoch configured now: see PARAM_STATE_EPOCH.
        PARAM_STATE_EPOCH => EVM_NETWORK['expected_epoch'],
    ], $db);

    if ($rocksDb->clear() === false) {
        throw new RuntimeException('Can\'t clean RocksDB database', INT_EXC_DB);
    }
}

/**
 * @param string $type
 * @param Database $db
 * @return array
 * @throws RuntimeException
 */
// Do not forget to grant required privileges "GRANT XA_RECOVER_ADMIN on *.* to 'user'@'%';"
function getXATXList(string $type, Database $db): array
{
    if (($qr = $db->query('XA RECOVER')) === false) {
        throw new RuntimeException('Can\'t query XA RECOVER', INT_EXC_DB);
    }

    $type = PROJECT_NAME . '-' . $type;
    $result = [];

    while ($r = $qr->fetch_assoc()) {
        $txName = substr($r['data'], 0, $r['gtrid_length']);
        $txType = substr($r['data'], $r['gtrid_length'], $r['bqual_length']);
        if ($txType === $type || str_starts_with($txType, $type . '_')) {
            $result[] = [$txName, $txType];
        }
    }

    return $result;
}

/**
 * @param string $type
 * @param Database $db
 * @param SparseMerkleTree $smt
 * @param string $logfileName
 * @return bool
 * @throws Indexer\RustRocksDBProxyException
 * @throws RuntimeException
 */
function checkUncommitedXATX(string $type, Database $db, SparseMerkleTree $smt, string $logfileName): bool
{
    if (($xaList = getXATXList($type, $db)) !== []) {
        if (count($xaList) === 1) {
            logEvent(
                'Found uncommited TX (' . implode(',', $xaList[0]) . '), checking...',
                'DB Consistency',
                $logfileName
            );

            $xaOldRoot = $xaList[0][0];
            $xaCurrentRoot = $smt->getRootHex();
            $txCommand = $xaOldRoot === $xaCurrentRoot ? 'ROLLBACK' : 'COMMIT';
            if ($db->query('XA ' . $txCommand . ' \'' . implode('\',\'', $xaList[0]) . '\'', retries: 0) === false) {
                throw new RuntimeException('Can\'t commit XA transaction (consistency check)', INT_EXC_DB);
            }

            logEvent(
                'New data from last uncommited TX has been ' . ($txCommand === 'COMMIT' ? ' commited' : 'reverted'),
                'DB Consistency',
                $logfileName
            );

            return $txCommand === 'COMMIT';
        }

        throw new RuntimeException(
            'PANIC! Should never happen! There more than one uncommited XA transactions: ' .
            formatXATXList($xaList),
            INT_EXC_FATAL
        );
    }
    return false;
}

function formatXATXList(array $xaTXes): string
{
    $parts = [];
    foreach ($xaTXes as $index => $subArray) {
        $formattedValues = "'" . implode("', '", $subArray) . "'";
        $parts[] = "$index: $formattedValues";
    }

    return implode(' / ', $parts);
}

/**
 * @param RpcClient $rpc
 * @return void
 * @throws RuntimeException
 * @throws BadRemoteCallException
 */
function assertNodeIsGood(RpcClient $rpc): void
{
    // check mnsync to prevent false data. IsSynced is AssetID === 999
    // (MASTERNODE_SYNC_FINISHED): the blockchain and every masternode asset are synced.
    /** @var BitcoindResponse $response */
    $response = $rpc->request('mnsync', 'status');
    $mnSync = $response->get();
    if (!isset($mnSync['IsSynced']) || $mnSync['IsSynced'] !== true) {
        throw new RuntimeException('Blockchain node is not synced');
    }
    // END check mnsync

    // z_viewtransaction finds a transaction that pays this wallet nothing only through
    // -txindex (on by default in Kerrigan Core). Without it, "not in the wallet" and "the
    // wallet has not reached that block yet" look the same, and the second would skip a
    // command. So the index is required, and the scan treats a missing transaction as an
    // error rather than as someone else's.
    //
    // result(), not get(): get() hands back the only element of a one-element answer instead
    // of the answer, and this one is always {"txindex": {...}}. The same goes for
    // z_listaddresses below on a node holding just the published key.
    $response = $rpc->request('getindexinfo', 'txindex');
    $indexInfo = $response->result();
    if (!isset($indexInfo['txindex'])) {
        throw new RuntimeException(
            'The Blockchain Node runs without -txindex, which the indexer needs. Stopping...',
            INT_EXC_FATAL
        );
    }
    if ($indexInfo['txindex']['synced'] !== true) {
        throw new RuntimeException('The Blockchain Node\'s transaction index is not synced');
    }

    // let's check that the node has the INCOME_WALLET viewing key imported, otherwise stop.
    // z_importviewingkey lists only the key's default address, so INCOME_WALLET has to be
    // that one: the first address z_getnewaddress gave the registrar's wallet.
    $response = $rpc->request('z_listaddresses');
    $addrList = array_flip(array_column($response->result(), 'address'));
    if (!isset($addrList[INCOME_WALLET])) {
        throw new RuntimeException(
            'The Registrar INCOME_WALLET viewing key is not imported in the Blockchain Node. Stopping...',
            INT_EXC_FATAL
        );
    }
}

/**
 * Why the node does not follow the chain now, or null when it does.
 *
 * Synced once is not synced now. Kerrigan Core keeps `mnsync status` finished for as long as it
 * runs: once synced, CMasternodeSync is never started over (both Reset() calls of
 * UpdatedBlockTip() come after its IsSynced() return). A node that lost its peers says it is
 * synced, and stays where it was. Whether it has peers, and how old its newest block is, tell
 * whether it follows the chain.
 * The age is read against this server's clock, which must therefore be right (NTP).
 *
 * @param RpcClient $rpc
 * @param int $staleSec how old the newest block of a node that follows the chain may be
 * @return string|null
 * @throws RuntimeException (INT_EXC_FATAL) when the node answers something no Kerrigan Core does
 * @throws BadRemoteCallException
 */
function nodeLag(RpcClient $rpc, int $staleSec): ?string
{
    /** @var BitcoindResponse $response */
    $response = $rpc->request('getconnectioncount');
    if (!is_int($peers = $response->get()) || $peers < 0) {
        throw new RuntimeException('Something is wrong with getconnectioncount: ' . json_encode($peers), INT_EXC_FATAL);
    }
    /** @var BitcoindResponse $response */
    $response = $rpc->request('getbestblockhash');
    $bestHash = $response->get();
    /** @var BitcoindResponse $response */
    $response = $rpc->request('getblockheader', $bestHash);
    $header = $response->get();
    if (!is_string($bestHash) || !is_array($header) || ($header['hash'] ?? '') !== $bestHash ||
        !is_int($header['height'] ?? null) || !is_int($header['time'] ?? null)) {
        throw new RuntimeException('Something is wrong with getblockheader of the best block ' . json_encode($bestHash), INT_EXC_FATAL);
    }
    $newest = 'its newest block, #' . $header['height'] . ', is from ' . gmdate('Y-m-d H:i:s', $header['time']) .
        ' UTC, ' . intdiv(max(0, time() - $header['time']), 60) . ' min ago';
    if ($peers === 0) {
        return 'The node has no peers: ' . $newest . '.';
    }

    /** @var BitcoindResponse $response */
    $response = $rpc->request('mnsync', 'status');
    $mnSync = $response->get();
    // IsSynced is AssetID === 999, as assertNodeIsGood() reads it
    if (!is_array($mnSync) || ($mnSync['IsSynced'] ?? null) !== true) {
        return 'The node is still syncing (mnsync AssetID ' . json_encode($mnSync['AssetID'] ?? null) . '): ' . $newest .
            ', with ' . $peers . ' peer(s).';
    }

    if (time() - $header['time'] > $staleSec) {
        return 'The node is behind: ' . $newest . ' (more than ' . intdiv($staleSec, 60) . ' min), with ' . $peers .
            ' peer(s). Is the server\'s clock right?';
    }

    return null;
}

/**
 * The message of an error from sending to Telegram, fit for a log: without the bot token. When
 * Telegram can't be reached, the SDK's message ends with the URL it called, and the token is in
 * it (.../bot<token>/sendMessage).
 *
 * @param string $message
 * @param string $token the bot token to take out
 * @return string
 */
function telegramErrorText(string $message, string $token = NOTIFY_TG_BOT_KEY): string
{
    if ($token === '') {
        return $message;
    }

    return str_replace([$token, rawurlencode($token)], '<bot token>', $message);
}

/**
 * Sends a message to the admin's Telegram, when one is configured (NOTIFY_*). Every message to
 * the admin of the indexer, the registrar and the registrar API goes through here. A failure to
 * send is only logged, without the bot token: no message is worth stopping the caller over.
 *
 * @param string $text
 * @param string $logFile where a failure to send is logged
 * @return void
 */
function sendTelegram(string $text, string $logFile): void
{
    if (NOTIFY_TYPE !== 'telegram' || NOTIFY_TG_BOT_KEY === '' || NOTIFY_TG_USER_ID === '') {
        return;
    }

    try {
        $telegram = new Api(NOTIFY_TG_BOT_KEY);
        $telegram->sendMessage([
            'chat_id' => NOTIFY_TG_USER_ID,
            // Telegram refuses a message over 4096 characters.
            'text' => mb_substr($text, 0, 4000),
        ]);
    } catch (Throwable $e) {
        logEvent(telegramErrorText($e->getMessage()), 'Telegram sending error', $logFile);
    }
}

/**
 * Sends a message to the indexer's admin, when Telegram is configured (NOTIFY_*). A failure to
 * send is only logged: no message is worth stopping the sync over.
 *
 * @param string $text
 * @return void
 */
function notifyIndexerAdmin(string $text): void
{
    sendTelegram(PROJECT_NAME . ' Name Indexer: ' . $text, 'indexer.debug');
}

/**
 * Whether the admin is to be told about a FATAL error of the sync now; if so, it is recorded that
 * they were. The same message is told once, then again every SYNC_STALL_REMIND_SEC while the sync
 * keeps failing with it - it was told on every run, every minute, for an error that lasts until
 * someone acts on it - and another message is told at once. clearFatalTold() forgets it once a
 * run ends without an error. Recorded in params, so it outlives a recreated container.
 *
 * On a connection of its own: the sync's may be inside the XA transaction the error broke off,
 * where nothing else can be written. When the record cannot be read or written, the admin is told,
 * as before.
 *
 * @param string $message the error's message
 * @return bool
 */
function fatalToBeTold(string $message): bool
{
    $db = new Database();
    try {
        $hash = crc32($message);
        $held = getParams([PARAM_FATAL_TOLD_HASH, PARAM_FATAL_TOLD_TS], $db);
        if (($held[PARAM_FATAL_TOLD_HASH] ?? null) === $hash &&
            time() - ($held[PARAM_FATAL_TOLD_TS] ?? 0) < SYNC_STALL_REMIND_SEC) {
            logEvent($message, 'Held alert', 'indexer.debug');
            return false;
        }
        setParams([PARAM_FATAL_TOLD_HASH => $hash, PARAM_FATAL_TOLD_TS => time()], $db);
    } catch (Throwable $e) {
        logEvent($e->getMessage(), 'Holding the alert failed', 'indexer.error');
    } finally {
        $db->free();
    }

    return true;
}

/**
 * Forgets the FATAL error fatalToBeTold() last told about: called by a run that ends without an
 * error, so the next FATAL error is told at once, even with the same message.
 *
 * @param Database $db
 * @return void
 * @throws RuntimeException
 */
function clearFatalTold(Database $db): void
{
    if (getParams([PARAM_FATAL_TOLD_HASH], $db) !== [] && $db->doDelete(
        'params',
        ['param' => ['sign' => 'IN', 'value' => [PARAM_FATAL_TOLD_HASH, PARAM_FATAL_TOLD_TS]]]
    ) === false) {
        throw new RuntimeException('Failed to clear the FATAL error told about', INT_EXC_DB);
    }
}

/**
 * Tells the indexer's admin about a node that does not follow the chain (nodeLag()): once that has
 * lasted INDEXER_NODE_ALERT_SEC, again every SYNC_STALL_REMIND_SEC while it lasts, and once more
 * when the node follows the chain again. Logged on every run meanwhile.
 *
 * Such a node holds the sync back, and never makes it wrong. The sync takes a range of blocks only
 * up to a checkpoint the chain has anchored, and commits it only once it gives that checkpoint's
 * root, so the state served is always one the anchor contract attests to. What a node left behind
 * costs is the next checkpoint: /v1.0/info reports indexer_synced false as soon as the chain
 * anchors one the node does not reach. A node that has no peers while nothing new is anchored
 * misses nothing yet, so the sync and /v1.0/info carry on as they are, and only the admin is told,
 * before it comes to that.
 *
 * Since when it lasts, and when the admin was last told, are held in params like the rest of the
 * sync's state, so they outlive a recreated container.
 *
 * @param string|null $lag why the node does not follow the chain (nodeLag(), and what the sync finds
 *        besides), or null when it does
 * @param Database $db
 * @return void
 * @throws RuntimeException
 */
function watchNodeLag(?string $lag, Database $db): void
{
    $now = time();
    $held = getParams([PARAM_NODE_LAG_SINCE, PARAM_NODE_LAG_TOLD], $db);
    $since = $held[PARAM_NODE_LAG_SINCE] ?? null;
    $told = $held[PARAM_NODE_LAG_TOLD] ?? null;

    if ($lag === null) {
        if ($since === null) {
            return;
        }
        if ($db->doDelete(
            'params',
            ['param' => ['sign' => 'IN', 'value' => [PARAM_NODE_LAG_SINCE, PARAM_NODE_LAG_TOLD]]]
        ) === false) {
            throw new RuntimeException('Failed to clear the node lag', INT_EXC_DB);
        }
        if ($told !== null) {
            $text = 'The Kerrigan node follows the chain again, after ' . intdiv($now - $since, 60) . ' min.';
            logEvent($text, 'Node', 'indexer.debug');
            notifyIndexerAdmin($text);
        }
        return;
    }

    logEvent($lag, 'Node', 'indexer.error');
    $save = [];
    if ($since === null) {
        $since = $save[PARAM_NODE_LAG_SINCE] = $now;
    }
    if ($now - $since >= INDEXER_NODE_ALERT_SEC && ($told === null || $now - $told >= SYNC_STALL_REMIND_SEC)) {
        notifyIndexerAdmin(
            $lag . ' It has lasted ' . intdiv($now - $since, 60) . ' min. The sync goes on with the blocks the node ' .
            'has, and /v1.0/info reports indexer_synced false once the chain anchors a checkpoint the node does not reach.'
        );
        $save[PARAM_NODE_LAG_TOLD] = $now;
    }
    if ($save !== []) {
        setParams($save, $db);
    }
}
