<?php declare(strict_types=1);

use Indexer\Database;
use Indexer\Protocol;

/**
 * @var array $uri
 * @var Database $db
 */

if (!Protocol::isDomainNameValid($domainName = strtolower($uri[2] ?? ''))) {
    throw new RuntimeException(API_ERROR_WRONG_INCOMING_PARAM_VALUE, INT_EXC_API_ERROR);
}

$request = [
    'order' => 'desc',
    'count' => 100,
    'from_block_id' => 0,
];

if (($raw = file_get_contents('php://input')) !== '') {
    $input = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);

    if (isset($input['order']) && in_array($input['order'], ['asc', 'desc'], true)) {
        $request['order'] = $input['order'];
    }

    $request['from_block_id'] = getParamBlock($input['from_block_id'] ?? 0);

    // With from_block_id, the nonce of the last row a client has: the page goes on after that
    // row rather than after its whole block. One block can hold several changes of a name (rows
    // of one block are in nonce order, as nonces strictly increase), and a page could end among
    // them: going on from the next block skipped the rest. Without it, as before.
    if (array_key_exists('from_nonce', $input)) {
        if (!is_int($input['from_nonce']) || $input['from_nonce'] < 0 || $request['from_block_id'] === 0) {
            throw new RuntimeException(API_ERROR_WRONG_INCOMING_PARAM_VALUE, INT_EXC_API_ERROR);
        }
        $request['from_nonce'] = $input['from_nonce'];
    }

    $request['count'] = getParamCount($input['count'] ?? 0, $request['count'], 500);
}

$orderBy = 'ORDER BY `domain_block_id` ' . $request['order'] . ', `nonce` ' . $request['order'] . ' LIMIT ' . $request['count'];
if (isset($request['from_nonce'])) {
    // A row comparison, in the primary key's order: the rows after (block, nonce).
    $qr = $db->doPlainQuery(
        'SELECT * FROM `domains_history` WHERE `domain_name` = {%name%} AND (`domain_block_id`, `nonce`) ' .
        ($request['order'] === 'asc' ? '>' : '<') . ' ({%block%}, {%nonce%}) ' . $orderBy,
        ['{%name%}' => $domainName, '{%block%}' => $request['from_block_id'], '{%nonce%}' => $request['from_nonce']]
    );
} else {
    $where = [
        'domain_name' => $domainName,
        'domain_block_id' => ['sign' => '>', 'value' => $request['from_block_id']],
    ];
    if ($request['order'] === 'desc') {
        if ($request['from_block_id'] !== 0) {
            $where['domain_block_id']['sign'] = '<';
        } else {
            unset($where['domain_block_id']);
        }
    }

    $qr = $db->doSelect('domains_history', '*', $where, $orderBy);
}

if ($qr === false) {
    throw new RuntimeException(API_ERROR_INTERNAL, INT_EXC_DB);
}

$out = [];
if ($qr->num_rows !== 0) {
    while ($r = $qr->fetch_assoc()) {
        unset($r['domain_name']);
        $out[] = $r;
    }
}

apiAnswer('response', $out);
