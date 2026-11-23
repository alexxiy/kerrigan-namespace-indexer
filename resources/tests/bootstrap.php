<?php declare(strict_types=1);

// Shared by the tests in this directory. Loads the indexer's code without config.php, so each test
// sets the network (NET=mainnet|testnet) and nothing of a real deployment's config is read. A test
// that needs a database gets one from run.sh: TEST_DB_HOST / TEST_DB_PORT / TEST_DB_NAME, user and
// password `t` (a throwaway mysqld of its own).

define('BASE', realpath(__DIR__ . '/../..'));
require BASE . '/vendor/autoload.php';

const PROJECT_NAME = 'KNS';
define('COIN_NETWORK', getenv('NET') ?: 'mainnet');
define('PROTOCOL_MARKER', COIN_NETWORK === 'testnet' ? 't' . PROJECT_NAME : PROJECT_NAME);
const INT_EXC_DB = -98;
const INT_EXC_FATAL = -99;
// Where commands are paid to. Only its equality to an output's address is ever checked here.
const INCOME_WALLET = 'ks1testincomewallet';
define('WORKLOGS_PATH', getenv('TEST_LOGS') ?: sys_get_temp_dir());
if (getenv('TEST_DB_NAME') !== false) {
    define('DB_CONFIG', [
        'host' => (getenv('TEST_DB_HOST') ?: '127.0.0.1') . ':' . (getenv('TEST_DB_PORT') ?: '3306'),
        'user' => 't',
        'pass' => 't',
        'db_name' => getenv('TEST_DB_NAME'),
    ]);
}

$GLOBALS['checks'] = 0;
$GLOBALS['failed'] = 0;

function check(bool $ok, string $what): void
{
    $GLOBALS['checks']++;
    if (!$ok) {
        $GLOBALS['failed']++;
    }
    echo ($ok ? '  ok   ' : '  FAIL ') . $what . PHP_EOL;
}

function section(string $title): void
{
    echo '=== ' . $title . PHP_EOL;
}

function done(): never
{
    echo $GLOBALS['checks'] . ' checks, ' . $GLOBALS['failed'] . ' failed' . PHP_EOL;
    exit($GLOBALS['failed'] === 0 ? 0 : 1);
}

/** A signing key, derived the way the front derives one: SHA-256 of a phrase as the Ed25519 seed. */
function signer(string $phrase): array
{
    $kp = sodium_crypto_sign_seed_keypair(hash('sha256', $phrase, true));

    return ['pub' => bin2hex(sodium_crypto_sign_publickey($kp)), 'sk' => sodium_crypto_sign_secretkey($kp)];
}

/** A memo: the fields, then the signature over them joined by ':' (the marker is put in front). */
function memo(array $fields, string $sk): string
{
    $body = implode(':', [PROTOCOL_MARKER, '1', ...$fields]);

    return $body . ':' . bin2hex(sodium_crypto_sign_detached($body, $sk));
}

/** A Sapling address of this network that passes Protocol::isAddressValid(). */
function address(int $which = 0): string
{
    $mainnet = [
        'ks1npkydsxtk0gzhahlph73ryx7hxk7ee2wgsvvur4y8jrxrr7h5w95gjjzdk98ruxlnrajzyrkjgf',
        'ks1zakp8l6gscp7kamwzyqxe67sk35xmmfvdvfj5e8erjc57n6ge9d8alzh0x2ut4vuh3fhvdrrnwf',
    ];
    $testnet = [
        'ktestsapling1y5p5wupyfwr5679l9u8m9w3lvgs3qdnn7yal5q8hd7fmekr6wsdcxelv4ddufdjygrq92rr29lx',
        'ktestsapling1xfxjd5wc803pt47xq3tsqt5k3qp9jkdmjrqv9u8l9jqhgftkzhs8ekjnpzcysz2gcz9qvx5kj0y',
    ];

    return (COIN_NETWORK === 'testnet' ? $testnet : $mainnet)[$which];
}

/** Starts a process in the background, its output appended to $log (or dropped); see stopProcess(). */
function spawn(array $cmd, array $env = [], ?string $log = null): mixed
{
    $out = $log === null ? ['file', '/dev/null', 'w'] : ['file', $log, 'a'];

    return proc_open($cmd, [['file', '/dev/null', 'r'], $out, $out], $pipes, null, $env + getenv());
}

/** Stops a process spawn() started: SIGTERM, then up to 20 s for it to end. */
function stopProcess(mixed $proc): void
{
    proc_terminate($proc, SIGTERM);
    for ($i = 0; $i < 200 && proc_get_status($proc)['running']; $i++) {
        usleep(100000);
    }
    proc_close($proc);
}

/**
 * Starts the rr-proxy of RR_PROXY with RR_CONFIG (run.sh writes it: a throwaway database,
 * idle_timeout_secs = 2) and returns once it answers on RR_SOCKET.
 */
function startRrProxy(): mixed
{
    $daemon = spawn([getenv('RR_PROXY'), '--config', getenv('RR_CONFIG')], [], getenv('TEST_LOGS') . '/rr-proxy.out');
    // Up once it answers: the socket file of a stopped daemon may still be there.
    for ($i = 0; $i < 80; $i++) {
        try {
            $probe = new \Indexer\RustRocksDBProxy(getenv('RR_SOCKET'));
            if ($probe->ping()) {
                $probe->disconnect();
                return $daemon;
            }
        } catch (\Indexer\RustRocksDBProxyException) {
        }
        usleep(100000);
    }
    throw new RuntimeException('rr-proxy did not come up at ' . getenv('RR_SOCKET'));
}

/**
 * A copy of the indexer's code at $dir/kerrigan-name-indexer, with a config of its own
 * (config/test.inc.php): the test database, rr-proxy at $rrSocket, logs in $logs, the anchor
 * contract on chain 56 at epoch $expectedEpoch, and the coin node's JSON-RPC on port $nodePort
 * (1: none answers). Nothing of a real deployment comes along: no config, lock or cache file, and
 * not the registrar or its API server.
 *
 * @return string the copy's path
 */
function indexerCopy(string $dir, string $rrSocket, string $logs, int $expectedEpoch, int $nodePort = 1): string
{
    $idx = $dir . '/kerrigan-name-indexer';
    @mkdir($dir, 0777, true);
    @mkdir($logs, 0777, true);
    $name = basename(BASE);
    $exclude = '';
    foreach (['config/*.php', 'registrar', 'registrar-api', 'resources/*.lock', 'resources/api_*', 'release_type', '1.php'] as $path) {
        $exclude .= ' --exclude=' . escapeshellarg($name . '/' . $path);
    }
    exec('cd ' . escapeshellarg(dirname(BASE)) . ' && tar -c' . $exclude . ' --exclude=.git ' . escapeshellarg($name) .
        ' | tar -x -C ' . escapeshellarg($dir) .
        ($name === 'kerrigan-name-indexer' ? '' : ' && mv ' . escapeshellarg($dir . '/' . $name) . ' ' . escapeshellarg($idx)),
        $out, $rc);
    if ($rc !== 0) {
        throw new RuntimeException('copying the indexer failed');
    }
    file_put_contents($idx . '/release_type', "test\n");
    file_put_contents($idx . '/config/test.inc.php', '<?php declare(strict_types=1);' . "\n" .
        'const DB_CONFIG = ' . var_export(DB_CONFIG, true) . ";\n" .
        "const RPC_CONFIG = ['scheme' => 'http', 'host' => '127.0.0.1', 'port' => $nodePort, 'user' => 'x', 'pass' => 'x', 'timeout' => 2];\n" .
        "const EVM_NETWORK = ['chain_id' => 56, 'contract_address' => '0x" . str_repeat('ab', 20) . "', 'abi' => __DIR__ . '/../resources/evm-anchor.abi', 'expected_epoch' => " . $expectedEpoch . "];\n" .
        "const INDEXER_ROCKSDB_PROXY_SOCKET_PATH = '$rrSocket';\n" .
        "const SMT_GENESIS_ROOT = '" . str_repeat('0', 64) . "';\n" .
        "const COIN_GENESIS_BLOCK = 1;\nconst EVM_THROTTLING = 0;\nconst EVM_BATCH_SIZE = 10;\nconst COIN_NETWORK = 'mainnet';\n" .
        "const INCOME_WALLET = 'ks1testincomewallet';\nconst INCOME_WALLET_VIEW_KEY = '" . str_repeat('ab', 128) . "';\n" .
        "const NOTIFY_TYPE = '';\nconst NOTIFY_TG_BOT_KEY = '';\nconst NOTIFY_TG_USER_ID = '';\n" .
        "const WORKLOGS_PATH = '$logs';\nconst DEBUG = true;\n");

    return $idx;
}

/**
 * Protocol with its state open to a test: the names it holds and the tree paths they hold.
 */
final class TestProtocol extends \Indexer\Protocol
{
    /** Sets the state of the names given (false: a name that does not exist) and forgets the rest. */
    public static function seed(array $states): void
    {
        self::$domainsCache = $states;
        self::$pathHolders = [];
        self::clearDomainState();
    }

    /** Puts $holder on the tree path of $domain: the collision no two real names are known to make. */
    public static function forgePath(string $domain, string $holder): void
    {
        self::$pathHolders[self::treeKey($domain)] = $holder;
    }

    public static function pathHolders(): array
    {
        return self::$pathHolders;
    }
}
