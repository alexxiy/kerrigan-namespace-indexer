<?php declare(strict_types=1);

namespace Indexer;

use BitWasp;
use SodiumException;
use RuntimeException;

class Protocol
{
    protected static Database $db;
    protected static array $domainsCache = [];
    protected static array $domainIntermediateState = [];

    protected static array $commandArgsCountCache = [];

    /// Slack allowed between a command's nonce and its block timestamp, in seconds.
    /// Covers client clock skew without letting a nonce be pushed far into the future.
    public const int NONCE_FUTURE_TOLERANCE = 300;

    // available domain zones. Must equal kns-core's ALLOWED_ZONES.
    protected const array DOMAIN_ZONES = ['kerrigan', 'krgn', 'private', 'secure', 'safe'];

    /**
     * Accepted Kerrigan Sapling address shape, per network. Mirrors kns-core's EXPECTED_HRP
     * and EXPECTED_ADDRESS_LEN, which the circuit enforces.
     *
     * The lengths are not arbitrary: a Sapling payment address is 43 bytes (11-byte
     * diversifier + 32-byte pk_d), which bech32m encodes as ceil(43*8/5)=69 data characters
     * plus a 6-character checksum, plus the separator - so hrp length + 76.
     *
     * The regtest `kregsapling` form (87 characters) is deliberately absent: it is rejected
     * on both networks, matching the circuit.
     */
    protected const array NETWORK_ADDRESS_RULES = [
        'mainnet' => ['hrp' => 'ks',           'len' => 78],
        'testnet' => ['hrp' => 'ktestsapling', 'len' => 88],
    ];

    /**
     * The value bech32m's checksum polymod must come to (BIP 350). bech32, the variant
     * BitWasp\Bech32 checks, uses 1.
     */
    protected const int BECH32M_CONST = 0x2bc830a3;

    /** Decoded payload length of a Sapling payment address. */
    public const int SAPLING_PAYLOAD_LEN = 43;

    // OPs
    public const string OP_REG = 'REG';
    public const string OP_UPD = 'UPD';
    public const string OP_CHG = 'CHG';
    public const string OP_LST = 'LST';
    public const string OP_ULT = 'ULT';
    public const string OP_BUY = 'BUY';

    protected const array OP_LIST = [
        self::OP_REG,
        self::OP_UPD,
        self::OP_CHG,
        self::OP_LST,
        self::OP_ULT,
        self::OP_BUY
    ];

    // The protocol header
    protected const array PROTOCOL_HEADER = [
        'marker' => 0,
        'version' => 1,
        'op' => 2,
        'domain_name' => 3,
    ];

    // Several command format for various OPs
    protected const array COMMAND_TYPE_1 = [
        'target_address' => 4,
        'pubkey' => 5,
        'price' => null,
        'nonce' => 6,
        'signature' => 7
    ];
    protected const array COMMAND_TYPE_2 = [
        'target_address' => null,
        'pubkey' => 4,
        'price' => null,
        'nonce' => 5,
        'signature' => 6
    ];
    protected const array COMMAND_TYPE_3 = [
        'target_address' => null,
        'pubkey' => 4,
        'price' => 5,
        'nonce' => 6,
        'signature' => 7
    ];

    // Command formats to OPs mapping
    protected const array COMMAND_FORMAT = [
        self::OP_REG => self::COMMAND_TYPE_1,
        self::OP_UPD => self::COMMAND_TYPE_1,
        self::OP_BUY => self::COMMAND_TYPE_1,
        self::OP_CHG => self::COMMAND_TYPE_2,
        self::OP_ULT => self::COMMAND_TYPE_2,
        self::OP_LST => self::COMMAND_TYPE_3,
    ];

    public const array PRICES_REG = [
        // Denominated in SATOSHI (1 KRGN = 100000000), matching the units the indexer
        // compares against: commandOutput() takes a shielded output's amount from
        // kerrigand's `valueSat`, already in satoshi, before paymentAmountValid() checks it.
        // PLACEHOLDERS: PIVX Name's table, carried over unchanged in KRGN until the KNS
        // prices are set. Every indexer must use the same table as the registrar.
        1 => 1000000000000, // 10000 KRGN
        2 =>  750000000000, //  7500 KRGN
        3 =>  240000000000, //  2400 KRGN
        4 =>   70000000000, //   700 KRGN
        5 =>   35000000000, //   350 KRGN
        6 =>   17500000000, //   175 KRGN
        7 =>    9000000000, //    90 KRGN
    ];
    public const array PRICES_UPD = [
        1 => 1500000000, // 15 KRGN
        2 => 1200000000, // 12 KRGN
        3 => 1000000000, // 10 KRGN
        4 =>  900000000, //  9 KRGN
        5 =>  800000000, //  8 KRGN
        6 =>  700000000, //  7 KRGN
        7 =>  600000000, //  6 KRGN
    ];

    public static function init(Database $db): void
    {
        self::$db = $db;
    }

    /**
     * @param string $command
     * @param int $txAmount
     * @param int $blockTime
     * @param bool $preCheck
     * @return array
     * @throws RuntimeException
     */
    public static function parseCommand(
        string $command,
        int $txAmount,
        int $blockTime,
        bool $preCheck = false
    ): array {
        $out = [];
        $parts = explode(':', $command);

        foreach (self::PROTOCOL_HEADER as $param => $index) {
            if (!isset($parts[$index]) ||
                match ($param) {
                    // PROTOCOL_MARKER: the wire marker carries the network prefix (tKNS on testnet).
                    'marker' => $parts[$index] === PROTOCOL_MARKER,
                    'version' => $parts[$index] === '1',
                    'op' => in_array($parts[$index], self::OP_LIST, true),
                    'domain_name' => self::isDomainNameValid($parts[$index]),
                } === false) {
                return [];
            }
            $out[$param] = $parts[$index];
        }

        if (($partsCount = count(self::PROTOCOL_HEADER) + self::commArgCount($out['op'])) !== count($parts)) {
            return [];
        }

        if ($preCheck === false) {
            $domainExists = self::domainExists($out['domain_name']);
            if ($out['op'] === self::OP_REG) {
                // If domain is registering, it should not exist
                if ($domainExists) {
                    return [];
                }
            // if domain for update doesn't exist
            } elseif ($domainExists === false) {
                return [];
            }
        }

        try {
            foreach (self::COMMAND_FORMAT[$out['op']] as $param => $index) {
                if ($index === null) {
                    $out[$param] = match ($param) {
                        'target_address' => $preCheck ? '' : self::$domainsCache[$out['domain_name']]['target_address'],
                        'price' => ($preCheck || in_array($out['op'], [self::OP_REG, self::OP_BUY, self::OP_ULT], true)) ? 0 :
                            self::$domainsCache[$out['domain_name']]['price'],
                        default => throw new RuntimeException('Unknown default param: ' . $param),
                    };
                    continue;
                }
                if (!isset($parts[$index]) ||
                    match ($param) {
                        'target_address' => self::isAddressValid($parts[$index]),
                        'pubkey' => self::isValidEd25519Pubkey($parts[$index]),
                        // ctype_digit rejects whitespace, a leading '+' and any non-digit;
                        // FILTER_VALIDATE_INT additionally rejects leading zeros and values
                        // that overflow PHP_INT_MAX. Together they accept only the exact
                        // canonical decimal form, so one command has exactly one valid memo.
                        'nonce', 'price' => ctype_digit($parts[$index]) &&
                            filter_var($parts[$index], FILTER_VALIDATE_INT) !== false &&
                            is_int($parts[$index] = (int)$parts[$index]) && $parts[$index] > 0 &&
                            // Upper-bound the nonce against the block's own timestamp. A
                            // command is signed before it is broadcast, so its nonce cannot
                            // legitimately exceed the block that carries it.
                            // NONCE_FUTURE_TOLERANCE absorbs ordinary client clock skew;
                            // it is a constant, so it stays deterministic.
                            ($param === 'price' ||
                                ($param === 'nonce' &&
                                    $parts[$index] <= $blockTime + self::NONCE_FUTURE_TOLERANCE)),
                        // skip sign check if this is pre-check for OP_CHG
                        'signature' => ($preCheck && $out['op'] === self::OP_CHG) ||
                            (
                                strlen($parts[$index]) === 128 &&
                                ctype_xdigit($parts[$index]) &&
                                strtolower($parts[$index]) === $parts[$index] &&
                                sodium_crypto_sign_verify_detached(
                                    hex2bin($parts[$index]),
                                    implode(':', array_slice($parts, 0, $partsCount - 1)),
                                    // If we change the domain owner, we need to get old pubkey to check the signature
                                    hex2bin($out['op'] !== self::OP_CHG ? $out['pubkey'] :
                                        self::$domainsCache[$out['domain_name']]['owner_pubkey'])
                                )
                            )
                    } === false) {
                    return [];
                }
                $out[$param] = $parts[$index];
            }
        } catch (SodiumException) {
            return [];
        }

        if ($preCheck === false) {
            if ($out['op'] !== self::OP_REG) {
                if (
                    // if nonce in DB equal or more that came in the command
                    self::$domainsCache[$out['domain_name']]['nonce'] >= $out['nonce'] ||
                    // if OP_UPD and nothing to change
                    ($out['op'] === self::OP_UPD && self::$domainsCache[$out['domain_name']]['target_address'] === $out['target_address']) ||
                    // if OP_BUY and TX Amount not equal the selling price of the domain
                    ($out['op'] === self::OP_BUY && $txAmount !== self::$domainsCache[$out['domain_name']]['price']) ||
                    // owner hasn't changed via CHG / there's no point to BUY from yourself
                    (in_array($out['op'], [self::OP_CHG, self::OP_BUY], true) && self::$domainsCache[$out['domain_name']]['owner_pubkey'] === $out['pubkey']) ||
                    // if OP_UPD or OP_CHG called on the domain which is on sale
                    (in_array($out['op'], [self::OP_UPD, self::OP_CHG], true) && self::$domainsCache[$out['domain_name']]['price'] !== 0) ||
                    // if OP_ULT or OP_BUY called on the domain which is not on sale
                    (in_array($out['op'], [self::OP_ULT, self::OP_BUY], true) && self::$domainsCache[$out['domain_name']]['price'] === 0) ||
                    // on any update check that the operation is initiated by the domain owner
                    (in_array($out['op'], [self::OP_UPD, self::OP_LST, self::OP_ULT], true) && self::$domainsCache[$out['domain_name']]['owner_pubkey'] !== $out['pubkey'])
                ) {
                    return [];
                }
            }
            self::$domainIntermediateState = [
                'target_address' => $out['target_address'],
                'owner_pubkey' => $out['pubkey'],
                'nonce' => $out['nonce'],
                'price' => $out['price'],
            ];
        }

        return $out;
    }

    /**
     * The output carrying a transaction's command, or null when it carries none.
     *
     * One command per transaction: the FIRST output paid to INCOME_WALLET whose memo passes
     * parseCommand's pre-check - its format and, for every OP but CHG, its signature, nothing
     * that depends on domain state. The outputs after it are never looked at, even when this
     * one is then refused by the full check or underpaid; the transaction simply carries no
     * valid command.
     *
     * The registrar and the indexer must pick the same output, or their trees diverge. Both
     * see the outputs in the same order (z_viewtransaction lists them by output index),
     * but they judge them at different moments: the registrar at ingest, before the domain
     * state the command will meet is known, the indexer against that state. A rule that
     * skipped an output the full check refuses would therefore pick a different output on
     * each side - the indexer would apply a second command the registrar never queued, and
     * the two roots would part for good. The state-free pre-check is what both can agree on.
     *
     * @param array $outputs z_viewtransaction's `outputs`, in the order it returns them
     * @param int $blockTime the time of the block carrying the transaction
     * @return array{output: int, memo: string, amount: int, command: array}|null
     * @throws RuntimeException
     */
    public static function commandOutput(array $outputs, int $blockTime): ?array
    {
        foreach ($outputs as $oneOutput) {
            // valueSat, the integer satoshi amount, not `value`: that one is a JSON float, and PHP
            // spells a float below 0.0001 as "1.0E-5", which bcmul() refuses with a ValueError.
            // One satoshi sent here with any text memo halted the registrar and every indexer on
            // that block for good.
            if ($oneOutput['address'] !== INCOME_WALLET || !isset($oneOutput['memoStr']) ||
                ($amount = $oneOutput['valueSat']) <= 0) {
                continue;
            }
            if (($command = self::parseCommand($oneOutput['memoStr'], $amount, $blockTime, true)) !== []) {
                return [
                    'output' => $oneOutput['output'],
                    'memo' => $oneOutput['memoStr'],
                    'amount' => $amount,
                    'command' => $command,
                ];
            }
        }

        return null;
    }

    /**
     * @param string $op
     * @return int
     */
    protected static function commArgCount(string $op): int
    {
        if (!isset(self::$commandArgsCountCache[$op])) {
            self::$commandArgsCountCache[$op] = 0;
            foreach (self::COMMAND_FORMAT[$op] as $index) {
                if ($index === null) {
                    continue;
                }
                self::$commandArgsCountCache[$op]++;
            }
        }
        return self::$commandArgsCountCache[$op];
    }

    /**
     * @param string $domainName
     * @return bool
     */
    public static function isDomainNameValid(string $domainName): bool
    {
        if ($domainName === '' || strlen($domainName) > 64 || substr_count($domainName, '.') !== 1 ||
            str_starts_with($domainName, '-') || str_contains($domainName, '--')) {
            return false;
        }
        $domainNameParts = explode('.', $domainName);
        if ($domainNameParts[0] === '' || str_ends_with($domainNameParts[0], '-') ||
            !in_array($domainNameParts[1], self::DOMAIN_ZONES, true)) {
            return false;
        }
        // The D modifier is REQUIRED: without it PCRE lets `$` match before a trailing
        // newline, so "test\n.krgn" would be accepted here while the SP1 circuit
        // rejects it — an unprovable batch that halts the checkpoint chain.
        if (!preg_match("/^[a-z0-9-]+$/D", $domainNameParts[0])) {
            return false;
        }

        return true;
    }

    /**
     * Validates a Kerrigan Sapling shielded address for the configured network.
     *
     * Four checks, matching kns_core::is_address_valid exactly. Anything the circuit
     * refuses but this accepts becomes a command the indexer queues and the prover then
     * cannot prove, stalling the batch — so the two must not drift.
     *
     * 1. Canonical lowercase. Bech32m's checksum is case-insensitive, so the uppercase
     *    spelling decodes to the same payload; accepting both would give one address two
     *    spellings, hashing to two different leaves.
     * 2. Exact character length for this network.
     * 3. Exact HRP and a bech32m checksum (see decodeBech32m), so a testnet address cannot
     *    be registered against mainnet state or vice versa, and an address has one spelling.
     * 4. Decoded payload of exactly 43 bytes. This is NOT implied by (2): bech32m pads to
     *    5-bit groups, so payloads of different byte lengths can encode to the same number
     *    of characters.
     *
     * @param string $address
     * @return bool
     */
    public static function isAddressValid(string $address): bool
    {
        // Enforce lowercase canonical formatting
        if ($address === '' || strtolower($address) !== $address) {
            return false;
        }

        $rules = self::NETWORK_ADDRESS_RULES[COIN_NETWORK];
        if (strlen($address) !== $rules['len']) {
            return false;
        }

        // Validate the Bech32m Sapling address
        try {
            [$hrp, $data] = self::decodeBech32m($address);
            // Verify Kerrigan network Human Readable Part (HRP)
            if ($hrp !== $rules['hrp']) {
                return false;
            }
            // decodeBech32m() has already stripped the 6-character checksum group, so $data
            // is just the payload in 5-bit groups. pad = false makes convertBits throw on a
            // non-zero final pad, which the catch below turns into a rejection — the same
            // strictness kns-core gets from bech32::convert_bits(.., false).
            //
            // count(), not strlen(): convertBits returns an array of bytes.
            if (count(BitWasp\Bech32\convertBits($data, count($data), 5, 8, false))
                !== self::SAPLING_PAYLOAD_LEN) {
                return false;
            }
        } catch (BitWasp\Bech32\Exception\Bech32Exception) {
            return false;
        }

        return true;
    }

    /**
     * Decodes a bech32m string into its HRP and its 5-bit data groups, checksum stripped.
     *
     * Kerrigan Core encodes Sapling addresses with bech32m (BIP 350), and BitWasp\Bech32,
     * the bech32 library here, knows only the original bech32 checksum: its decode() refuses
     * every real Kerrigan address. The two variants share the character set, the HRP
     * expansion and the polymod, and differ only in the value the polymod must come to, so
     * this reuses BitWasp's polyMod() and hrpExpand() and checks for BECH32M_CONST. An
     * address under the bech32 checksum is refused, as Kerrigan Core and kns-core refuse it.
     *
     * Case is not handled here: isAddressValid() has already refused anything that is not
     * lowercase, and CHARSET is lowercase.
     *
     * @param string $address
     * @return array{0: string, 1: int[]} [$hrp, $data]
     * @throws BitWasp\Bech32\Exception\Bech32Exception
     */
    protected static function decodeBech32m(string $address): array
    {
        $length = strlen($address);
        // The same bound as Kerrigan Core's bech32::Decode and BitWasp's decode().
        if ($length > 90) {
            throw new BitWasp\Bech32\Exception\Bech32Exception('Bech32m string cannot exceed 90 characters');
        }
        // The separator is the LAST '1': the HRP may contain one, the data part cannot.
        $positionOne = strrpos($address, '1');
        if ($positionOne === false || $positionOne < 1 || $positionOne + 7 > $length) {
            throw new BitWasp\Bech32\Exception\Bech32Exception('Missing separator, empty HRP or too short checksum');
        }
        $hrp = substr($address, 0, $positionOne);
        for ($i = 0; $i < $positionOne; $i++) {
            if (($o = ord($hrp[$i])) < 33 || $o > 126) {
                throw new BitWasp\Bech32\Exception\Bech32Exception('Out of range character in HRP');
            }
        }

        $data = [];
        for ($i = $positionOne + 1; $i < $length; $i++) {
            if (($value = strpos(BitWasp\Bech32\CHARSET, $address[$i])) === false) {
                throw new BitWasp\Bech32\Exception\Bech32Exception('Invalid bech32m character');
            }
            $data[] = $value;
        }

        $values = array_merge(BitWasp\Bech32\hrpExpand($hrp, strlen($hrp)), $data);
        if (BitWasp\Bech32\polyMod($values, count($values)) !== self::BECH32M_CONST) {
            throw new BitWasp\Bech32\Exception\Bech32Exception('Invalid bech32m checksum');
        }

        return [$hrp, array_slice($data, 0, -6)];
    }

    /**
     * @param string $pubkeyHex
     * @return bool
     */
    public static function isValidEd25519Pubkey(string $pubkeyHex): bool
    {
        // Basic length check (Must be exactly 32 bytes / 64 hex chars)
        // Enforce hexadecimal characters of length 64
        if (strlen($pubkeyHex) !== 64 || !ctype_xdigit($pubkeyHex) || strtolower($pubkeyHex) !== $pubkeyHex) {
            return false;
        }

        $pubkeyBytes = hex2bin($pubkeyHex);

        // The Curve Conversion Validation
        try {
            // This function enforces strict mathematical validation on the curve point.
            // It will throw a SodiumException if the point is invalid or part of a weak subgroup.
            sodium_crypto_sign_ed25519_pk_to_curve25519($pubkeyBytes);
            return true;
        } catch (SodiumException) {
            return false;
        }
    }

    /**
     * @param string $domain
     * @param bool $useCache
     * @return bool
     * @throws RuntimeException
     */
    public static function domainExists(string $domain, bool $useCache = true): bool
    {
        if ($useCache === false || !isset(self::$domainsCache[$domain])) {
            if (($qr = self::$db->doSelect(
                'domains_history',
                ['target_address', 'owner_pubkey', 'nonce', 'price'],
                ['domain_name' => $domain],
                'ORDER BY `domain_block_id` DESC, `nonce` DESC LIMIT 1'
            )) === false) {
                throw new RuntimeException(
                    'Failed to query domains for existence check',
                    INT_EXC_DB
                );
            }

            if ($useCache) {
                self::$domainsCache[$domain] = $qr->num_rows === 0 ? false : $qr->fetch_assoc();
            } else {
                return $qr->num_rows !== 0;
            }
        }

        return is_array(self::$domainsCache[$domain]);
    }

    /**
     * @param array $command
     * @param int $amount
     * @return bool
     */
    public static function paymentAmountValid(array $command, int $amount): bool
    {
        // for BUY, the TX amount is the domain selling price
        return $command['op'] === self::OP_BUY ||
            $amount === self::getDomainPrice($command['domain_name'], $command['op']);
    }

    /**
     * @param string $domain
     * @param string $op
     * @return int
     */
    public static function getDomainPrice(string $domain, string $op): int
    {
        $len = strlen(explode('.', $domain)[0]);
        if ($op === self::OP_REG) {
            return self::PRICES_REG[min($len, 7)];
        }
        return self::PRICES_UPD[min($len, 7)];
    }

    /**
     * @param string $amount
     * @return int
     */
    public static function convertAmount(string $amount): int
    {
        return (int)bcmul($amount, '100000000', 0);
    }

    /**
     * @param string $domain
     * @return void
     */
    public static function commitDomainState(string $domain): void
    {
        self::$domainsCache[$domain] = self::$domainIntermediateState;
        self::clearDomainState();
    }

    /**
     * @return void
     */
    public static function clearDomainState(): void
    {
        self::$domainIntermediateState = [];
    }
}
