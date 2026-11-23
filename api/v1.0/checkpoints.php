<?php declare(strict_types=1);

use Indexer\Database;

/**
 * @var array $uri
 * @var Database $db
 */

if (isset($uri[2])) {
    throw new RuntimeException(API_ERROR_WRONG_API_METHOD, INT_EXC_API_ERROR);
}

$request = [
    'order' => 'desc',
    'count' => 100,
    'from_block' => 0,
];
if (($raw = file_get_contents('php://input')) !== '') {
    $input = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);

    if (isset($input['order']) && in_array($input['order'], ['asc', 'desc'], true)) {
        $request['order'] = $input['order'];
    }

    $request['from_block'] = getParamBlock($input['from_block'] ?? 0);

    $request['count'] = getParamCount($input['count'] ?? 0, $request['count'], 500);
}

$where = [
    'block_id' => ['sign' => '>', 'value' => $request['from_block']],
];
if ($request['order'] === 'desc') {
    if ($request['from_block'] !== 0) {
        $where['block_id']['sign'] = '<';
    } else {
        unset($where['block_id']);
    }
}

$qr = $db->doSelect(
    'checkpoints',
    '*',
    $where,
    'ORDER BY `block_id` ' . $request['order'] . ' LIMIT ' . $request['count']
);

if ($qr === false) {
    throw new RuntimeException(API_ERROR_INTERNAL, INT_EXC_DB);
}

$out = [];
if ($qr->num_rows !== 0) {
    while ($r = $qr->fetch_assoc()) {
        $out[] = $r;
    }
}

apiAnswer('response', $out);
