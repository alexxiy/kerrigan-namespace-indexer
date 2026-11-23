<?php declare(strict_types=1);

namespace Indexer;

use Exception;

/**
 * Binary client for the rr-proxy daemon.
 *
 * Every frame is a 5-byte header followed by an opaque payload:
 *
 *   Request:   [u8 opcode][u32 length][payload]
 *   Response:  [u8 status][u32 length][payload]
 *
 * Lengths are big-endian ('N'). Because payloads are length-prefixed rather than
 * delimited, keys and values may contain any byte — no escaping, no reserved
 * characters, and a value can never be mistaken for a status token.
 *
 * Sparse Merkle Tree traversal lives in the daemon. A domain update or proof is
 * one round trip instead of the ~380 a client-side tree walk required, and a
 * proof always arrives with the root it was built from.
 */
class RustRocksDBProxy
{
    // --- Opcodes ---
    private const int OP_PING         = 0x01;
    private const int OP_STATS        = 0x02;
    private const int OP_GET          = 0x10;
    private const int OP_PUT          = 0x11;
    private const int OP_DELETE       = 0x12;
    private const int OP_BATCH_START  = 0x20;
    private const int OP_BATCH_COMMIT = 0x21;
    private const int OP_BATCH_ABORT  = 0x22;
    private const int OP_CLEAR        = 0x30;
    private const int OP_SMT_ROOT     = 0x40;
    private const int OP_SMT_LEAF     = 0x41;
    private const int OP_SMT_UPDATE   = 0x42;
    private const int OP_SMT_PROOF    = 0x43;
    private const int OP_SMT_VERIFY   = 0x44;
    private const int OP_SMT_PROOFS   = 0x45;

    /** The most domains one SMT_PROOFS request may name (the daemon's MAX_PROOFS_PER_REQUEST). */
    public const int MAX_PROOFS_PER_REQUEST = 1000;

    // --- Status codes ---
    public const int ST_OK               = 0x00;
    public const int ST_NOT_FOUND        = 0x01;
    public const int ST_ERROR            = 0x02;
    public const int ST_BAD_COMMAND      = 0x03;
    public const int ST_QUEUED           = 0x04;
    public const int ST_BATCH_TOO_LARGE  = 0x05;
    public const int ST_ALREADY_IN_BATCH = 0x06;
    /** A write refused, with nothing done: another connection is writing (one writer per database). */
    public const int ST_BUSY             = 0x07;

    private const array STATUS_NAMES = [
        self::ST_OK               => 'OK',
        self::ST_NOT_FOUND        => 'NOT_FOUND',
        self::ST_ERROR            => 'ERROR',
        self::ST_BAD_COMMAND      => 'BAD_COMMAND',
        self::ST_QUEUED           => 'QUEUED',
        self::ST_BATCH_TOO_LARGE  => 'BATCH_TOO_LARGE',
        self::ST_ALREADY_IN_BATCH => 'ALREADY_IN_BATCH',
        self::ST_BUSY             => 'BUSY',
    ];

    /** Bytes in a node hash. */
    public const int HASH_LEN = 32;
    /**
     * Upper bound on proof depth.
     *
     * The tree is compact: a leaf sits at the shallowest depth at which it is the
     * only key in its subtree, so a proof carries roughly log2(domains) siblings
     * rather than a fixed 128. This is a sanity bound on the depth the daemon
     * reports, NOT the length of any particular proof.
     */
    public const int MAX_PROOF_DEPTH = 128;

    // --- Proof terminal kinds (see the prover's docs/compact-smt.md) ---
    /** The domain's own leaf is at the terminal — the domain is present. */
    private const int TERMINAL_OCCUPIED = 0x00;
    /** An empty subtree is at the terminal — the domain is absent. */
    private const int TERMINAL_VACANT = 0x01;
    /** A different domain's leaf holds the slot — absent, and inserting splits. */
    private const int TERMINAL_BLOCKED = 0x02;

    private string $socketPath;

    /** @var resource|null */
    private $fp = null;

    private float $connectTimeout;
    private int $readTimeout;

    /** False once the daemon has answered SMT_PROOFS as an opcode it does not know. */
    private bool $hasProofLists = true;

    /**
     * True from a BATCH_START the daemon accepted until its BATCH_COMMIT succeeds or the
     * connection is dropped: the batch lives on this connection only.
     */
    private bool $inBatch = false;

    /**
     * @param string $socketPath  unix://… path to the daemon socket
     * @param float  $connectTimeout seconds to wait for the initial connection
     * @param int    $readTimeout    seconds to wait for a response before giving up
     */
    public function __construct(string $socketPath, float $connectTimeout = 1.0, int $readTimeout = 300)
    {
        $this->socketPath = $socketPath;
        $this->connectTimeout = $connectTimeout;
        $this->readTimeout = $readTimeout;
    }

    public function __destruct()
    {
        $this->disconnect();
    }

    /**
     * @return void
     * @throws RustRocksDBProxyException
     */
    public function connect(): void
    {
        if ($this->fp !== null) {
            // The daemon closes a connection idle past its idle_timeout_secs. Outside a batch
            // nothing lives on the connection, so a fresh one is as good, and is made here,
            // before a request could be lost on the closed one. Inside a batch it is left to
            // fail: the batch went with it (see call()).
            if ($this->inBatch || !$this->closedWhileIdle()) {
                return;
            }
            $this->disconnect();
        }

        $fp = @stream_socket_client($this->socketPath, $errNo, $errStr, $this->connectTimeout);
        if ($fp === false) {
            // Left null so a later call retries instead of operating on `false`.
            $this->fp = null;
            throw new RustRocksDBProxyException(
                "CRITICAL: Failed to connect to rr-proxy. Is the daemon running? Error: $errStr ($errNo)"
            );
        }

        // Without this a stalled daemon would block the caller indefinitely.
        // CLEAR performs a full compaction, so the default must be generous.
        stream_set_timeout($fp, $this->readTimeout);
        $this->fp = $fp;
    }

    /**
     * @return void
     */
    public function disconnect(): void
    {
        if ($this->fp !== null) {
            if (is_resource($this->fp)) {
                @fclose($this->fp);
            }
            $this->fp = null;
        }
        // The daemon drops a batch with the connection that opened it.
        $this->inBatch = false;
    }

    /**
     * Whether the daemon has closed (or broken) this connection while it was idle.
     *
     * The daemon never sends anything unasked, so between requests the socket is readable
     * only at end of file or on an error. Polled without waiting; feof() would not do, as on a
     * live socket it waits out the read timeout.
     */
    private function closedWhileIdle(): bool
    {
        if (!is_resource($this->fp)) {
            return true;
        }
        $read = [$this->fp];
        $write = null;
        $except = null;
        return @stream_select($read, $write, $except, 0) !== 0;
    }

    // =================================================================
    // FRAMING
    // =================================================================

    /**
     * Sends one request and reads exactly one response.
     *
     * Any I/O failure drops the connection: a half-written request or a partial
     * response would leave the stream misaligned, and every later call would
     * silently read the wrong frame.
     *
     * > **Do not retry after catching an I/O failure without restarting the
     * > transaction.** Dropping the connection makes the daemon discard whatever
     * > that connection had queued — the correct rollback — but a caller that
     * > catches and retries gets a *fresh* connection with no batch open, while
     * > still believing one is. Callers today let the exception terminate the
     * > process, which is why this is safe; adding retry logic means calling
     * > beginTransaction() again and replaying the work.
     *
     * @return array{0:int,1:string} [status, payload]
     * @throws RustRocksDBProxyException
     */
    private function call(int $opcode, string $payload = ''): array
    {
        $this->connect();

        $frame = chr($opcode) . pack('N', strlen($payload)) . $payload;

        try {
            $this->writeAll($frame);
            $header = $this->readExactly(5);
            $status = ord($header[0]);
            /** @var array{1:int} $unpacked */
            $unpacked = unpack('N', substr($header, 1, 4));
            $length = $unpacked[1];
            $body = $length > 0 ? $this->readExactly($length) : '';
        } catch (RustRocksDBProxyException $e) {
            $this->disconnect();
            throw $e;
        }

        return [$status, $body];
    }

    /**
     * @throws RustRocksDBProxyException
     */
    private function writeAll(string $data): void
    {
        $total = strlen($data);
        $written = 0;

        while ($written < $total) {
            // A short write is normal on a socket; only false is a failure.
            $n = @fwrite($this->fp, substr($data, $written), $total - $written);
            if ($n === false || $n === 0) {
                throw new RustRocksDBProxyException(
                    'CRITICAL: Write to rr-proxy failed after ' . $written . ' of ' . $total . ' bytes.'
                );
            }
            $written += $n;
        }
    }

    /**
     * @throws RustRocksDBProxyException
     */
    private function readExactly(int $length): string
    {
        $buffer = '';
        while (strlen($buffer) < $length) {
            $chunk = @fread($this->fp, $length - strlen($buffer));

            if ($chunk === false || $chunk === '') {
                $meta = is_resource($this->fp) ? stream_get_meta_data($this->fp) : ['timed_out' => false];
                if (!empty($meta['timed_out'])) {
                    throw new RustRocksDBProxyException(
                        'CRITICAL: rr-proxy did not respond within ' . $this->readTimeout . 's.'
                    );
                }
                throw new RustRocksDBProxyException(
                    'CRITICAL: Socket connection dropped while reading a response from rr-proxy.'
                );
            }
            $buffer .= $chunk;
        }
        return $buffer;
    }

    private static function statusName(int $status): string
    {
        return self::STATUS_NAMES[$status] ?? ('0x' . bin2hex(chr($status)));
    }

    /**
     * Asserts that a payload is exactly one node hash.
     *
     * Without this, a short or empty payload would flow onward silently:
     * unpack('C*', '') yields an empty array rather than an error, so a
     * truncated root would reach the prover's JSON as a zero-length value
     * instead of raising. Cheap insurance against a protocol or version
     * mismatch producing quietly wrong proofs.
     *
     * @throws RustRocksDBProxyException
     */
    private static function expectHash(string $body, string $what): string
    {
        if (strlen($body) !== self::HASH_LEN) {
            throw new RustRocksDBProxyException(
                'CRITICAL: ' . $what . ' returned ' . strlen($body) .
                ' bytes, expected ' . self::HASH_LEN . '.'
            );
        }
        return $body;
    }

    /**
     * Rejects any status outside the supplied whitelist.
     *
     * @param int[] $accepted
     * @throws RustRocksDBProxyException
     */
    private function expect(int $status, string $body, array $accepted, string $what): void
    {
        if (in_array($status, $accepted, true)) {
            return;
        }
        $detail = ($status === self::ST_ERROR && $body !== '') ? ': ' . $body : '';
        throw new RustRocksDBProxyException(
            'CRITICAL: ' . $what . ' failed (' . self::statusName($status) . ')' . $detail
        );
    }

    /**
     * Encodes a domain record for SMT_UPDATE / SMT_VERIFY.
     *
     * Layout: [32 pubkey][u64 price][u64 nonce][u32 addrLen][address][domain].
     * Integers are big-endian on the wire; the daemon converts them to
     * little-endian inside the hash, matching the prover.
     */
    private static function encodeRecord(
        string $domain,
        string $pubkeyBinary,
        string $targetAddress,
        int $price,
        int $nonce
    ): string {
        if (strlen($pubkeyBinary) !== self::HASH_LEN) {
            throw new RustRocksDBProxyException(
                'CRITICAL: pubkey must be ' . self::HASH_LEN . ' raw bytes, got ' . strlen($pubkeyBinary) . '.'
            );
        }
        if ($domain === '') {
            throw new RustRocksDBProxyException('CRITICAL: domain must not be empty.');
        }
        // pack('J', -1) silently becomes 0xFFFFFFFFFFFFFFFF, so a negative value
        // would be hashed as u64::MAX rather than rejected. Both sides would agree
        // on the digest, which is precisely what makes it dangerous: an upstream
        // data bug would produce a valid-looking leaf for the wrong state.
        if ($price < 0 || $nonce < 0) {
            throw new RustRocksDBProxyException(
                'CRITICAL: price and nonce must not be negative (got ' . $price . '/' . $nonce . ').'
            );
        }

        return $pubkeyBinary
            . pack('J', $price)
            . pack('J', $nonce)
            . pack('N', strlen($targetAddress))
            . $targetAddress
            . $domain;
    }

    // =================================================================
    // TRANSACTIONS
    // =================================================================

    /**
     * @throws RustRocksDBProxyException
     */
    public function beginTransaction(): void
    {
        [$status, $body] = $this->call(self::OP_BATCH_START);
        $this->expect($status, $body, [self::ST_OK], 'Starting transaction');
        $this->inBatch = true;
    }

    /**
     * @throws RustRocksDBProxyException
     */
    public function commitTransaction(): void
    {
        [$status, $body] = $this->call(self::OP_BATCH_COMMIT);
        $this->expect($status, $body, [self::ST_OK], 'Committing RocksDB batch');
        // Only on success: a batch the daemon failed stays open until it is aborted.
        $this->inBatch = false;
    }

    // =================================================================
    // SPARSE MERKLE TREE
    // =================================================================

    /**
     * Current tree root as 32 raw bytes.
     *
     * @throws RustRocksDBProxyException
     */
    public function smtRoot(): string
    {
        [$status, $body] = $this->call(self::OP_SMT_ROOT);
        $this->expect($status, $body, [self::ST_OK], 'Reading SMT root');
        return self::expectHash($body, 'SMT_ROOT');
    }

    /**
     * Stored leaf for a domain as 32 raw bytes, or null when the domain has no
     * leaf (or holds the empty-leaf value).
     *
     * @throws RustRocksDBProxyException
     */
    public function smtLeaf(string $domain): ?string
    {
        [$status, $body] = $this->call(self::OP_SMT_LEAF, $domain);
        if ($status === self::ST_NOT_FOUND) {
            return null;
        }
        $this->expect($status, $body, [self::ST_OK], 'Reading SMT leaf');
        return self::expectHash($body, 'SMT_LEAF');
    }

    /**
     * Hashes the record, writes the leaf, rehashes the spine, and returns the
     * new root as 32 raw bytes.
     *
     * The leaf hash is computed by the daemon so its format has exactly one
     * implementation, shared with the on-chain prover.
     *
     * @throws RustRocksDBProxyException
     */
    public function smtUpdate(
        string $domain,
        string $pubkeyBinary,
        string $targetAddress,
        int $price,
        int $nonce
    ): string {
        $payload = self::encodeRecord($domain, $pubkeyBinary, $targetAddress, $price, $nonce);
        [$status, $body] = $this->call(self::OP_SMT_UPDATE, $payload);
        $this->expect($status, $body, [self::ST_OK], 'Updating SMT for "' . $domain . '"');
        return self::expectHash($body, 'SMT_UPDATE');
    }

    /**
     * True only when the stored leaf equals the hash of exactly this record.
     *
     * @throws RustRocksDBProxyException
     */
    public function smtVerify(
        string $domain,
        string $pubkeyBinary,
        string $targetAddress,
        int $price,
        int $nonce
    ): bool {
        $payload = self::encodeRecord($domain, $pubkeyBinary, $targetAddress, $price, $nonce);
        [$status, $body] = $this->call(self::OP_SMT_VERIFY, $payload);
        if ($status === self::ST_NOT_FOUND) {
            return false;
        }
        $this->expect($status, $body, [self::ST_OK], 'Verifying SMT state for "' . $domain . '"');
        return true;
    }

    /**
     * Merkle proof for a domain, together with the root it was built from.
     *
     * Both come from a single database snapshot, so they always describe the
     * same state of the tree even while another process is committing.
     *
     * @return array{root:string, proof:array{depth:int, siblings:string[], terminal:string|array}}
     *         root and siblings are raw 32-byte strings, deepest sibling first
     * @throws RustRocksDBProxyException
     */
    public function smtProof(string $domain): array
    {
        [$status, $body] = $this->call(self::OP_SMT_PROOF, $domain);
        $this->expect($status, $body, [self::ST_OK], 'Reading SMT proof for "' . $domain . '"');

        return self::decodeProof($body, $domain);
    }

    /**
     * Merkle proofs for several domains, each with the root it was built from, in the order given.
     *
     * Read with SMT_PROOFS: up to MAX_PROOFS_PER_REQUEST domains in one round trip, all from one
     * database snapshot, so all against the same root. A longer list is asked in parts, each from
     * its own snapshot. A daemon from before SMT_PROOFS (rr-proxy 0.1.0) answers it as an
     * unknown opcode; the proofs are then read one by one with SMT_PROOF, as before, and a commit
     * landing between two of them gives them different roots - which is why each carries its own.
     *
     * @param string[] $domains non-empty domains
     * @return list<array{root:string, proof:array{depth:int, siblings:string[], terminal:string|array}}>
     * @throws RustRocksDBProxyException
     */
    public function smtProofs(array $domains): array
    {
        $out = [];
        foreach (array_chunk(array_values($domains), self::MAX_PROOFS_PER_REQUEST) as $part) {
            if ($this->hasProofLists) {
                $payload = '';
                foreach ($part as $domain) {
                    $payload .= pack('N', strlen($domain)) . $domain;
                }
                [$status, $body] = $this->call(self::OP_SMT_PROOFS, $payload);
                if ($status === self::ST_BAD_COMMAND && $body === 'unknown opcode') {
                    $this->hasProofLists = false;
                } else {
                    $this->expect($status, $body, [self::ST_OK], 'Reading SMT proofs for ' . count($part) . ' domain(s)');
                    array_push($out, ...self::decodeProofs($body, $part));
                    continue;
                }
            }
            foreach ($part as $domain) {
                $out[] = $this->smtProof($domain);
            }
        }

        return $out;
    }

    /**
     * Decodes an SMT_PROOFS response: one root, then every proof as SMT_PROOF sends it without the
     * root, length-prefixed, in the order the domains were asked.
     *
     * ```text
     * [root: 32][count: u32]
     * count × [length: u32][depth: u8][terminal: u8][siblings: 32 * depth][record, if BLOCKED]
     * ```
     *
     * Each proof is checked by decodeProof() with the root put back in front of it, and the count
     * and every length against the bytes there are.
     *
     * @param string[] $domains the domains asked, in order
     * @return list<array{root:string, proof:array{depth:int, siblings:string[], terminal:string|array}}>
     * @throws RustRocksDBProxyException
     */
    private static function decodeProofs(string $body, array $domains): array
    {
        $size = strlen($body);
        if ($size < self::HASH_LEN + 4) {
            throw new RustRocksDBProxyException(
                'CRITICAL: SMT proofs response is ' . $size . ' bytes, too short for a header.'
            );
        }
        $root = substr($body, 0, self::HASH_LEN);
        /** @var array{1:int} $unpacked */
        $unpacked = unpack('N', $body, self::HASH_LEN);
        if ($unpacked[1] !== count($domains)) {
            throw new RustRocksDBProxyException(
                'CRITICAL: SMT proofs response carries ' . $unpacked[1] . ' proof(s) for ' .
                count($domains) . ' domain(s).'
            );
        }

        $out = [];
        $offset = self::HASH_LEN + 4;
        foreach ($domains as $domain) {
            if ($size - $offset < 4) {
                throw new RustRocksDBProxyException(
                    'CRITICAL: SMT proofs response ends before the proof for "' . $domain . '".'
                );
            }
            /** @var array{1:int} $unpacked */
            $unpacked = unpack('N', $body, $offset);
            $offset += 4;
            if ($unpacked[1] > $size - $offset) {
                throw new RustRocksDBProxyException(
                    'CRITICAL: SMT proof for "' . $domain . '" claims ' . $unpacked[1] .
                    ' bytes, but only ' . ($size - $offset) . ' are left.'
                );
            }
            $out[] = self::decodeProof($root . substr($body, $offset, $unpacked[1]), $domain);
            $offset += $unpacked[1];
        }
        if ($offset !== $size) {
            throw new RustRocksDBProxyException(
                'CRITICAL: SMT proofs response has ' . ($size - $offset) . ' trailing bytes.'
            );
        }

        return $out;
    }

    /**
     * Decodes an SMT_PROOF response.
     *
     * ```text
     * [root: 32][depth: u8][terminal: u8][siblings: 32 * depth]
     * ```
     *
     * A TERMINAL_BLOCKED response is followed by the blocking domain's full record,
     * in the same layout clients send to SMT_UPDATE. The whole preimage is carried
     * rather than just its key because the circuit rederives the blocking key from
     * the same bytes that produce its leaf hash — see the prover's
     * docs/compact-smt.md section 5.3, which explains why a key alone is exploitable.
     *
     * Every length is checked. A short or over-long payload means a protocol or
     * version mismatch, and letting one through would produce quietly wrong proofs.
     *
     * @return array{root:string, proof:array{depth:int, siblings:string[], terminal:string|array}}
     * @throws RustRocksDBProxyException
     */
    private static function decodeProof(string $body, string $domain): array
    {
        $header = self::HASH_LEN + 2;
        if (strlen($body) < $header) {
            throw new RustRocksDBProxyException(
                'CRITICAL: SMT proof for "' . $domain . '" is ' . strlen($body) .
                ' bytes, too short for a header.'
            );
        }

        $root = substr($body, 0, self::HASH_LEN);
        $depth = ord($body[self::HASH_LEN]);
        $terminalTag = ord($body[self::HASH_LEN + 1]);

        if ($depth > self::MAX_PROOF_DEPTH) {
            throw new RustRocksDBProxyException(
                'CRITICAL: SMT proof for "' . $domain . '" claims depth ' . $depth .
                ', above the maximum of ' . self::MAX_PROOF_DEPTH . '.'
            );
        }

        $needed = $header + self::HASH_LEN * $depth;
        if (strlen($body) < $needed) {
            throw new RustRocksDBProxyException(
                'CRITICAL: SMT proof for "' . $domain . '" claims depth ' . $depth .
                ' but carries only ' . (strlen($body) - $header) . ' bytes of siblings.'
            );
        }

        $siblings = [];
        for ($i = 0; $i < $depth; $i++) {
            $siblings[] = substr($body, $header + self::HASH_LEN * $i, self::HASH_LEN);
        }

        $rest = substr($body, $needed);

        switch ($terminalTag) {
            case self::TERMINAL_OCCUPIED:
            case self::TERMINAL_VACANT:
                if ($rest !== '') {
                    throw new RustRocksDBProxyException(
                        'CRITICAL: SMT proof for "' . $domain . '" has ' . strlen($rest) .
                        ' trailing bytes after a terminal that carries no record.'
                    );
                }
                $terminal = $terminalTag === self::TERMINAL_OCCUPIED ? 'Occupied' : 'Vacant';
                break;

            case self::TERMINAL_BLOCKED:
                $terminal = ['Blocked' => ['record' => self::decodeRecord($rest, $domain)]];
                break;

            default:
                throw new RustRocksDBProxyException(
                    'CRITICAL: SMT proof for "' . $domain . '" has unknown terminal kind 0x' .
                    bin2hex(chr($terminalTag)) . '.'
                );
        }

        return [
            'root' => self::expectHash($root, 'SMT_PROOF root'),
            'proof' => ['depth' => $depth, 'siblings' => $siblings, 'terminal' => $terminal],
        ];
    }

    /**
     * Decodes a blocking domain's record — the exact inverse of encodeRecord().
     *
     * @return array{domain:string, pubkey:string, target_address:string, price:int, nonce:int}
     *         pubkey is raw binary; SparseMerkleTree converts it for JSON.
     * @throws RustRocksDBProxyException
     */
    private static function decodeRecord(string $body, string $domain): array
    {
        $fixed = self::HASH_LEN + 8 + 8 + 4;
        if (strlen($body) < $fixed) {
            throw new RustRocksDBProxyException(
                'CRITICAL: blocking record in the proof for "' . $domain . '" is ' .
                strlen($body) . ' bytes, too short.'
            );
        }

        $pubkey = substr($body, 0, self::HASH_LEN);
        /** @var array{1:int} $price */
        $price = unpack('J', substr($body, self::HASH_LEN, 8));
        /** @var array{1:int} $nonce */
        $nonce = unpack('J', substr($body, self::HASH_LEN + 8, 8));
        /** @var array{1:int} $addrLen */
        $addrLen = unpack('N', substr($body, self::HASH_LEN + 16, 4));

        $rest = substr($body, $fixed);
        if (strlen($rest) < $addrLen[1]) {
            throw new RustRocksDBProxyException(
                'CRITICAL: blocking record in the proof for "' . $domain .
                '" declares a ' . $addrLen[1] . '-byte address but carries ' . strlen($rest) . '.'
            );
        }

        $blockingDomain = substr($rest, $addrLen[1]);
        if ($blockingDomain === '') {
            throw new RustRocksDBProxyException(
                'CRITICAL: blocking record in the proof for "' . $domain . '" has an empty domain.'
            );
        }

        return [
            'domain' => $blockingDomain,
            'pubkey' => $pubkey,
            'target_address' => substr($rest, 0, $addrLen[1]),
            'price' => $price[1],
            'nonce' => $nonce[1],
        ];
    }

    // =================================================================
    // RAW KEY/VALUE (maintenance and diagnostics)
    // =================================================================

    /**
     * @throws RustRocksDBProxyException
     */
    public function get(string $key): ?string
    {
        [$status, $body] = $this->call(self::OP_GET, $key);
        if ($status === self::ST_NOT_FOUND) {
            return null;
        }
        $this->expect($status, $body, [self::ST_OK], 'RocksDB GET');
        return $body;
    }

    /**
     * @throws RustRocksDBProxyException
     */
    public function put(string $key, string $value): void
    {
        [$status, $body] = $this->call(self::OP_PUT, pack('N', strlen($key)) . $key . $value);
        $this->expect($status, $body, [self::ST_OK, self::ST_QUEUED], 'RocksDB PUT');
    }

    /**
     * @throws RustRocksDBProxyException
     */
    public function delete(string $key): void
    {
        [$status, $body] = $this->call(self::OP_DELETE, $key);
        $this->expect($status, $body, [self::ST_OK, self::ST_QUEUED], 'RocksDB DELETE');
    }

    // =================================================================
    // ADMINISTRATION
    // =================================================================

    /**
     * Wipes the database. Used for a full resynchronisation from chain data.
     *
     * The daemon compacts afterwards, so on a large database this can take a
     * while — the read timeout is sized accordingly.
     *
     * @throws RustRocksDBProxyException
     */
    public function clear(): bool
    {
        [$status, ] = $this->call(self::OP_CLEAR);
        return $status === self::ST_OK;
    }

    /**
     * @throws RustRocksDBProxyException
     */
    public function ping(): bool
    {
        [$status, ] = $this->call(self::OP_PING);
        return $status === self::ST_OK;
    }

    /**
     * RocksDB counters, for health monitoring.
     *
     * @throws RustRocksDBProxyException
     */
    public function stats(): string
    {
        [$status, $body] = $this->call(self::OP_STATS);
        $this->expect($status, $body, [self::ST_OK], 'Reading RocksDB stats');
        return $body;
    }
}

class RustRocksDBProxyException extends Exception
{
}
