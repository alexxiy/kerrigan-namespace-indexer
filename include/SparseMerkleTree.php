<?php declare(strict_types=1);

namespace Indexer;

/**
 * Thin client over the Sparse Merkle Tree owned by the rr-proxy daemon.
 *
 * Tree traversal, default (empty subtree) hashes and leaf hashing all live in
 * the daemon, sharing one implementation with the on-chain prover. This class
 * only issues commands and converts between raw bytes and the byte-array form
 * used in JSON payloads.
 *
 * Each operation is a single round trip. A proof arrives together with the root
 * it was built from, taken from one database snapshot, so the two can never
 * describe different states of the tree.
 */
class SparseMerkleTree
{
    private RustRocksDBProxy $db;

    public function __construct(RustRocksDBProxy $db)
    {
        $this->db = $db;
    }

    /**
     * Converts raw bytes to the array-of-integers form used in JSON payloads
     * and consumed by the prover.
     *
     * @return int[]
     */
    private static function toBytes(string $binary): array
    {
        return array_values(unpack('C*', $binary));
    }

    /**
     * Current tree root.
     *
     * @return int[] 32 bytes
     * @throws RustRocksDBProxyException
     */
    public function getRoot(): array
    {
        return self::toBytes($this->db->smtRoot());
    }

    /**
     * Current tree root as a hex string.
     *
     * @throws RustRocksDBProxyException
     */
    public function getRootHex(): string
    {
        return bin2hex($this->db->smtRoot());
    }

    /**
     * Merkle proof for a domain, in the shape the prover expects as JSON.
     *
     * The tree is compact, so a proof is variable length: roughly log2(domains)
     * siblings rather than a fixed 128. Callers must not assume a fixed count.
     *
     * @return array{depth:int, siblings:int[][], terminal:string|array}
     * @throws RustRocksDBProxyException
     */
    public function getProof(string $domain): array
    {
        return self::proofToJson($this->db->smtProof($domain)['proof']);
    }

    /**
     * Merkle proof together with the root it was built from.
     *
     * Prefer this over separate getProof()/getRoot() calls: those can observe
     * different states if another process commits between them, producing a
     * proof that will not verify against the returned root.
     *
     * @return array{root:int[], proof:array{depth:int, siblings:int[][], terminal:string|array}}
     * @throws RustRocksDBProxyException
     */
    public function getProofWithRoot(string $domain): array
    {
        $result = $this->db->smtProof($domain);

        return [
            'root' => self::toBytes($result['root']),
            'proof' => self::proofToJson($result['proof']),
        ];
    }

    /**
     * getProofWithRoot() for several domains, in the order given, read in one round trip from one
     * snapshot where the daemon can (see RustRocksDBProxy::smtProofs()). Each keeps its own root
     * all the same: an older daemon, or a list longer than one request, can give different ones.
     *
     * @param string[] $domains
     * @return list<array{root:int[], proof:array{depth:int, siblings:int[][], terminal:string|array}}>
     * @throws RustRocksDBProxyException
     */
    public function getProofsWithRoot(array $domains): array
    {
        $out = [];
        foreach ($this->db->smtProofs($domains) as $result) {
            $out[] = [
                'root' => self::toBytes($result['root']),
                'proof' => self::proofToJson($result['proof']),
            ];
        }

        return $out;
    }

    /**
     * Converts a decoded proof's raw byte strings into the array-of-integers form
     * the prover's JSON payload uses.
     *
     * The shape mirrors the prover's `Proof` struct exactly, including the terminal:
     * `"Occupied"` or `"Vacant"` as a bare string, and `{"Blocked":{"record":{...}}}`
     * when another domain holds the slot. Changing this shape breaks deserialisation
     * inside the zkVM.
     *
     * @param array{depth:int, siblings:string[], terminal:string|array} $proof
     * @return array{depth:int, siblings:int[][], terminal:string|array}
     */
    private static function proofToJson(array $proof): array
    {
        $terminal = $proof['terminal'];

        if (is_array($terminal)) {
            $record = $terminal['Blocked']['record'];
            $terminal = [
                'Blocked' => [
                    'record' => [
                        'domain' => $record['domain'],
                        'pubkey' => self::toBytes($record['pubkey']),
                        'target_address' => $record['target_address'],
                        'price' => $record['price'],
                        'nonce' => $record['nonce'],
                    ],
                ],
            ];
        }

        return [
            'depth' => $proof['depth'],
            'siblings' => array_map(
                static fn(string $sibling): array => self::toBytes($sibling),
                $proof['siblings']
            ),
            'terminal' => $terminal,
        ];
    }

    /**
     * Writes the domain's record into the tree and returns the new root.
     *
     * The leaf hash is derived by the daemon from these fields, so the hashing
     * format is defined in exactly one place.
     *
     * @param string $pubkeyBinary 32-byte raw binary public key
     * @return int[] the new root, 32 bytes
     * @throws RustRocksDBProxyException
     */
    public function update(
        string $domain,
        string $pubkeyBinary,
        string $targetAddress,
        int $price,
        int $nonce
    ): array {
        return self::toBytes(
            $this->db->smtUpdate($domain, $pubkeyBinary, $targetAddress, $price, $nonce)
        );
    }

    /**
     * Raw hex leaf hash for a domain, or null when it has never been registered
     * (or was reset to the empty leaf).
     *
     * @throws RustRocksDBProxyException
     */
    public function getLeaf(string $domain): ?string
    {
        $leaf = $this->db->smtLeaf($domain);
        return $leaf === null ? null : bin2hex($leaf);
    }

    /**
     * Whether a domain currently has a leaf in the tree.
     *
     * @throws RustRocksDBProxyException
     */
    public function leafExists(string $domain): bool
    {
        return $this->db->smtLeaf($domain) !== null;
    }

    /**
     * Verifies that a domain exists in exactly the state described.
     *
     * Returns false if the domain is absent, or if any single field (owner,
     * address, price, nonce) differs from what is stored.
     *
     * @param string $pubkeyBinary 32-byte raw binary public key
     * @throws RustRocksDBProxyException
     */
    public function verifyDomainState(
        string $domain,
        string $pubkeyBinary,
        string $targetAddress,
        int $price,
        int $nonce
    ): bool {
        return $this->db->smtVerify($domain, $pubkeyBinary, $targetAddress, $price, $nonce);
    }
}
