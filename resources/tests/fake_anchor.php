<?php declare(strict_types=1);

// php -S router: a fake EVM endpoint serving the anchor contract's views, ABI-encoded with the
// indexer's own web3.php. State in $FANCHOR_DIR/state.json:
//   tip (int), epoch (int, or a decimal string past PHP_INT_MAX), invalid (list of idx), roots
//   (idx => 64 hex; default: idx 0 zeros, otherwise sha256 of the idx), batch mode: ok | reverse
//   | unsupported | error1 | drop1 | badhex1 | http500 (every request, batch or not, answers HTTP
//   500). Used by epoch_gate_test.php.
// Every HTTP request is logged to $FANCHOR_DIR/calls as one line: "single <fn> <block>" or
// "batch <n> <fn,...> <blocks>".
require getenv('FANCHOR_VENDOR') . '/autoload.php';

use Web3\Contracts\Ethabi;
use Web3\Utils;

$dir = getenv('FANCHOR_DIR');
$state = json_decode((string)file_get_contents($dir . '/state.json'), true);
$abi = json_decode((string)file_get_contents(getenv('FANCHOR_ABI')), true);
$ethabi = new Ethabi([
    'address' => new Web3\Contracts\Types\Address, 'bool' => new Web3\Contracts\Types\Boolean,
    'bytes' => new Web3\Contracts\Types\Bytes, 'dynamicBytes' => new Web3\Contracts\Types\DynamicBytes,
    'int' => new Web3\Contracts\Types\Integer, 'string' => new Web3\Contracts\Types\Str,
    'uint' => new Web3\Contracts\Types\Uinteger,
]);
$bySelector = [];
foreach ($abi as $f) {
    if (($f['type'] ?? '') === 'function') {
        $bySelector[$ethabi->encodeFunctionSignature(Utils::jsonMethodToString($f))] = $f;
    }
}
$rootOf = static function (int $idx) use ($state): string {
    return $state['roots'][(string)$idx] ?? ($idx === 0 ? str_repeat('0', 64) : hash('sha256', 'root' . $idx));
};
$heightOf = static fn(int $idx): int => 1000 + 100 * $idx;

$answer = static function (array $r) use (&$log, $bySelector, $ethabi, $state, $rootOf, $heightOf): array {
    $ok = static fn($result) => ['jsonrpc' => '2.0', 'id' => $r['id'] ?? null, 'result' => $result];
    switch ($r['method'] ?? '') {
        case 'eth_getBlockByNumber':
            return $ok(['number' => '0x64', 'hash' => '0x' . str_repeat('1', 64)]);
        case 'eth_blockNumber':
            return $ok('0x80');
        case 'eth_call':
            $data = $r['params'][0]['data'];
            $f = $bySelector[substr($data, 0, 10)] ?? null;
            $log[] = [$f['name'] ?? '?', $r['params'][1] ?? '-'];
            $in = $f['inputs'] === [] ? [] : $ethabi->decodeParameters(array_column($f['inputs'], 'type'), '0x' . substr($data, 10));
            $tip = $state['tip'];
            $types = array_column($f['outputs'], 'type');
            $values = match ($f['name']) {
                'rootAt' => ['0x' . ((int)$in[0]->toString() <= $tip ? $rootOf((int)$in[0]->toString()) : str_repeat('0', 64))],
                'verifyRootValidity' => (static function () use ($in, $tip, $rootOf, $heightOf, $state): array {
                    $want = strtolower(str_pad(Utils::stripZero((string)$in[0]), 64, '0', STR_PAD_LEFT));
                    for ($i = 0; $i <= $tip; $i++) {
                        if ($rootOf($i) === $want) {
                            return in_array($i, $state['invalid'] ?? [], true) ? [false, 0] : [true, $heightOf($i)];
                        }
                    }
                    return [false, 0];
                })(),
                'tipIndex' => [$tip],
                'anchoredState' => ['0x' . $rootOf($tip), $heightOf($tip), $state['epoch']],
                'currentRoot' => ['0x' . $rootOf($tip)],
                'epoch' => [$state['epoch']],
                default => null,
            };
            if ($values === null) {
                return ['jsonrpc' => '2.0', 'id' => $r['id'] ?? null, 'error' => ['code' => -32000, 'message' => 'not served']];
            }
            return $ok($ethabi->encodeParameters($types, $values));
    }
    return ['jsonrpc' => '2.0', 'id' => $r['id'] ?? null, 'error' => ['code' => -32601, 'message' => 'method not found']];
};

header('Content-Type: application/json');
$mode = $state['batch'] ?? 'ok';
if ($mode === 'http500') {
    file_put_contents($dir . '/calls', "http500\n", FILE_APPEND);
    http_response_code(500);
    echo '{}';
    return true;
}
$req = json_decode((string)file_get_contents('php://input'), true);
$log = [];
if (is_array($req) && array_is_list($req)) {
    if ($mode === 'unsupported') {
        file_put_contents($dir . '/calls', 'batch ' . count($req) . " refused\n", FILE_APPEND);
        echo json_encode(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32600, 'message' => 'batch requests are not supported']]);
        return true;
    }
    $out = array_map($answer, $req);
    if ($mode === 'reverse') {
        $out = array_reverse($out);
    } elseif ($mode === 'error1') {
        $out[1] = ['jsonrpc' => '2.0', 'id' => $out[1]['id'], 'error' => ['code' => -32000, 'message' => 'missing trie node']];
    } elseif ($mode === 'drop1') {
        unset($out[1]);
        $out = array_values($out);
    } elseif ($mode === 'badhex1') {
        $out[1]['result'] = '0x';
    }
    file_put_contents($dir . '/calls', 'batch ' . count($req) . ' ' . implode(',', array_unique(array_column($log, 0))) . ' '
        . implode(',', array_unique(array_column($log, 1))) . "\n", FILE_APPEND);
    echo json_encode($out);
} else {
    $one = $answer($req ?? []);
    file_put_contents($dir . '/calls', 'single ' . ($log[0][0] ?? ($req['method'] ?? '?')) . ' ' . ($log[0][1] ?? '-') . "\n", FILE_APPEND);
    echo json_encode($one);
}
return true;
