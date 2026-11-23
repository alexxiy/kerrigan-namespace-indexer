<?php declare(strict_types=1);

// Exercises Protocol::isAddressValid on both networks, against real checksum-valid
// Kerrigan addresses (made by Kerrigan Core v1.4.0's z_getnewaddress). Loaded without
// config.php so COIN_NETWORK can be forced per run.

require __DIR__ . '/bootstrap.php';

use Indexer\Protocol;

$MAIN    = 'ks1npkydsxtk0gzhahlph73ryx7hxk7ee2wgsvvur4y8jrxrr7h5w95gjjzdk98ruxlnrajzyrkjgf';
$TEST    = 'ktestsapling1y5p5wupyfwr5679l9u8m9w3lvgs3qdnn7yal5q8hd7fmekr6wsdcxelv4ddufdjygrq92rr29lx';
$REGTEST = 'kregsapling1frurahjy20rh4j3mxwvj66623xx9l5wwx6ukcmjyx8ny05hy9lun5jx934na7nruv64puptalrm';
// $MAIN's and $TEST's payloads under the bech32 checksum instead of bech32m. Kerrigan Core
// refuses them, so a valid bech32 checksum must not be enough here either.
$MAIN_BECH32 = 'ks1npkydsxtk0gzhahlph73ryx7hxk7ee2wgsvvur4y8jrxrr7h5w95gjjzdk98ruxlnrajz3lx7dt';
$TEST_BECH32 = 'ktestsapling1y5p5wupyfwr5679l9u8m9w3lvgs3qdnn7yal5q8hd7fmekr6wsdcxelv4ddufdjygrq92kl6f6y';
$WALLET = 'ks14uyfh9m64njntdtxsnt662utf2yxtvtnyzyh2v8d0nna7cngzd6eeht3x80689d973v0yvc2fkz';

$net = COIN_NETWORK;
$ours   = $net === 'testnet' ? $TEST : $MAIN;
$theirs = $net === 'testnet' ? $MAIN : $TEST;
$oursBech32 = $net === 'testnet' ? $TEST_BECH32 : $MAIN_BECH32;

$cases = [
    'this network'                => [$ours, true],
    'other network'               => [$theirs, false],
    'regtest kregsapling'         => [$REGTEST, false],
    'bech32, not bech32m'         => [$oursBech32, false],
    'uppercase of a valid one'    => [strtoupper($ours), false],
    'corrupted checksum'          => [substr($ours, 0, -1) . (substr($ours, -1) === 'q' ? 'p' : 'q'), false],
    'truncated'                   => [substr($ours, 0, -1), false],
    'empty'                       => ['', false],
    'not an address'              => ['not-an-address', false],
    'Kerrigan transparent'        => ['KSvgmrG5YH1yXqRx99XzXphsvkQXpjdFxK', false],
    'PIVX sapling (wrong coin)'   => ['ps10ayxgy96kzhzwe0mtd53lraj4hxsu9scyh730vwxna0ul2yxw2pwxvdceu8tajd40fhvkmx3dyz', false],
    'zcash sapling (wrong coin)'  => ['zs1z7rejlpsa98s2rrrfkwmaxu53e4ue0ulcrw0h4x5g8jl04tak0d3mm47vdtahatqrlkngh9sly', false],
    'configured INCOME_WALLET'    => [$WALLET, $net === 'mainnet'],
];

$pass = 0; $fail = 0;
echo "  --- COIN_NETWORK = $net (marker " . PROTOCOL_MARKER . ") ---\n";
foreach ($cases as $label => [$addr, $want]) {
    $got = Protocol::isAddressValid($addr);
    $ok = $got === $want;
    $ok ? $pass++ : $fail++;
    printf("  %-28s %-6s expected %-5s got %-5s\n", $label, $ok ? 'PASS' : 'FAIL',
        $want ? 'true' : 'false', $got ? 'true' : 'false');
}
echo "  $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
