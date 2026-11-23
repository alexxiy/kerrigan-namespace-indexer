<?php declare(strict_types=1);

namespace Indexer;

use Denpa\Bitcoin\Client as RpcClient;
use Denpa\Bitcoin\Exceptions\BadRemoteCallException;
use Denpa\Bitcoin\Responses\BitcoindResponse;
use RuntimeException;

/**
 * Reads a range of blocks and finds the commands the protocol carries out in them: the sync of the
 * indexer, and whatever else has to judge the chain by exactly the same rules.
 *
 * The state each command meets is Protocol's: what Protocol::domainExists() reads, and the domain
 * state it keeps. Every command carried out moves that state on (Protocol::commitDomainState()), so
 * a range is scanned on top of the state as of the block before it, and leaves Protocol holding the
 * state as of its last block. Nothing is written anywhere: what becomes of the commands is the
 * caller's.
 */
final class BlockScanner
{
    /**
     * @param RpcClient $rpc a node whose wallet holds INCOME_WALLET's viewing key
     * @param int $from the first block to scan
     * @param int $to the last block to scan
     * @param callable(int, string, bool): void|null $scanned called once each block is fully scanned,
     *        with the block, its hash, and whether the range has given no command up to it
     * @param string|null $after the hash of block $from - 1, when the caller has it: block $from has to
     *        be built on it
     * @return array the commands carried out, in the order the chain holds them: parseCommand()'s
     *         answer with `tx_id`, `block_id` and `block_time`
     * @throws RuntimeException|BadRemoteCallException
     */
    public static function scan(RpcClient $rpc, int $from, int $to, ?callable $scanned = null, ?string $after = null): array
    {
        $newData = [];
        // The hash the next block has to be built on. The blocks are read one at a time, so a
        // reorganisation between two reads would otherwise give a range made of two branches.
        $prevHash = $after;
        for ($block = $from; $block <= $to; $block++) {
            /** @var BitcoindResponse $response */
            $response = $rpc->request('getblockhash', $block);
            $blockHash = $response->get();
            if (!is_string($blockHash) || preg_match('/^[0-9a-f]{64}$/D', $blockHash) !== 1) {
                throw new RuntimeException('Got wrong \'getblockhash\' response: ' . json_encode($blockHash));
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
            // Not an error to stop for: the next run reads the range again, from the chain as it
            // is then.
            if ($prevHash !== null && ($blockData['previousblockhash'] ?? null) !== $prevHash) {
                throw new RuntimeException(
                    'Block ' . $block . ' (' . $blockHash . ') is built on ' . json_encode($blockData['previousblockhash'] ?? null) .
                    ', not on ' . $prevHash . ' read as block ' . ($block - 1) . ': the chain was reorganised during the scan'
                );
            }
            $prevHash = $blockHash;

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

            if ($scanned !== null) {
                $scanned($block, $blockHash, $newData === []);
            }
        }

        return $newData;
    }

    /**
     * The `domains_history` row of a command scan() found carried out: the domain state it leaves,
     * and where it was carried out.
     *
     * @param array $command one of scan()'s answer
     * @return array{domain_name: string, domain_block_id: int, domain_ts: int, target_address: string,
     *     owner_pubkey: string, domain_tx: string, op: string, nonce: int, price: int}
     */
    public static function historyRow(array $command): array
    {
        return [
            'domain_name' => $command['domain_name'],
            'domain_block_id' => $command['block_id'],
            'domain_ts' => $command['block_time'],
            'target_address' => $command['target_address'],
            'owner_pubkey' => $command['pubkey'],
            'domain_tx' => $command['tx_id'],
            'op' => $command['op'],
            'nonce' => $command['nonce'],
            'price' => $command['price'],
        ];
    }
}
