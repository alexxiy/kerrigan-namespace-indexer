<?php declare(strict_types=1);

namespace Indexer;

use Throwable;
use Exception;
use Web3\Providers\HttpProvider;
use Web3\Contract;
use Web3\Contracts\Ethabi;
use Web3\Eth;
use Web3\Utils;
use GuzzleHttp\Client;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use InvalidArgumentException;
use Indexer\Interfaces\Web3EthMethods;
use GuzzleHttp\Exception\TransferException;

class Evm
{
    protected Database $db;
    // chain info
    protected int $chainId;
    protected string $contractAddress;
    // RPC
    protected HttpProvider $httpProvider;
    protected float $rpcTimeout = 30.0;
    // contract
    protected Contract $contract;
    /**
     * @var Eth&Web3EthMethods
     */
    protected Eth $eth;
    protected Ethabi $ethAbi;
    protected array $events;
    /**
     * Block that every contract read is answered from, as a hex quantity, or null to let
     * the node answer from `latest`.
     *
     * Pinned to one concrete height rather than passing the `finalized` tag on each call,
     * for two reasons. Reading `latest` lets a checkpoint that a reorg later discards be
     * persisted, and the reconcile path then treats that as a rollback: once the epoch has
     * moved it rebuilds the node from genesis, turning an ordinary one-block BSC reorg into
     * an hours-long resync. And a run that resolved the tag per call
     * would let the length probe and the fetches that follow it straddle different chain
     * tips, so the walk could observe a length it can no longer read to.
     */
    protected ?string $readBlock = null;
    /**
     * What pinReadBlock() settled on, so it can report the same answer twice.
     *
     * @var array{block: int, mode: string}|null
     */
    protected ?array $pinnedAt = null;


    /**
     * @param array $networkInfo
     * @param Database $db
     * @param float|null $rpcTimeout seconds one EVM call may take; null keeps the default
     * @throws RuntimeException
     */
    public function __construct(array $networkInfo, Database $db, ?float $rpcTimeout = null)
    {
        $this->db = $db;
        if ($rpcTimeout !== null) {
            $this->rpcTimeout = $rpcTimeout;
        }

        $this->chainId = $networkInfo['chain_id'];
        $this->contractAddress = $networkInfo['contract_address'];

        $this->setRpcUrl();

        $this->contract = new Contract($this->httpProvider, file_get_contents($networkInfo['abi']));
        $this->events = $this->contract->getEvents();
        $this->eth = $this->contract->getEth();
        $this->ethAbi = $this->contract->getEthabi();
    }

    /**
     * @return string
     * @throws RuntimeException
     */
    protected function getRpcUrl(): string
    {
        $qr = $this->db->doSelect(
            'rpc_list',
            '`rpc_url`',
            ['chain_id' => $this->chainId],
            'ORDER BY `issue_ts` ASC LIMIT 1'
        );
        if ($qr === false || $qr->num_rows !== 1) {
            throw new RuntimeException('DB Error: Unable to retrieve rpc URL', INT_EXC_DB);
        }

        return $this->db->fetchAssoc($qr)['rpc_url'];
    }

    /**
     * @param bool $upd
     * @return void
     */
    public function setRpcUrl(bool $upd = false): void
    {
        if (!isset($this->httpProvider)) {
            $this->httpProvider = new HttpProvider($this->getRpcUrl(), $this->rpcTimeout);
            return;
        }

        if ($upd) {
            $qr = $this->db->doUpdate(
                'rpc_list',
                ['issue_ts' => time()],
                [
                    'rpc_url' => $this->httpProvider->getHost(),
                    'chain_id' => $this->chainId
                ]
            );
            if ($qr === false) {
                throw new RuntimeException('DB Error: Unable to update rpc URL issue_ts', INT_EXC_DB);
            }

            $httpProviderRef = new ReflectionClass($this->httpProvider);
            $httpProviderRef->getProperty('host')->setValue($this->httpProvider, $this->getRpcUrl());
            return;
        }

        throw new RuntimeException('Error: httpProvider exists, but method called without upd');
    }

    /**
     * Inspects errors and classifies them as network/availability issues
     * (EvmRpcException) vs logic/parameter issues (passed through).
     *
     * @param mixed $error
     * @throws EvmRpcException
     * @throws Throwable
     */
    protected function handleRpcError(mixed $error): void
    {
        if ($error === null) {
            return;
        }

        $isRpcUnavailable = false;

        if ($error instanceof Throwable) {
            $message = $error->getMessage();
            
            // Catch Guzzle-level connection/timeout/transport exceptions
            if ($error instanceof TransferException) {
                $isRpcUnavailable = true;
            }
            
            // Check previous exception in case it is wrapped
            $prev = $error->getPrevious();
            if ($prev instanceof TransferException) {
                $isRpcUnavailable = true;
            }
        } else {
            $message = (string)$error;
        }

        // Heuristic patterns for nodes/infrastructure failing
        $lowerMsg = strtolower($message);
        $unavailabilityPatterns = [
            'curl error',
            'connection refused',
            'timed out',
            'timeout',
            'cannot resolve',
            'could not resolve',
            'host unreachable',
            'bad gateway',
            'service unavailable',
            'gateway timeout',
            'too many requests',
            'rate limit',
            'status code 429',
            'status code 502',
            'status code 503',
            'status code 504',
            'cloudflare',
            'limit exceeded',
        ];

        foreach ($unavailabilityPatterns as $pattern) {
            if (str_contains($lowerMsg, $pattern)) {
                $isRpcUnavailable = true;
                break;
            }
        }

        if ($isRpcUnavailable) {
            throw new EvmRpcException($message, 0, $error instanceof Throwable ? $error : null);
        }

        if ($error instanceof Throwable) {
            throw $error;
        }
        throw new RuntimeException($message);
    }

    /**
     * Height of the most recent block the chain considers FINAL, or null when the
     * endpoint does not support the tag.
     *
     * BSC finality is provable rather than probabilistic: under Parlia with fast
     * finality a finalized block cannot be reverted without slashing a supermajority
     * of validators. So this is a real guarantee, not a confidence heuristic - which
     * is why anchoring waits on this rather than counting confirmations.
     *
     * Returns null rather than throwing when `finalized` is unsupported: the RPC list
     * is third-party and rotates, so callers fall back to a confirmation depth instead
     * of stalling the pipeline on one endpoint's capabilities.
     *
     * @throws Throwable
     */
    public function getFinalizedBlockNumber(): ?int
    {
        $result = null;
        try {
            $this->eth->getBlockByNumber('finalized', false, function ($err, $data) use (&$result) {
                // Not handleRpcError(): an endpoint that does not know the tag is a
                // capability gap, not a fault worth rotating the endpoint over.
                if ($err !== null || $data === null || !isset($data->number)) {
                    return;
                }
                $result = (int)hexdec(ltrim((string)$data->number, "\x00"));
            });
        } catch (Throwable) {
            return null;
        }

        return $result;
    }

    /**
     * @return int
     * @throws Throwable
     */
    public function getBlockCount(): int
    {
        $result = null;
        try {
            $this->eth->blockNumber(function ($err, $data) use (&$result) {
                if ($err !== null) {
                    $this->handleRpcError($err);
                }
                $result = (int)$data->toString();
            });
        } catch (Throwable $e) {
            // eth_blockNumber takes no input, so nothing about the call itself can be wrong: any
            // failure is the endpoint's. Passing an unrecognised one through left the endpoint
            // selected, so the next run asked the same failing node again.
            throw $e instanceof EvmRpcException ? $e : new EvmRpcException('eth_blockNumber: ' . $e->getMessage(), 0, $e);
        }
        if ($result === null) {
            throw new EvmRpcException('eth_blockNumber returned no value');
        }

        return $result;
    }

    /**
     * Reads one `view` function on the anchor contract, with throttling applied.
     *
     * All checkpoint discovery goes through here.
     *
     * @param array<int, mixed> $args
     * @return array<int, mixed> raw decoded return values
     * @throws Throwable
     */
    public function callContract(string $function, array $args = []): array
    {
        $out = null;
        $handler = static function ($err, $result) use (&$out, $function) {
            if ($err !== null) {
                if ($err instanceof Throwable) {
                    throw $err;
                }
                throw new RuntimeException('eth_call ' . $function . ': ' . $err);
            }
            $out = $result;
        };

        // Contract::call() pops the callback first and slices off the function's declared
        // inputs; a single remaining non-array argument is then taken as the block to read
        // at. So appending it here needs no change in the library.
        $tail = $this->readBlock === null ? [$handler] : [$this->readBlock, $handler];

        try {
            $this->contract->at($this->contractAddress)->call($function, ...[...$args, ...$tail]);
        } catch (Throwable $e) {
            // Every view this is used for is a plain getter that cannot revert, so a failure is
            // the endpoint's, whatever its wording: a pruned node's "missing trie node" on a
            // finalised read, say, which no pattern in handleRpcError() knows. Raised as an RPC
            // fault, it moves the reader to another endpoint instead of retrying this one.
            if ($e instanceof EvmRpcException) {
                throw $e;
            }
            // The handler above already names the call in what it throws.
            $message = str_starts_with($e->getMessage(), 'eth_call ')
                ? $e->getMessage()
                : 'eth_call ' . $function . ': ' . $e->getMessage();
            throw new EvmRpcException($message, 0, $e);
        }

        // Paced rather than parallel: the endpoint limit is per-second concurrency, not
        // total volume.
        if (EVM_THROTTLING > 0) {
            usleep(EVM_THROTTLING);
        }

        return $out ?? [];
    }

    /**
     * Pins every subsequent contract read in this run to the newest finalised block, and
     * reports which rule produced it: 'finalized' when the endpoint serves the tag, 'depth'
     * when it does not and a confirmation depth stood in.
     *
     * Those two are not equivalent — depth is probabilistic where the tag is a consensus
     * guarantee — so the caller is expected to log the mode rather than let "final" quietly
     * mean two different things. This mirrors what the registrar already does before it
     * treats an anchor transaction as settled.
     *
     * Idempotent: the first call fixes the height for the lifetime of the instance.
     *
     * @return array{block: int, mode: string}
     * @throws Throwable
     */
    public function pinReadBlock(): array
    {
        // Return what is actually pinned. Re-resolving would report a height the reads are
        // not using, which is worse than not reporting at all.
        if ($this->pinnedAt !== null) {
            return $this->pinnedAt;
        }

        $mode = 'finalized';
        $block = $this->getFinalizedBlockNumber();

        if ($block === null) {
            $mode = 'depth';
            $block = $this->getBlockCount() - EVM_FINALITY_FALLBACK_CONFIRMATIONS;
        }

        // A chain younger than the fallback depth would otherwise ask for a negative
        // height; genesis is the honest floor and simply yields a chain holding only its
        // genesis checkpoint.
        $block = max($block, 0);

        $this->readBlock = '0x' . dechex($block);

        return $this->pinnedAt = ['block' => $block, 'mode' => $mode];
    }

    /**
     * Normalises a bytes32 the RPC returned into the 64-char lowercase hex this codebase
     * stores.
     *
     * web3.php is inconsistent here and it matters: a non-zero root arrives as the
     * 66-character "0x…" string, but the all-zero genesis root arrives as the single
     * character "0". Storing that verbatim would make the genesis checkpoint compare
     * unequal to the empty-tree root the SMT reports, and the very first verification
     * would fail.
     */
    private static function normaliseRoot(mixed $value): string
    {
        $hex = strtolower((string)$value);
        if (str_starts_with($hex, '0x')) {
            $hex = substr($hex, 2);
        }
        return str_pad($hex, 64, '0', STR_PAD_LEFT);
    }

    /**
     * The root the contract currently attests to.
     *
     * This is what "synced" is measured against — not the newest local checkpoint row,
     * which goes stale in step with the computed root and would agree with it even when
     * the node is far behind.
     *
     * @throws Throwable
     */
    public function getCurrentRoot(): string
    {
        // An empty answer is an RPC fault, not the genesis root: normalising '' gave 64 zeros,
        // which info.php then cached as the contract's root.
        if (($root = $this->callContract('currentRoot')[0] ?? null) === null) {
            throw new EvmRpcException('eth_call currentRoot returned no value');
        }
        return self::normaliseRoot($root);
    }

    /**
     * Number of checkpoints the contract holds: the genesis root at index 0 up to the current
     * tip, i.e. `tipIndex() + 1`.
     *
     * The new contract exposes the tip directly, so this is a single read. The old layout
     * (a `rootChain` array whose auto-generated getter has no length) forced a revert-probing
     * doubling+binary search; that cannot work against the new `rootAt`, which is a mapping —
     * an out-of-range read returns bytes32(0) instead of reverting, so the probe would never
     * find the end. One `tipIndex` call replaces ~2*log2(n) calls per sync run.
     *
     * @throws Throwable
     */
    public function getCheckpointCount(): int
    {
        $tip = $this->callContract('tipIndex')[0] ?? null;
        if ($tip === null) {
            // Not a revert — the mapping/accessor never reverts — so an empty result is an
            // RPC fault, which must surface (and be retried) rather than read as length 0.
            throw new EvmRpcException('eth_call tipIndex returned no value');
        }

        return (int)$tip->toString() + 1;
    }

    /**
     * The checkpoint at `$index`: its root and the Kerrigan height it covers, or null when the
     * index is not part of the chain — past the tip, or a root a rollback has disavowed.
     *
     * `rootAt($index)` gives the root; `verifyRootValidity($root)` gives validity and height
     * in one call. A rolled-back root reads back invalid, so the caller sees it drop off the
     * chain exactly as the old `rootHistory.isValid` conveyed it. Neither call can revert
     * (mapping + plain view), so there is no revert branch to interpret any more.
     *
     * Phantom-genesis guard: because `rootAt` is a mapping, an index past the tip returns
     * bytes32(0), and `verifyRootValidity(bytes32(0))` reports a *valid* genesis — so an
     * out-of-range read would masquerade as a genesis checkpoint. But `commitBatch` rejects
     * bytes32(0) as a committed root, so the empty root exists on-chain only as genesis at
     * index 0. Treating a zero root at any other index as "not on the chain" closes the trap
     * with no extra call. Callers additionally bound iteration by getCheckpointCount().
     *
     * @return array{root: string, block_id: int}|null
     * @throws Throwable
     */
    public function getCheckpointAt(int $index): ?array
    {
        $raw = $this->callContract('rootAt', [$index])[0] ?? null;
        if ($raw === null) {
            // A mapping read always answers, bytes32(0) past the tip, so no answer at all is the
            // endpoint failing. Read as "not on the chain", it failed the anchor check below the
            // high-water mark.
            throw new EvmRpcException('eth_call rootAt returned no value');
        }
        if (self::isUnwrittenSlot($raw, $index)) {
            return null;
        }

        return self::checkpointOf($raw, $this->callContract('verifyRootValidity', [$raw]));
    }

    /**
     * The checkpoints from `$from` on, at most `$count` of them, each as getCheckpointAt() gives
     * it, in order and up to the first index that is not on the chain.
     *
     * Read in two JSON-RPC batch requests, every rootAt and then every verifyRootValidity,
     * instead of two calls per index: a sync from genesis walks the whole chain this way. An
     * endpoint that does not answer a batch as asked is read one index at a time, as before, so
     * batching never makes discovery fail where it would not have.
     *
     * @return list<array{root: string, block_id: int}>
     * @throws Throwable
     */
    public function getCheckpointsFrom(int $from, int $count): array
    {
        $indexes = $count > 0 ? range($from, $from + $count - 1) : [];
        try {
            $raws = [];
            foreach ($this->callContractBatch('rootAt', array_map(static fn(int $i) => [$i], $indexes)) as $k => $out) {
                // See getCheckpointAt().
                if (($raw = $out[0] ?? null) === null) {
                    throw new EvmRpcException('eth_call rootAt returned no value');
                }
                if (self::isUnwrittenSlot($raw, $indexes[$k])) {
                    break;
                }
                $raws[] = $raw;
            }

            $checkpoints = [];
            $infos = $this->callContractBatch('verifyRootValidity', array_map(static fn($raw) => [$raw], $raws));
            foreach ($infos as $k => $info) {
                if (($cp = self::checkpointOf($raws[$k], $info)) === null) {
                    break;
                }
                $checkpoints[] = $cp;
            }

            return $checkpoints;
        } catch (EvmRpcException $e) {
            logEvent(
                'Batch read of checkpoints from idx ' . $from . ' failed (' . $e->getMessage() . '), reading them one at a time',
                'EVM Checkpoints',
                'indexer.debug'
            );
        }

        $checkpoints = [];
        foreach ($indexes as $index) {
            if (($cp = $this->getCheckpointAt($index)) === null) {
                break;
            }
            $checkpoints[] = $cp;
        }

        return $checkpoints;
    }

    /**
     * Whether rootAt($index) answered an unwritten slot.
     *
     * bytes32(0) is genesis, and genesis lives only at index 0. Anywhere else it is an
     * unwritten slot the chain shrank past under a rollback, never a real checkpoint.
     * SMT_GENESIS_ROOT is the empty-tree root the contract was deployed with and the same
     * value indexer.php seeds idx 0 with, so the guard can never drift from the seed.
     */
    private static function isUnwrittenSlot(mixed $raw, int $index): bool
    {
        return self::normaliseRoot($raw) === SMT_GENESIS_ROOT && $index !== 0;
    }

    /**
     * The checkpoint a verifyRootValidity($raw) answer describes, or null when the root is not
     * part of the chain.
     *
     * @param mixed $raw the root as rootAt returned it
     * @param array<int|string, mixed> $info the decoded verifyRootValidity answer
     * @return array{root: string, block_id: int}|null
     * @throws EvmRpcException
     */
    private static function checkpointOf(mixed $raw, array $info): ?array
    {
        if ($info === []) {
            // A view that cannot revert answered nothing: an RPC fault, not a "no".
            throw new EvmRpcException('eth_call verifyRootValidity returned no value');
        }

        // isValid false means a rollback disavowed this root (or it was never on the chain),
        // so it is not part of the checkpoint chain. A valid entry always carries its height.
        $isValid = $info['isValid'] ?? ($info[0] ?? false);
        if ($isValid !== true) {
            return null;
        }

        $blockHeight = $info['blockHeight'] ?? ($info[1] ?? null);
        if ($blockHeight === null) {
            // Height 0 would be stored as the checkpoint's block and the sync would replay to it.
            throw new EvmRpcException('eth_call verifyRootValidity returned no height for a valid root');
        }

        return [
            'root' => self::normaliseRoot($raw),
            'block_id' => (int)$blockHeight->toString(),
        ];
    }

    /**
     * One `view` function of the anchor contract for each argument list in `$argsList`, in a
     * single JSON-RPC batch request at the pinned block, with the throttling applied once.
     *
     * Not web3.php's own batch mode: it matches the answers to the requests by position, while a
     * server may answer a batch in any order, and turns an entry's error into a null result.
     * Here the answers are matched by id, and every one must be a result: these views cannot
     * revert, so an error, a missing answer or anything that is not a batch answer is the
     * endpoint's fault.
     *
     * @param list<array<int, mixed>> $argsList
     * @return list<array<int|string, mixed>> raw decoded return values, in the order of $argsList
     * @throws EvmRpcException
     */
    private function callContractBatch(string $function, array $argsList): array
    {
        if ($argsList === []) {
            return [];
        }
        $abi = null;
        foreach ($this->contract->getFunctions() as $candidate) {
            if (($candidate['name'] ?? null) === $function) {
                $abi = $candidate;
                break;
            }
        }
        if ($abi === null) {
            throw new InvalidArgumentException('The anchor ABI has no function ' . $function);
        }

        $signature = $this->ethAbi->encodeFunctionSignature(Utils::jsonMethodToString($abi));
        $requests = [];
        foreach (array_values($argsList) as $id => $args) {
            $requests[] = [
                'jsonrpc' => '2.0',
                'id' => $id,
                'method' => 'eth_call',
                'params' => [
                    [
                        'to' => $this->contractAddress,
                        'data' => $signature . Utils::stripZero($this->ethAbi->encodeParameters($abi, $args)),
                    ],
                    $this->readBlock ?? 'latest',
                ],
            ];
        }

        try {
            $response = (new Client())->post($this->httpProvider->getHost(), [
                'headers' => ['content-type' => 'application/json'],
                'body' => json_encode($requests, JSON_THROW_ON_ERROR),
                'timeout' => $this->rpcTimeout,
                'connect_timeout' => $this->rpcTimeout,
            ]);
            $answers = json_decode((string)$response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new EvmRpcException('eth_call ' . $function . ' batch: ' . $e->getMessage(), 0, $e);
        }
        if (!is_array($answers) || !array_is_list($answers)) {
            throw new EvmRpcException('eth_call ' . $function . ' batch: the answer is not a batch');
        }

        $results = [];
        foreach ($answers as $answer) {
            if (is_array($answer) && is_int($answer['id'] ?? null) && is_string($answer['result'] ?? null)) {
                $results[$answer['id']] = $answer['result'];
            }
        }
        $out = [];
        foreach (array_keys($requests) as $id) {
            // Every output of these views is one or more 32-byte words.
            if (preg_match('/^0x(?:[0-9a-fA-F]{64})+$/D', $results[$id] ?? '') !== 1) {
                throw new EvmRpcException('eth_call ' . $function . ' batch: no result for entry ' . $id);
            }
            try {
                $out[] = $this->ethAbi->decodeParameters($abi, $results[$id]);
            } catch (Throwable $e) {
                throw new EvmRpcException('eth_call ' . $function . ' batch: entry ' . $id . ': ' . $e->getMessage(), 0, $e);
            }
        }

        // One request, so paced as one: see callContract().
        if (EVM_THROTTLING > 0) {
            usleep(EVM_THROTTLING);
        }

        return $out;
    }

    /**
     * The anchored state in one read: current root, the Kerrigan height it covers, and the
     * `epoch` it belongs to.
     *
     * `epoch` is the contract's client-safety marker: it advances on any change to the
     * anchored state other than a proven batch under unchanged rules — a rules change or a
     * rollback, both of which re-point names without a fresh owner signature. Reading all
     * three with one `anchoredState()` call guarantees the epoch and the root it guards come
     * from the same block. The indexer's epoch gate pins the reviewed epoch against this.
     *
     * @return array{root: string, block_id: int, epoch: int}
     * @throws Throwable
     */
    public function getAnchoredState(): array
    {
        $state = $this->callContract('anchoredState');

        $root = $state['root'] ?? ($state[0] ?? null);
        $blockHeight = $state['blockHeight'] ?? ($state[1] ?? null);
        $epoch = $state['currentEpoch'] ?? ($state[2] ?? null);

        // The view cannot revert, so a missing field is an RPC fault. Defaulting it read an empty
        // answer as epoch 0, and the epoch gate then reported the reviewed change as not in
        // force yet - a FATAL alert, and the epoch wait set - with the same endpoint kept.
        if ($root === null || $blockHeight === null || $epoch === null) {
            throw new EvmRpcException('eth_call anchoredState returned no value');
        }

        return [
            'root' => self::normaliseRoot($root),
            'block_id' => self::uintToInt($blockHeight, 'anchoredState blockHeight'),
            'epoch' => self::uintToInt($epoch, 'anchoredState currentEpoch'),
        ];
    }

    /**
     * getAnchoredState() as another endpoint of the chain answers it, at the same pinned block: a
     * second opinion on what the endpoint in use said, where one third-party endpoint alone must
     * not decide (the epoch gate). The others are asked in rpc_list order, the least recently
     * failed first, until one answers. The endpoint in use stays the one in use.
     *
     * @return array{root: string, block_id: int, epoch: int}|null null when the chain has no other
     *         endpoint, or none of them answered
     * @throws RuntimeException on a database error
     */
    public function getAnchoredStateElsewhere(): ?array
    {
        $current = $this->httpProvider->getHost();
        if (($qr = $this->db->doSelect(
            'rpc_list',
            '`rpc_url`',
            ['chain_id' => $this->chainId, 'rpc_url' => ['sign' => '<>', 'value' => $current]],
            'ORDER BY `issue_ts` ASC'
        )) === false) {
            throw new RuntimeException('DB Error: Unable to retrieve the other rpc URLs', INT_EXC_DB);
        }

        $host = new ReflectionProperty($this->httpProvider, 'host');
        try {
            while ($row = $qr->fetch_assoc()) {
                $host->setValue($this->httpProvider, $row['rpc_url']);
                try {
                    return $this->getAnchoredState();
                } catch (Throwable $e) {
                    if (in_array($e->getCode(), [INT_EXC_DB, INT_EXC_FATAL], true)) {
                        throw $e;
                    }
                    // This one did not answer (or not with a value): the next one.
                }
            }
        } finally {
            $host->setValue($this->httpProvider, $current);
        }

        return null;
    }

    /**
     * A uint the contract returned, as an int. A value past PHP_INT_MAX is no answer an honest
     * endpoint gives (no epoch or height gets there), and (int) would not keep it: it is refused
     * as an RPC fault rather than read as some other number.
     *
     * @param mixed $value the decoded uint (a BigInteger)
     * @param string $what for the message
     * @return int
     * @throws EvmRpcException
     */
    protected static function uintToInt(mixed $value, string $what): int
    {
        $digits = (string)$value->toString();
        if (!ctype_digit($digits) || filter_var($digits, FILTER_VALIDATE_INT) === false) {
            throw new EvmRpcException('eth_call ' . $what . ': ' . substr($digits, 0, 40) . ' is out of range');
        }

        return (int)$digits;
    }
}

class EvmRpcException extends Exception
{
    /**
     * The message is kept with every URL in it cut to its scheme and host. A paid endpoint
     * carries its API key in the path or query (https://host/v1/<key>), or a login before the
     * host, and Guzzle ends its messages with the full URL asked ("... for https://host/v1/<key>");
     * these messages go to the error log and, for a FATAL error, to the admin's Telegram. Every
     * failure of an endpoint is raised as one of these (see Evm), so this is the one place to cut.
     */
    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct(self::redactUrls($message), $code, $previous);
    }

    /** $text with each URL in it as scheme://host[:port] only. */
    public static function redactUrls(string $text): string
    {
        return preg_replace(
            '~\b([a-z][a-z0-9+.-]*://)(?:[^\s/?#@]*@)?([^\s/?#@`\'"<>()]+)[^\s`\'"<>()]*~i',
            '$1$2',
            $text
        ) ?? $text;
    }
}
