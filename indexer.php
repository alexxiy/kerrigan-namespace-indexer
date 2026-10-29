<?php declare(strict_types=1);

use Indexer\Database;
use Indexer\Evm;
use Indexer\EvmRpcException;
use Indexer\Protocol;
use Indexer\RustRocksDBProxy;
use Indexer\SparseMerkleTree;
use Indexer\LockFileUtils;
use Denpa\Bitcoin\Client as RpcClient;
use Denpa\Bitcoin\Exceptions\BadRemoteCallException;
use Denpa\Bitcoin\Responses\BitcoindResponse;
use Telegram\Bot\Api;

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/config.php';
require __DIR__ . '/include/functions.php';

$command = $argv[1] ?? '';
$watchdogLockFile = __DIR__ . '/resources/sync.lock';

try {
    if ($command === 'watchdog') {
        // Read-only: report a stalled lock without ever competing for it. See watchdog.php.
        // Dispatched before the Database and rr-proxy objects are built so the watchdog
        // stays independent of them. Both constructors are lazy today, so this changes
        // nothing at runtime; it keeps the watchdog working if either ever starts
        // connecting eagerly, which is exactly when it would be needed most.
        $watchdogInspectOnly = true;
        include __DIR__ . '/watchdog.php';
        exit;
    }

    // Moved inside the try so that any future eager connection failure is logged and
    // notified like other errors instead of dying uncaught before the handler exists.
    $db = new Database();
    Protocol::init($db);
    $rocksDb = new RustRocksDBProxy(INDEXER_ROCKSDB_PROXY_SOCKET_PATH);
    $smt = new SparseMerkleTree($rocksDb);

    if ($command === 'clean') {
        if (($argv[2] ?? '') !== 'confirm') {
            echo 'Warning!!!' . PHP_EOL .
                'You\'re about to clean all the databases to prepare it for the from-the-scratch synchronization.' . PHP_EOL .
                'Make sure you understand what you\'re doing! Please add "confirm" word after the "clean" command to process.' . PHP_EOL;
            exit;
        }
        resetIndexerState($db, $rocksDb);

        echo 'The database has been successfully cleaned.' . PHP_EOL;
        exit;
    }
    if ($command !== 'sync') {
        echo '--== ' . PROJECT_NAME . ' Domain name Indexer ==--' . PHP_EOL .
            'Supported commands:' . PHP_EOL .
            'sync - start the indexer sync iteration (the way it\'s started from Systemd timer service)' . PHP_EOL .
            'watchdog - report a stalled sync lock (the way it\'s started from Systemd watchdog timer)' . PHP_EOL .
            'clean - perform the database clean for fresh initialization from on-chain data' . PHP_EOL;
        exit;
    }

    // Held by an earlier :EPOCH-STOP: until the operator deploys the reviewed epoch (see
    // PARAM_SYNC_STOPPED). Checked before the lock, so a stopped node holds none.
    if (syncStopped($db, true)) {
        exit;
    }

    // Lock the execution more than one script at one moment
    $watchdogInspectOnly = false;
    include __DIR__ . '/watchdog.php';

    // check if we have at least one RPC_URL added to work with
    if (($qr = $db->doSelect(
        'rpc_list',
        '`rpc_url`',
        ['chain_id' => EVM_NETWORK['chain_id']]
    )) === false) {
        throw new RuntimeException('Can\'t query rpc_list', INT_EXC_DB);
    }
    if ($qr->num_rows === 0) {
        throw new RuntimeException(
            'Can\'t start. Please, add at least one RPC endpoint (rpc_list DB table)',
            INT_EXC_FATAL
        );
    }

    $evm = new Evm(EVM_NETWORK, $db);

    // The newest checkpoint we hold locally. `idx` is the position in the contract's
    // root chain (`rootAt` by index, 0 = genesis), which is what makes discovery resumable.
    if (($qr = $db->doSelect(
        'checkpoints',
        ['idx', 'block_id', 'smt_root'],
        [],
        'ORDER BY `idx` DESC LIMIT 1'
    )) === false) {
        throw new RuntimeException('Can\'t query checkpoints', INT_EXC_DB);
    }
    if ($qr->num_rows === 0) {
        throw new RuntimeException(
            'Genesis SMT root is not found in the database. It seems the initialization with "clean" command hasn\'t been executed properly.',
            INT_EXC_FATAL
        );
    }
    $checkpoint = $qr->fetch_assoc();

    // Check the data consistency in MySQL and RocksDB (if there's any uncommited MySQL transaction).
    // It can happen when commit to RocksDB or to MySQL on the last sync has failed.
    checkUncommitedXATX('indexer', $db, $smt, 'indexer.debug');

    $requestedParams = [PARAM_LAST_PROCESSED_COIN_BLOCK];
    $params = getParams($requestedParams, $db);
    if (count($params) !== count($requestedParams)) {
        throw new RuntimeException(
            'One or several required parameters are absent in "params" table.',
            INT_EXC_FATAL
        );
    }
    $epochParams = getParams([PARAM_STATE_EPOCH, PARAM_EPOCH_WAIT], $db);
    // The epoch the chain last confirmed this state under; null on a node that predates it,
    // until its next run confirms one (see PARAM_STATE_EPOCH).
    $stateEpoch = $epochParams[PARAM_STATE_EPOCH] ?? null;
    // The expected_epoch this node has already reported waiting for (see PARAM_EPOCH_WAIT).
    $epochWait = $epochParams[PARAM_EPOCH_WAIT] ?? null;

    // --- CHECKPOINT DISCOVERY ------------------------------------------------------
    //
    // Checkpoints are read ascending from the anchor contract's root chain (`rootAt` by
    // index). The idx column is the resume cursor, so an interrupted walk continues where it
    // stopped.
    //
    // Best-effort by design: an RPC failure here must not prevent the blockchain sync
    // below from processing the checkpoints already stored. Discovery fills the work
    // queue; the sync consumes it. A short queue means less work this run, never none.
    try {
        // Pin every read below to one finalised height before the walk starts.
        //
        // Reading the chain tip would let a checkpoint that a reorg then discards be
        // written to a table the reconcile path treats as authoritative: the next run sees
        // the stored root disagree with the chain and takes it for a rollback. On BSC a
        // one-block reorg is ordinary, so that turns routine chain behaviour into an outage.
        // Pinning also keeps the length probe and the fetches that follow it on the same
        // view, which reading the tip per call would not.
        $pinned = $evm->pinReadBlock();
        logEvent(
            'Reading checkpoints at EVM block ' . $pinned['block'] . ' (' . $pinned['mode'] . ')',
            'EVM Checkpoints',
            'indexer.debug'
        );

        // Epoch gate — the new contract's client-safety marker.
        //
        // `epoch` advances whenever the anchored state moves by anything other than a proven
        // batch under unchanged rules: a rules change, or a rollback. Either re-points names
        // without a fresh owner signature, so the indexer must pin the epoch it has reviewed
        // and refuse to follow the chain under a newer one until an operator has looked at
        // what changed. EVM_NETWORK['expected_epoch'] is that pin; bump it only after
        // reviewing the change on-chain. Reading it from anchoredState() ties the epoch to the
        // root it guards (same block). A legitimate rollback trips this first, by design: the
        // operator reviews, bumps expected_epoch, and on the next run the reconcile below
        // finds the rolled-back checkpoints and rebuilds the node from genesis.
        //
        // The stop this raises holds until expected_epoch reaches the on-chain epoch (see
        // PARAM_SYNC_STOPPED), so the operator is told once rather than on every run, and
        // nothing more is needed than deploying the reviewed value.
        //
        // Below the reviewed epoch, the change this node was updated for is not in force yet.
        // The epoch never goes down, so that is a change still to come, not one nobody has
        // reviewed: the node waits for it instead of stopping, since a stop is for an on-chain
        // epoch past the reviewed one. Meanwhile it takes no new checkpoints, so the
        // state epoch below is confirmed only under the epoch it was reviewed for, and from the
        // first run that finds the chain at that epoch it carries on by itself. The wait is
        // reported once (see PARAM_EPOCH_WAIT); later runs end discovery quietly, and the
        // checkpoints already stored still sync.
        $anchored = $evm->getAnchoredState();
        if ($anchored['epoch'] < EVM_NETWORK['expected_epoch']) {
            $waitMessage = 'On-chain epoch ' . $anchored['epoch'] . ' is below the reviewed '
                . "EVM_NETWORK['expected_epoch'] = " . EVM_NETWORK['expected_epoch'] . ': the '
                . 'change it was reviewed for is not in force yet. Waiting for it; the sync '
                . 'carries on by itself once the chain reaches epoch '
                . EVM_NETWORK['expected_epoch'] . '.';
            if ($epochWait === EVM_NETWORK['expected_epoch']) {
                throw new RuntimeException($waitMessage);
            }
            setParams([PARAM_EPOCH_WAIT => EVM_NETWORK['expected_epoch']], $db);
            throw new RuntimeException($waitMessage, INT_EXC_FATAL);
        }
        if ($anchored['epoch'] !== EVM_NETWORK['expected_epoch']) {
            throw new RuntimeException(
                'On-chain epoch ' . $anchored['epoch'] . " does not match the reviewed "
                . "EVM_NETWORK['expected_epoch'] = " . EVM_NETWORK['expected_epoch'] . '. A rules '
                . 'change or rollback the operator has not reviewed is in force; refusing to '
                . 'follow the chain until expected_epoch is updated. :EPOCH-STOP:',
                INT_EXC_FATAL
            );
        }
        if ($epochWait !== null && $db->doDelete('params', ['param' => PARAM_EPOCH_WAIT]) === false) {
            throw new RuntimeException('Failed to clear the epoch wait', INT_EXC_DB);
        }

        $chainLength = $evm->getCheckpointCount();
        $localNext = $checkpoint['idx'] + 1;

        // A rollback rewrites history below our high-water mark: the chain gets shorter,
        // or an index we already hold now carries a different root.
        $anchorOk = $chainLength >= $localNext;
        if ($anchorOk) {
            $anchor = $evm->getCheckpointAt($checkpoint['idx']);
            $anchorOk = $anchor !== null && $anchor['root'] === $checkpoint['smt_root'];
        }

        if ($anchorOk && $stateEpoch !== EVM_NETWORK['expected_epoch']) {
            setParams([PARAM_STATE_EPOCH => EVM_NETWORK['expected_epoch']], $db);
        }

        if (!$anchorOk) {
            // A rollback always moves the epoch. The chain confirmed this state under the epoch
            // in force now, so no rollback has happened since: the reads disagree with what the
            // same epoch said before. Not a reason to discard anything - read from another
            // endpoint. The checkpoints already stored stay usable for the sync below.
            if ($stateEpoch === EVM_NETWORK['expected_epoch']) {
                $evm->setRpcUrl(true);
                throw new RuntimeException(
                    'Anchor mismatch at idx ' . $checkpoint['idx'] . ' under epoch ' . $stateEpoch .
                    ', which the chain confirmed this state in, so no rollback explains it; reading from another endpoint next run'
                );
            }

            logEvent(
                'Anchor mismatch at idx ' . $checkpoint['idx'] . ' — the contract rolled back; reconciling',
                'EVM Checkpoints',
                'indexer.debug'
            );

            $keep = -1;
            for ($i = min($checkpoint['idx'], max($chainLength - 1, 0)); $i >= 0; $i--) {
                $onChain = $evm->getCheckpointAt($i);
                if ($onChain === null) {
                    continue;
                }
                if (($qr = $db->doSelect('checkpoints', ['smt_root'], ['idx' => $i])) === false) {
                    throw new RuntimeException('Can\'t query checkpoint for reconcile', INT_EXC_DB);
                }
                $localRoot = $qr->num_rows === 1 ? $qr->fetch_assoc()['smt_root'] : null;
                if ($localRoot !== null && $localRoot === $onChain['root']) {
                    $keep = $i;
                    break;
                }
            }

            // Genesis is immutable: the contract was deployed with it and no rollback can
            // pop index 0. So "no index agrees, not even 0" cannot describe a real chain —
            // it means the reads themselves were unreliable. Deleting on that reading is
            // how an endpoint outage once wiped a node's entire checkpoint table.
            if ($keep < 0) {
                throw new RuntimeException(
                    'Reconcile found no agreeing checkpoint, not even genesis — refusing to '
                    . 'discard anything. The chain reads are unreliable; retrying next run.'
                );
            }

            // Everything above the agreement point describes a state the contract no longer
            // attests to, so it must not stay in a table that resolve() cites, and the domain
            // state built on it is ahead of the chain. The epoch gate above has let this run
            // through, so the operator has reviewed the rollback, and rebuilding is all that is
            // left to do - done here rather than left waiting for `clean confirm`, which
            // independent nodes would otherwise never get. Nothing is lost: it all comes from
            // the chains again, from the next run on.
            resetIndexerState($db, $rocksDb);
            throw new RuntimeException(
                'The anchor contract rolled back to checkpoint idx ' . $keep . ' (epoch ' .
                EVM_NETWORK['expected_epoch'] . '). The node has reset itself and resyncs from genesis.',
                INT_EXC_FATAL
            );
        }

        // Fetch forward, persisting each chunk so an interruption keeps what it got.
        $insData = [];
        for ($idx = $localNext; $idx < $chainLength; $idx++) {
            $cp = $evm->getCheckpointAt($idx);
            if ($cp === null) {
                break;
            }

            $insData[] = [
                'idx' => $idx,
                'block_id' => $cp['block_id'],
                'smt_root' => $cp['root'],
            ];
            $checkpoint['idx'] = $idx;
            $checkpoint['block_id'] = $cp['block_id'];
            $checkpoint['smt_root'] = $cp['root'];

            if (count($insData) >= EVM_BATCH_SIZE || $idx === $chainLength - 1) {
                if ($db->doBulkInsert('checkpoints', $insData) === false) {
                    throw new RuntimeException('Bulk insert to checkpoints', INT_EXC_DB);
                }
                logEvent(
                    count($insData) . ' checkpoint(s) committed, through idx ' . $idx,
                    'EVM Checkpoints',
                    'indexer.debug'
                );
                $insData = [];
            }
        }
    } catch (Throwable $e) {
        // A rollback, an unreviewed epoch change, or a database failure is a real fault and
        // must surface rather than being swallowed as a best-effort discovery miss.
        if (in_array($e->getCode(), [INT_EXC_FATAL, INT_EXC_DB], true)) {
            throw $e;
        }

        // The ONLY place this file rotates a failing endpoint. Every call that can raise
        // EvmRpcException is inside this try, so the top-level handler can never see one —
        // catching it here without marking the endpoint would pin the indexer to a dead
        // RPC run after run, discovery permanently stalled behind a single log line.
        // Anything added later that calls Evm outside this block needs its own handling.
        if ($e instanceof EvmRpcException) {
            $evm->setRpcUrl(true);
        }

        logEvent(
            'Checkpoint discovery stopped early: ' . $e->getMessage()
            . '. Continuing with checkpoints up to idx ' . $checkpoint['idx'],
            'EVM Checkpoints',
            'indexer.debug'
        );
    }

    $rpc = new RpcClient(RPC_CONFIG);

    // check the node is synced, the Registrar wallet exists (viewing key is imported)
    assertNodeIsGood($rpc);

    // syncing blockchain to the latest checkpoint, validating commands, update domains DB and SMT root
    if ($checkpoint['block_id'] > $params[PARAM_LAST_PROCESSED_COIN_BLOCK]) {
        logEvent(
            'There is new checkpoint block to sync to: ' . $checkpoint['block_id'],
            'Blockchain sync',
            'indexer.debug'
        );

        $bcSynced = false;
        $bcLastCheckpointBlockId = $params[PARAM_LAST_PROCESSED_COIN_BLOCK];
        while ($bcSynced === false) {
            if (($qr = $db->doSelect(
                'checkpoints',
                ['block_id', 'smt_root'],
                ['block_id' => ['sign' => '>', 'value' => $bcLastCheckpointBlockId]],
                'ORDER BY `block_id` ASC LIMIT 1'
            )) === false) {
                throw new RuntimeException('Can\'t query next checkpoint for sync', INT_EXC_DB);
            }
            if ($qr->num_rows === 0) {
                $bcSynced = true;
                logEvent(
                    'Synchronization is ended',
                    'Blockchain sync',
                    'indexer.debug'
                );
                continue;
            }
            $smtOldRoot = $smt->getRootHex();
            $lastCheckpoint = $qr->fetch_assoc();

            $newData = [];
            for ($block = ++$bcLastCheckpointBlockId; $block <= $lastCheckpoint['block_id']; $block++) {
                /** @var BitcoindResponse $response */
                $response = $rpc->request('getblockhash', $block);
                $blockHash = $response->get();
                if (strlen($blockHash) !== 64) {
                    throw new RuntimeException('Got wrong \'getblockhash\' response: ' . $blockHash);
                }

                /** @var BitcoindResponse $response */
                $response = $rpc->request('getblock', $blockHash, 2);
                $blockData = $response->get();

                if (!isset($blockData['tx'], $blockData['confirmations']) ||
                    !is_array($blockData['tx']) || $blockData['tx'] === [] ||
                    !is_int($blockData['confirmations']) || $blockData['confirmations'] < 1 ||
                    (($blockData['hash'] ?? '') !== $blockHash)) {
                    throw new RuntimeException(
                        'TX list of the block "' . $block . '" can\'t absent or be empty'
                    );
                }

                foreach ($blockData['tx'] as $oneBlockTx) {
                    // Kerrigan carries Sapling in special transactions (type 10,
                    // TRANSACTION_SAPLING); getblock shows their payload as `saplingTx`.
                    // Only one with shielded outputs can pay a command to INCOME_WALLET.
                    if (($oneBlockTx['saplingTx']['vOutputDescriptions'] ?? []) === []) {
                        continue;
                    }

                    try {
                        /** @var BitcoindResponse $response */
                        $response = $rpc->request('z_viewtransaction', $oneBlockTx['txid']);
                        $saplingTxInfo = $response->get();

                        if (!isset($saplingTxInfo['txid'])) {
                            throw new RuntimeException('Failed to get info of shielded TX: ' . $oneBlockTx['txid']);
                        }
                        // Strict check: Should never happen, just in case
                        if ($saplingTxInfo['txid'] !== $oneBlockTx['txid']) {
                            throw new RuntimeException(
                                'Strange txid "' . $saplingTxInfo['txid'] . '" in shielded tx: ' . $oneBlockTx['txid']
                            );
                        }

                        // Strict check: Should never happen, just in case. z_viewtransaction
                        // always lists `outputs`, empty when it could decrypt none of them.
                        if (!isset($saplingTxInfo['outputs']) || !is_array($saplingTxInfo['outputs'])) {
                            throw new RuntimeException(
                                'TX "' . $oneBlockTx['txid'] . '" have no outputs list',
                                INT_EXC_FATAL
                            );
                        }
                        // Only this wallet's own spends were readable: nothing paid to it
                        if ($saplingTxInfo['outputs'] === []) {
                            continue;
                        }

                        // there can't be more than one command in the same tx: the first output
                        // carrying a well-formed one decides it, exactly as on the registrar
                        // (see Protocol::commandOutput)
                        if (($commandOutput = Protocol::commandOutput($saplingTxInfo['outputs'], $blockData['time'])) === null) {
                            continue;
                        }
                        $txValue = $commandOutput['amount'];

                        // The full check, against the domain state this command meets. A refusal
                        // here does not send us on to the next output - see commandOutput().
                        if (($commandData = Protocol::parseCommand(
                            $commandOutput['memo'],
                            $txValue,
                            $blockData['time']
                        )) === []) {
                            continue;
                        }

                        // ignore TX with wrong coin amount
                        if (Protocol::paymentAmountValid($commandData, $txValue) === false) {
                            Protocol::clearDomainState();
                            continue;
                        }

                        Protocol::commitDomainState($commandData['domain_name']);

                        $commandData['tx_id'] = $oneBlockTx['txid'];
                        $commandData['block_id'] = $block;
                        $commandData['block_time'] = $blockData['time'];
                        $newData[] = $commandData;
                    } catch (BadRemoteCallException $e) {
                        // A Sapling transaction that pays this wallet nothing and spends none
                        // of its notes: every other user's payment. "No such transaction..."
                        // is NOT skipped: with -txindex (see assertNodeIsGood) a transaction
                        // of a block is always found, so that one is an error.
                        if ($e->getCode() === -5 &&
                            $e->getMessage() === 'The transaction has no Sapling spends or outputs this wallet can read') {
                            continue;
                        }
                        throw $e;
                    }
                }
            }

            if ($db->xaTXStart([$smtOldRoot, 'indexer']) === false) {
                throw new RuntimeException('Starting XA-TX', INT_EXC_DB);
            }
            $rocksDb->beginTransaction();

            foreach ($newData as $oneNewData) {
                // debug logging
                if (DEBUG) {
                    $debugLogData = array_map(
                        static fn($k, $v) => "$k: $v",
                        array_keys($oneNewData),
                        $oneNewData
                    );
                    logEvent(
                        implode(', ', $debugLogData),
                        'Received valid domain operation command',
                        'indexer.debug'
                    );
                }

                if ($oneNewData['op'] === Protocol::OP_REG) {
                    if (($qr = $db->doInsert(
                        'domains',
                        [
                            'domain_name' => $oneNewData['domain_name'],
                            'created_block_id' => $oneNewData['block_id'],
                            'updated_block_id' => $oneNewData['block_id'],
                            'created_ts' => $oneNewData['block_time'],
                            'updated_ts' => $oneNewData['block_time'],
                            'nonce' => $oneNewData['nonce'],
                        ],
                        true
                    )) === false) {
                        throw new RuntimeException('Inserting new domain', INT_EXC_DB);
                    }
                    // Strict check: Should never happen, just in case
                    if ($db->affectedRows() === 0) {
                        throw new RuntimeException(
                            'CRITICAL! Inserting existing domain',
                            INT_EXC_FATAL
                        );
                    }
                } else {
                    if (($qr = $db->doUpdate(
                        'domains',
                        [
                            'updated_block_id' => $oneNewData['block_id'],
                            'updated_ts' => $oneNewData['block_time'],
                            'nonce' => $oneNewData['nonce'],
                        ],
                        ['domain_name' => $oneNewData['domain_name']],
                    )) === false) {
                        throw new RuntimeException('Updating domain', INT_EXC_DB);
                    }
                    if ($db->affectedRows() === 0) {
                        throw new RuntimeException(
                            'CRITICAL! The domain "' . $oneNewData['domain_name'] .
                            '" should exist and should be updated!!!',
                            INT_EXC_FATAL
                        );
                    }

                    if ($oneNewData['op'] === Protocol::OP_LST) {
                        // An upsert, not a plain insert: LST on a domain that is already listed
                        // is a re-list at a new price (the web app's "Change price"), which the
                        // protocol and the circuit both accept. The row exists then, and the
                        // INSERT IGNORE this used to be affected nothing - which the check below
                        // took for a fault, stopping the indexer on that checkpoint for good.
                        if (($qr = $db->doInsert(
                            'marketplace',
                            [
                                'domain_name' => $oneNewData['domain_name'],
                                'price' => $oneNewData['price'],
                                'block_id' => $oneNewData['block_id'],
                                'nonce' => $oneNewData['nonce'],
                            ],
                            dup: ['price', 'block_id', 'nonce']
                        )) === false) {
                            throw new RuntimeException(
                                'Inserting ' . $oneNewData['domain_name'] . ' to marketplace',
                                INT_EXC_DB
                            );
                        }
                        // Strict check: Should never happen, just in case. 1 is a new listing and
                        // 2 a re-list; 0 would be a row left exactly as it was, which cannot
                        // happen, because every command raises the nonce.
                        if ($db->affectedRows() === 0) {
                            throw new RuntimeException(
                                'CRITICAL! Listing ' . $oneNewData['domain_name'] . ' changed nothing in marketplace',
                                INT_EXC_FATAL
                            );
                        }
                    } elseif (in_array($oneNewData['op'], [Protocol::OP_ULT, Protocol::OP_BUY], true)) {
                        if (($qr = $db->doDelete(
                            'marketplace',
                            [
                                'domain_name' => $oneNewData['domain_name'],
                            ]
                        )) === false) {
                            throw new RuntimeException(
                                'Deleting ' . $oneNewData['domain_name'] . ' form marketplace',
                                INT_EXC_DB
                            );
                        }
                        // Strict check: Should never happen, just in case
                        if ($db->affectedRows() === 0) {
                            throw new RuntimeException(
                                'CRITICAL! The domain ' . $oneNewData['domain_name'] . ' must be deleted from marketplace',
                                INT_EXC_FATAL
                            );
                        }
                    }
                }

                // common part for any domain update operation: inserting latest and actual domain info
                $oneDomainIns = [
                    'domain_name' => $oneNewData['domain_name'],
                    'domain_block_id' => $oneNewData['block_id'],
                    'domain_ts' => $oneNewData['block_time'],
                    'target_address' => $oneNewData['target_address'],
                    'owner_pubkey' => $oneNewData['pubkey'],
                    'domain_tx' => $oneNewData['tx_id'],
                    'op' => $oneNewData['op'],
                    'nonce' => $oneNewData['nonce'],
                    'price' => $oneNewData['price'],
                ];

                if (($qr = $db->doInsert(
                    'domains_history',
                    $oneDomainIns
                )) === false) {
                    throw new RuntimeException('Inserting new domain history', INT_EXC_DB);
                }

                // --- SMT SEQUENCE STEP B: Move Time Forward ---
                // The daemon derives the leaf hash from these fields and rehashes the
                // spine, so the next transaction gets the mathematically correct state.
                $smt->update(
                    $oneNewData['domain_name'],
                    hex2bin($oneNewData['pubkey']),
                    $oneNewData['target_address'],
                    $oneNewData['price'],
                    $oneNewData['nonce'],
                );

                // debug logging
                logEvent(
                    'Successfully processed!',
                    'Received valid domain operation command',
                    'indexer.debug'
                );
            }

            // update the latest processed block to the latest processed checkpoint
            setParams([PARAM_LAST_PROCESSED_COIN_BLOCK => $lastCheckpoint['block_id']], $db);

            // Verify the SMT root BEFORE anything is committed.
            //
            // rr-proxy serves reads through the open batch overlay (read-your-own-writes),
            // so this sees the pending tree. Checking after the commits would detect a
            // divergence only once the wrong state was already durable and the
            // last-processed marker had advanced past it — leaving the node to resume on
            // top of state it had itself proven wrong.
            if (($lastSMTRoot = $smt->getRootHex()) !== $lastCheckpoint['smt_root']) {
                throw new RuntimeException(
                    'SMT root verification failed. Expected: ' . $lastCheckpoint['smt_root'] . ', got: ' . $lastSMTRoot,
                    INT_EXC_FATAL
                );
            }

            if ($db->xaTXFinalize(skipCommit: true) === false) {
                throw new RuntimeException('Finalizing DB transaction', INT_EXC_DB);
            }

            $rocksDb->commitTransaction();

            // commiting to MySQL only on successfully commit to the RocksDB
            // if xaTXCommit fails there will be inconsistency which we'll check and fix at the start of synchronization
            if ($db->xaTXCommit() === false) {
                throw new RuntimeException('Commiting DB transaction', INT_EXC_DB);
            }

            logEvent('New SMT root validated: ' . $lastSMTRoot, 'SMT Root', 'indexer.debug');

            $bcLastCheckpointBlockId = $lastCheckpoint['block_id'];
        }
    }
} catch (Throwable $e) {
    logEvent(
        $e->getMessage(),
        match ($e->getCode()) {
            INT_EXC_DB => 'DB Error',
            INT_EXC_FATAL => 'FATAL Error',
            default => 'Exception (' . $e->getCode() . ')',
        },
        'indexer.error'
    );

    // Hold the sync where it stopped instead of failing - and alerting - on every run. The
    // marker comes from the checkpoint discovery above, by which point $db exists and $anchored
    // holds the on-chain epoch that raised it.
    $stopNote = '';
    if ($e->getCode() === INT_EXC_FATAL && str_contains($e->getMessage(), ':EPOCH-STOP:')) {
        try {
            setParams([PARAM_SYNC_STOPPED => $anchored['epoch']], $db);
            $stopNote = ' The sync is stopped until expected_epoch is raised to '
                . $anchored['epoch'] . '.';
        } catch (Throwable $stopError) {
            logEvent($stopError->getMessage(), 'Stopping the sync failed', 'indexer.error');
            $stopNote = ' Stopping the sync failed, so this repeats on every run.';
        }
    }

    // send a notification to the Admin
    if ($e->getCode() === INT_EXC_FATAL &&
        NOTIFY_TYPE === 'telegram' && NOTIFY_TG_BOT_KEY !== '' && NOTIFY_TG_USER_ID !== '') {
        try {
            $telegram = new Api(NOTIFY_TG_BOT_KEY);
            $telegram->sendMessage([
                'chat_id' => NOTIFY_TG_USER_ID,
                'text' => PROJECT_NAME . ' Name Indexer error: ' . $e->getMessage() . $stopNote
            ]);
        } catch (Throwable $e) {
            logEvent(
                $e->getMessage(),
                'Telegram sending error',
                'indexer.debug'
            );
        }
    }
}

// Only ever release a lock this run actually owns. The watchdog command reaches this line
// too when it throws on a stalled lock, and it must not unlink a lock file it never took.
if (LockFileUtils::isOwnLock($watchdogLockFile)) {
    LockFileUtils::releaseLock($watchdogLockFile);
}
