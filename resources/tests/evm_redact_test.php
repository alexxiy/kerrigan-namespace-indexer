<?php declare(strict_types=1);

// What an EVM endpoint's failure tells the logs: EvmRpcException keeps only the scheme and host of
// every URL in its message, so the API key of a paid endpoint (in the path, the query or a login
// before the host) never reaches the error log or Telegram. Checked on the messages Guzzle really
// writes, from a request to a closed port and one answered 403, and on written ones. No database.

require __DIR__ . '/bootstrap.php';

use GuzzleHttp\Client;
use Indexer\EvmRpcException;

const PORT = 18616;
const KEY = 'S3cretKey0123';

// EvmRpcException lives in Evm.php, which PSR-4 loads by the name Evm only.
class_exists(\Indexer\Evm::class);

/** The message of $e as EvmRpcException keeps it, as Evm wraps an endpoint's failure. */
$wrapped = static fn(Throwable $e): string => (new EvmRpcException('eth_call tipIndex: ' . $e->getMessage(), 0, $e))->getMessage();

section('Guzzle failures');
try {
    (new Client())->post('http://127.0.0.1:1/v1/' . KEY . '?apikey=' . KEY, ['connect_timeout' => 2, 'body' => '{}']);
    check(false, 'a closed port refuses');
} catch (Throwable $e) {
    $msg = $wrapped($e);
    check(str_contains($e->getMessage(), KEY), 'Guzzle names the full URL (' . get_class($e) . ')');
    check(!str_contains($msg, KEY) && str_contains($msg, 'http://127.0.0.1:1') && str_contains($msg, 'cURL error'),
        'kept: the error and the host, not the key: ' . $msg);
}

$tmp = sys_get_temp_dir() . '/redact-' . getmypid();
@mkdir($tmp);
file_put_contents($tmp . '/r.php', '<?php http_response_code(403); echo "{\"error\":\"key\"}";');
$server = spawn(['php', '-S', '127.0.0.1:' . PORT, $tmp . '/r.php']);
usleep(500000);
try {
    (new Client())->post('http://user:' . KEY . '@127.0.0.1:' . PORT . '/bsc/' . KEY, ['timeout' => 5, 'body' => '{}']);
    check(false, 'the endpoint answers 403');
} catch (Throwable $e) {
    $msg = $wrapped($e);
    check(str_contains($e->getMessage(), KEY), 'Guzzle names the full URL (' . get_class($e) . ')');
    check(!str_contains($msg, KEY) && !str_contains($msg, 'user') && str_contains($msg, '`POST http://127.0.0.1:' . PORT . '`')
        && str_contains($msg, '403 Forbidden'), 'kept: the method, host and status, not the key or login: ' . $msg);
} finally {
    stopProcess($server);
    @unlink($tmp . '/r.php');
    @rmdir($tmp);
}

section('written messages');
$cases = [
    'nothing to cut' => ['eth_call rootAt returned no value', 'eth_call rootAt returned no value'],
    'host and port kept' => ['refused by https://bsc.example.org:8545', 'refused by https://bsc.example.org:8545'],
    'path and query cut' => ['cURL error 28: timed out (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for https://bsc-mainnet.nodereal.io/v1/' . KEY . '?x=1#f',
        'cURL error 28: timed out (see https://curl.haxx.se) for https://bsc-mainnet.nodereal.io'],
    'login cut' => ['for https://' . KEY . ':pw@rpc.example.org/', 'for https://rpc.example.org'],
    'an @ after the path' => ['for https://rpc.example.org/a@' . KEY, 'for https://rpc.example.org'],
    'quoted' => ["`POST https://rpc.example.org/" . KEY . "` resulted in a `401 Unauthorized` response: \"https://x.org/" . KEY . "\"",
        "`POST https://rpc.example.org` resulted in a `401 Unauthorized` response: \"https://x.org\""],
    'two of them, upper case' => ['HTTPS://A.example/' . KEY . ' then wss://b.example/' . KEY, 'HTTPS://A.example then wss://b.example'],
];
foreach ($cases as $what => [$in, $out]) {
    $got = (new EvmRpcException($in))->getMessage();
    check($got === $out, $what . ($got === $out ? '' : ': ' . $got));
}
$e = new EvmRpcException('for https://h.example/' . KEY, 7, $prev = new RuntimeException('x'));
check($e->getCode() === 7 && $e->getPrevious() === $prev, 'the code and the previous exception are kept');

done();
