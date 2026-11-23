<?php declare(strict_types=1);

// Protocol::parseCommand() and its helpers: every OP accepted against a state it is valid for and
// refused against the states it is not, the memo rules (signature, nonce, price, marker), the
// first-output rule of commandOutput(), prices, and the tree path check of a REG (pathTaken()).
// Needs the database of run.sh: a REG's full check reads `domains` (empty here).

require __DIR__ . '/bootstrap.php';

use Indexer\Database;

TestProtocol::init(new Database());

$A = signer('protocol test owner A');
$B = signer('protocol test owner B');
$t = 1780000000; // the block time; nonces are a little below it
$addr0 = address(0);
$addr1 = address(1);
$owned = static fn(array $k, int $price = 0, int $nonce = 1779990000, ?string $target = null): array => [
    'target_address' => $target ?? $addr1, 'owner_pubkey' => $k['pub'], 'nonce' => $nonce, 'price' => $price,
];

/** The full check of $memo against $state for its name (false: the name does not exist). */
$full = static function (string $memo, array|false $state, int $amount) use ($t): array {
    TestProtocol::seed([explode(':', $memo)[3] => $state]);

    return TestProtocol::parseCommand($memo, $amount, $t);
};

section('tree key: kns-core key_of() reference vectors');
check(bin2hex(TestProtocol::treeKey('alice.krgn')) === 'fbf0a43deee9bf66e4bf5415259f5cf4', 'alice.krgn');
check(bin2hex(TestProtocol::treeKey('bob.krgn')) === 'a96751d69f7583b1cd101a6ad887ff69', 'bob.krgn');
check(strlen(TestProtocol::treeKey('x.krgn')) === TestProtocol::TREE_KEY_LEN && TestProtocol::TREE_KEY_LEN === 16,
    '16 bytes: 128 bits');

section('REG');
$name = 'abcdefg.krgn';
$price = TestProtocol::getDomainPrice($name, 'REG');
$reg = memo(['REG', $name, $addr0, $A['pub'], $t - 100], $A['sk']);
$cmd = $full($reg, false, $price);
check($cmd !== [] && $cmd['op'] === 'REG' && $cmd['domain_name'] === $name && $cmd['pubkey'] === $A['pub'] &&
    $cmd['target_address'] === $addr0 && $cmd['nonce'] === $t - 100 && $cmd['price'] === 0, 'a free name is taken');
check(TestProtocol::paymentAmountValid($cmd, $price) && !TestProtocol::paymentAmountValid($cmd, $price - 1),
    'paid exactly its price');
check($full($reg, $owned($B), $price) === [], 'a taken name is refused');
check(TestProtocol::parseCommand($reg, $price, $t, true) !== [], 'the pre-check does not look at the state');

section('REG: a name whose whole tree path another name holds (RP-SYS1)');
TestProtocol::seed([$name => false]);
TestProtocol::forgePath($name, 'zzzzzzz.krgn');
check(TestProtocol::pathTaken($name), 'pathTaken() sees the forged holder');
check(TestProtocol::parseCommand($reg, $price, $t) === [], 'its REG is refused');
check(TestProtocol::parseCommand($reg, $price, $t, true) !== [],
    'the pre-check still takes it: the registrar queues it, then refunds it at READY');
TestProtocol::seed([$name => false]);
TestProtocol::forgePath($name, $name);
check(!TestProtocol::pathTaken($name), 'a name is not in its own way');
TestProtocol::seed([$name => false]);
check(!TestProtocol::pathTaken($name), 'no holder, none in the database: free');

section('commitDomainState() records the path the name takes');
TestProtocol::seed([$name => false]);
check(TestProtocol::parseCommand($reg, $price, $t) !== [], 'REG taken');
TestProtocol::commitDomainState($name);
check((TestProtocol::pathHolders()[TestProtocol::treeKey($name)] ?? null) === $name, 'its key is held by it');
check(TestProtocol::domainExists($name), 'and the name exists for the next command');
$again = memo(['REG', $name, $addr1, $B['pub'], $t - 50], $B['sk']);
check(TestProtocol::parseCommand($again, $price, $t) === [], 'a second REG of it in the same run is refused');

section('UPD');
$upd = memo(['UPD', $name, $addr0, $A['pub'], $t - 10], $A['sk']);
$updPrice = TestProtocol::getDomainPrice($name, 'UPD');
check(($cmd = $full($upd, $owned($A), $updPrice)) !== [] && $cmd['target_address'] === $addr0 && $cmd['price'] === 0,
    'the owner moves the target');
check($full($upd, false, $updPrice) === [], 'refused: the name does not exist');
check($full($upd, $owned($B), $updPrice) === [], 'refused: not the owner');
check($full($upd, $owned($A, 0, 1779990000, $addr0), $updPrice) === [], 'refused: nothing to change');
check($full($upd, $owned($A, 5), $updPrice) === [], 'refused: the name is listed');
check($full($upd, $owned($A, 0, $t - 10), $updPrice) === [], 'refused: the nonce is not above the last one');

section('CHG');
$chg = memo(['CHG', $name, $B['pub'], $t - 10], $A['sk']);
check(($cmd = $full($chg, $owned($A), $updPrice)) !== [] && $cmd['pubkey'] === $B['pub'] && $cmd['target_address'] === $addr1,
    'the owner hands the name to B, the target stays');
check($full($chg, $owned($B), $updPrice) === [], 'refused: signed by A, owned by B (who is also the new owner)');
check($full(memo(['CHG', $name, $B['pub'], $t - 10], $B['sk']), $owned($A), $updPrice) === [],
    'refused: signed by B, not the owner');
check($full($chg, $owned($A, 5), $updPrice) === [], 'refused: the name is listed');
check(TestProtocol::parseCommand(substr($chg, 0, -1) . (str_ends_with($chg, '0') ? '1' : '0'), $updPrice, $t, true) !== [],
    'the pre-check does not verify a CHG signature (the owner is state)');

section('LST and ULT');
$lst = memo(['LST', $name, $A['pub'], '250000000', $t - 10], $A['sk']);
check(($cmd = $full($lst, $owned($A), $updPrice)) !== [] && $cmd['price'] === 250000000, 'the owner lists it, price exact');
check($full($lst, $owned($B), $updPrice) === [], 'refused: not the owner');
check($full(memo(['LST', $name, $A['pub'], '0250000000', $t - 10], $A['sk']), $owned($A), $updPrice) === [],
    'refused: a price with a leading zero');
check($full(memo(['LST', $name, $A['pub'], '0', $t - 10], $A['sk']), $owned($A), $updPrice) === [], 'refused: price 0');
$ult = memo(['ULT', $name, $A['pub'], $t - 10], $A['sk']);
check(($cmd = $full($ult, $owned($A, 250000000), $updPrice)) !== [] && $cmd['price'] === 0, 'the owner takes it off sale');
check($full($ult, $owned($A), $updPrice) === [], 'refused: not on sale');

section('BUY');
$buy = memo(['BUY', $name, $addr0, $B['pub'], $t - 10], $B['sk']);
check(($cmd = $full($buy, $owned($A, 250000000), 250000000)) !== [] && $cmd['pubkey'] === $B['pub'] && $cmd['price'] === 0,
    'B buys it at its price');
check(TestProtocol::paymentAmountValid($cmd, 250000000), 'the price is the payment');
check($full($buy, $owned($A, 250000000), 249999999) === [], 'refused: paid less than the price');
check($full($buy, $owned($A), 250000000) === [], 'refused: not on sale');
check($full($buy, $owned($B, 250000000), 250000000) === [], 'refused: buying from oneself');

section('memo rules');
TestProtocol::seed([$name => false]);
$sig = substr($reg, -128);
check(TestProtocol::parseCommand(substr($reg, 0, -128) . strtoupper($sig), $price, $t) === [], 'an upper-case signature');
check(TestProtocol::parseCommand(memo(['REG', $name, $addr0, $A['pub'], $t + 301], $A['sk']), $price, $t) === [],
    'a nonce more than NONCE_FUTURE_TOLERANCE past the block');
TestProtocol::seed([$name => false]);
check(TestProtocol::parseCommand(memo(['REG', $name, $addr0, $A['pub'], $t + 300], $A['sk']), $price, $t) !== [],
    'a nonce right at the tolerance');
check(TestProtocol::parseCommand(memo(['REG', $name, $addr0, $A['pub'], '0' . ($t - 100)], $A['sk']), $price, $t) === [],
    'a nonce with a leading zero');
check(TestProtocol::parseCommand(memo(['REG', 'Abcdefg.krgn', $addr0, $A['pub'], $t - 100], $A['sk']), $price, $t) === [],
    'an upper-case name');
check(TestProtocol::parseCommand(memo(['REG', 'abcdefg.com', $addr0, $A['pub'], $t - 100], $A['sk']), $price, $t) === [],
    'a zone that does not exist');
check(TestProtocol::parseCommand($reg . ':x', $price, $t) === [], 'a field too many');
$other = (COIN_NETWORK === 'testnet' ? 'KNS' : 'tKNS') . substr($reg, strlen(PROTOCOL_MARKER));
check(TestProtocol::parseCommand($other, $price, $t) === [], 'the other network\'s marker');
check(TestProtocol::parseCommand(memo(['REG', $name, address(0) . 'x', $A['pub'], $t - 100], $A['sk']), $price, $t) === [],
    'a target that is not an address of this network');

section('commandOutput(): the first output that passes the pre-check decides');
$out = static fn(int $n, string $memo, int $amount = 1000, string $to = INCOME_WALLET): array =>
    ['output' => $n, 'address' => $to, 'memoStr' => $memo, 'valueSat' => $amount];
$picked = TestProtocol::commandOutput([$out(0, 'hello'), $out(1, $reg, $price, 'ks1other'), $out(2, $reg, 0),
    $out(3, $upd, $updPrice), $out(4, $reg, $price)], $t);
check(($picked['output'] ?? null) === 3, 'not text, not another address, not zero: output 3');
check(TestProtocol::commandOutput([$out(0, 'hello')], $t) === null, 'none: null');

section('prices');
check(TestProtocol::getDomainPrice('a.krgn', 'REG') === 2000000000000 &&
    TestProtocol::getDomainPrice('abcdefghij.krgn', 'REG') === 18000000000 &&
    TestProtocol::getDomainPrice('a.krgn', 'UPD') === 3000000000 &&
    TestProtocol::getDomainPrice('abcdefghij.krgn', 'LST') === 1200000000, 'tiers by length, 7 and above alike');

done();
