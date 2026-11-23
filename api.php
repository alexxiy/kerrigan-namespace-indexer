<?php declare(strict_types=1);

use Indexer\Database;
use Indexer\RustRocksDBProxyException;

require __DIR__ . '/config.php';
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/include/functions.php';

header('Content-Type: application/json; charset=utf-8');

try {
    // The path as nginx matched it to a location: all before the query. Not parse_url(), which
    // takes a path starting with // for a host and the path after it: //v9/v1.0/info, which nginx
    // serves as /v9/v1.0/info, ran /v1.0/info.
    $rawUri = explode('?', $_SERVER['REQUEST_URI'], 2)[0];
    $uri = explode('/', trim($rawUri, '/'));

    // calling API method
    if (($reqCnt = count($uri)) >= 2 &&
        preg_match('/^v\d+(?:\.\d+)?$/', $uri[0]) &&
        preg_match('/^[a-zA-Z0-9_-]+$/', $uri[1]) &&
        is_file($scriptPath = __DIR__ . '/api/' . $uri[0] . '/' . $uri[1] . '.php')
    ) {
        $db = new Database(false, DB_CONFIG_API);
        require $scriptPath;
    }
} catch (RuntimeException | RustRocksDBProxyException $e) {
    logEvent($e->getMessage(), $_SERVER['REQUEST_URI'], 'indexer-api.debug');
    if (in_array($e->getCode(), [INT_EXC_DB, INT_EXC_API_ERROR], true)) {
        apiAnswer('error', $e->getMessage());
    } else {
        apiAnswer('error', API_ERROR_INTERNAL);
    }
} catch (JsonException $e) {
    apiAnswer('error', API_ERROR_INVALID_INCOMING_JSON);
} catch (TypeError | ValueError $e) {
    // A request field of the wrong JSON type - a number or an array where a string belongs - meeting
    // a typed parameter under strict_types. Uncaught, it was a PHP fatal error in place of the JSON
    // answer, with a stack trace wherever errors are displayed. Logged as an error all the same,
    // since a genuine bug surfaces the same way.
    logEvent($e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')', $_SERVER['REQUEST_URI'], 'indexer-api.error');
    apiAnswer('error', API_ERROR_WRONG_INCOMING_PARAM_VALUE);
}

apiAnswer('error', API_ERROR_WRONG_API_METHOD);
