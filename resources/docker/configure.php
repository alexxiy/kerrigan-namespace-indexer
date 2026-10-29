<?php declare(strict_types=1);

$templatePath = '/opt/kerrigan-name-indexer/config/release.inc.php.sample';
$outputPath = '/opt/kerrigan-name-indexer/config/docker.inc.php';

if (!is_file($templatePath)) {
    echo "Error: Template config sample not found at $templatePath\n";
    exit(1);
}

$template = file_get_contents($templatePath);

$replacements = [
    '{:db_user:}' => getenv('DB_USER') ?: 'namedbuser',
    '{:db_password:}' => getenv('DB_PASSWORD') ?: '',
    '{:db_name:}' => getenv('DB_NAME') ?: 'kerrigan-name',
    '{:rpc_host:}' => getenv('RPC_HOST') ?: '127.0.0.1',
    '{:rpc_port:}' => getenv('RPC_PORT') ?: '7121',
    '{:rpc_user:}' => getenv('RPC_USER') ?: '',
    '{:rpc_pass:}' => getenv('RPC_PASS') ?: '',
    '{:notify_type:}' => getenv('NOTIFY_TYPE') ?: 'telegram',
    '{:notify_tg_bot_key:}' => getenv('NOTIFY_TG_BOT_KEY') ?: '',
    '{:notify_tg_user_id:}' => getenv('NOTIFY_TG_USER_ID') ?: '',
    '{:logs_path:}' => getenv('LOGS_PATH') ?: '/var/log/indexer',
    // Cast through (int) so a stray value cannot inject PHP into the generated config —
    // these land unquoted in a `const` declaration.
    '{:evm_throttling:}' => (string)(int)(getenv('EVM_THROTTLING') ?: 0),
    '{:evm_batch_size:}' => (string)max(1, (int)(getenv('EVM_BATCH_SIZE') ?: 10)),
];

$content = strtr($template, $replacements);

// EXPECTED_EPOCH overrides the anchor epoch this image was released for
// (EVM_NETWORK['expected_epoch'] in the template): for an operator who reviews rules changes
// and rollbacks themselves, and follows one before an image carrying it is published. Left
// empty, the node follows the image's own reviewed value, which a new release moves forward.
// Not `?: ''` like the values above: "0" would read as unset there, and is an error here.
$expectedEpoch = getenv('EXPECTED_EPOCH');
if ($expectedEpoch !== false && $expectedEpoch !== '') {
    // Digits only, so nothing but a number can land in the generated PHP.
    if (!ctype_digit($expectedEpoch) || (int)$expectedEpoch < 1) {
        echo "Error: EXPECTED_EPOCH must be a positive integer, got \"$expectedEpoch\"\n";
        exit(1);
    }
    $content = preg_replace(
        "/'expected_epoch' => \\d+,/",
        "'expected_epoch' => " . (int)$expectedEpoch . ',',
        $content,
        -1,
        $count
    );
    if ($count !== 1) {
        echo "Error: expected_epoch not found exactly once in $templatePath\n";
        exit(1);
    }
}

if (file_put_contents($outputPath, $content) === false) {
    echo "Error: Failed to write config to $outputPath\n";
    exit(1);
}

echo "Successfully generated config file at $outputPath\n";
exit(0);
