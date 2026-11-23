<?php declare(strict_types=1);

// Checks the indexer's Protocol::isAddressValid against the same corpus the circuit's
// kns-core is checked against. Any disagreement is a host/guest divergence.

require __DIR__ . '/bootstrap.php';

use Indexer\Protocol;

// Defaults to the fixture shipped beside this script; ADDR_CORPUS overrides it.
$corpusPath = getenv('ADDR_CORPUS') ?: __DIR__ . '/fixtures/addr_corpus.json';
$corpus = json_decode(file_get_contents($corpusPath), true, 8, JSON_THROW_ON_ERROR);
$net = COIN_NETWORK;

$checked = 0;
$failures = [];
foreach ($corpus as $c) {
    $want = (bool)$c[$net];
    $got = Protocol::isAddressValid($c['addr']);
    if ($got !== $want) {
        $failures[] = sprintf('%s (%s): expected %s, got %s',
            substr($c['addr'], 0, 30), $c['why'],
            $want ? 'true' : 'false', $got ? 'true' : 'false');
    }
    $checked++;
}

printf("  %d corpus cases checked (%s)\n", $checked, $net);
if ($failures === []) {
    echo "  all agree with the reference\n";
    exit(0);
}
printf("  %d DISAGREE:\n", count($failures));
foreach (array_slice($failures, 0, 12) as $f) {
    echo "    $f\n";
}
exit(1);
