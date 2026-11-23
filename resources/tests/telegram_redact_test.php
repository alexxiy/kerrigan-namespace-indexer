<?php declare(strict_types=1);

// What a failure to send to Telegram tells the logs: sendTelegram() (include/functions.php), which
// every message to the admin of the indexer, the registrar and the registrar API goes through,
// logs it through telegramErrorText(), so the bot token in the URL the SDK names never reaches a
// log. Checked on the message the SDK really writes, from a request to a closed port, and on
// written ones; and what sendTelegram() sends, to a fake Telegram (`php -S`): the chat, and the
// text cut to 4000 characters. A copy of include/functions.php is loaded with the SDK pointed at
// those local URLs. No database, nothing leaves the machine.

require __DIR__ . '/bootstrap.php';

use Telegram\Bot\Api;

const PORT = 18617;
const TOKEN = '123456:TESTtoken-x_y';
const DEBUG = true;
const NOTIFY_TYPE = 'telegram';
const NOTIFY_TG_BOT_KEY = TOKEN;
const NOTIFY_TG_USER_ID = '42';

$tmp = sys_get_temp_dir() . '/tg-redact-' . getmypid();
@mkdir($tmp);
$logName = 'tg-redact-' . getmypid() . '.debug';
$logPath = WORKLOGS_PATH . '/' . PROJECT_NAME . '-' . $logName . '.log';

// The copy: each `new Api(NOTIFY_TG_BOT_KEY)` given the base URL of $GLOBALS['tgBaseUrl'].
$src = file_get_contents(BASE . '/include/functions.php');
$patched = str_replace('new Api(NOTIFY_TG_BOT_KEY)', 'new Api(NOTIFY_TG_BOT_KEY, false, null, $GLOBALS[\'tgBaseUrl\'])', $src, $count);
check($count === 1, 'include/functions.php makes one Api, in sendTelegram() (' . $count . ')');
file_put_contents($tmp . '/functions.php', $patched);
require $tmp . '/functions.php';

section('the SDK\'s own message');
try {
    (new Api(TOKEN, false, null, 'http://127.0.0.1:1/bot'))->sendMessage(['chat_id' => '42', 'text' => 'x']);
    check(false, 'a closed port refuses');
} catch (Throwable $e) {
    $msg = telegramErrorText($e->getMessage());
    check(str_contains($e->getMessage(), TOKEN), 'the SDK names the URL with the token in it (' . get_class($e) . ')');
    check(!str_contains($msg, TOKEN) && !str_contains($msg, 'TESTtoken') && str_contains($msg, 'cURL error')
        && str_contains($msg, 'http://127.0.0.1:1/bot<bot token>/sendMessage'), 'kept: the error and the URL, not the token: ' . $msg);
}

section('written messages');
$cases = [
    'nothing to cut' => ['Unauthorized', 'Unauthorized'],
    'raw' => ['for https://api.telegram.org/bot' . TOKEN . '/sendMessage', 'for https://api.telegram.org/bot<bot token>/sendMessage'],
    'url-encoded' => ['for https://api.telegram.org/bot' . rawurlencode(TOKEN) . '/sendMessage', 'for https://api.telegram.org/bot<bot token>/sendMessage'],
    'twice' => [TOKEN . ' and ' . TOKEN, '<bot token> and <bot token>'],
];
foreach ($cases as $what => [$in, $out]) {
    $got = telegramErrorText($in);
    check($got === $out, $what . ($got === $out ? '' : ': ' . $got));
}
check(telegramErrorText('bot' . TOKEN, '') === 'bot' . TOKEN, 'no token configured: the message as it is');
check(telegramErrorText('bot' . TOKEN, 'other') === 'bot' . TOKEN, 'another token: nothing cut');

section('sendTelegram()');
@unlink($logPath);
$GLOBALS['tgBaseUrl'] = 'http://127.0.0.1:1/bot';
sendTelegram('a message', $logName);
$log = (string)@file_get_contents($logPath);
check(str_contains($log, 'Telegram sending error') && str_contains($log, 'cURL error') && str_contains($log, '<bot token>'),
    'Telegram not reached: logged in the log asked for');
check(!str_contains($log, 'TESTtoken'), 'the log has no token in it');

file_put_contents($tmp . '/tg.php', '<?php ' .
    'file_put_contents(__DIR__ . "/got.json", json_encode(["uri" => $_SERVER["REQUEST_URI"], "post" => $_POST]));' .
    'header("Content-Type: application/json");' .
    'echo json_encode(["ok" => true, "result" => ["message_id" => 1, "date" => 0, "chat" => ["id" => 42, "type" => "private"], "text" => "x"]]);');
$server = spawn(['php', '-S', '127.0.0.1:' . PORT, $tmp . '/tg.php']);
usleep(500000);
try {
    $GLOBALS['tgBaseUrl'] = 'http://127.0.0.1:' . PORT . '/bot';
    @unlink($logPath);
    $long = str_repeat('é', 3999) . 'ab' . str_repeat('x', 5000);
    sendTelegram($long, $logName);
    $got = json_decode((string)@file_get_contents($tmp . '/got.json'), true);
    check(is_array($got) && $got['uri'] === '/bot' . TOKEN . '/sendMessage', 'sent to the bot\'s sendMessage');
    check(($got['post']['chat_id'] ?? null) === '42', 'to NOTIFY_TG_USER_ID');
    $text = $got['post']['text'] ?? '';
    check(mb_strlen($text) === 4000 && $text === str_repeat('é', 3999) . 'a', 'a text over 4096 characters cut to 4000, by characters (' . mb_strlen($text) . ')');
    check(!file_exists($logPath), 'nothing logged when it is sent');

    sendTelegram('short', $logName);
    $got = json_decode((string)@file_get_contents($tmp . '/got.json'), true);
    check(($got['post']['text'] ?? '') === 'short', 'a short one sent as it is');
} finally {
    stopProcess($server);
    @unlink($logPath);
    foreach (['functions.php', 'tg.php', 'got.json'] as $f) {
        @unlink($tmp . '/' . $f);
    }
    @rmdir($tmp);
}

done();
