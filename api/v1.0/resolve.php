<?php declare(strict_types=1);

use Indexer\Database;
use Indexer\RustRocksDBProxy;
use Indexer\SparseMerkleTree;
use Indexer\Protocol;

/**
 * The root of the tree as of a height the sync committed at (PARAM_LAST_PROCESSED_COIN_BLOCK):
 * the root of the checkpoint it last committed, the highest at or below that height. The height
 * can also lie between two checkpoints, where SyncProgress saves it while a range holds no command
 * yet: the tree is still as that checkpoint left it then.
 *
 * @param int|null $stateBlock
 * @param Database $db
 * @return string the root in hex, or '' when there is none to compare a proof's root with
 * @throws RuntimeException
 */
function resolveStateRoot(?int $stateBlock, Database $db): string
{
    if ($stateBlock === null) {
        return '';
    }
    $qr = $db->doSelect(
        'checkpoints',
        ['smt_root'],
        ['block_id' => ['sign' => '<=', 'value' => $stateBlock]],
        'ORDER BY `block_id` DESC LIMIT 1'
    );
    if ($qr === false) {
        throw new RuntimeException(API_ERROR_INTERNAL, INT_EXC_DB);
    }

    return $qr->fetch_assoc()['smt_root'] ?? '';
}

/**
 * @var array $uri
 * @var Database $db
 */

$lookupType = 'name';
if (!Protocol::isDomainNameValid($domainName = strtolower($uri[2] ?? ''))) {
    if (!isset($uri[3]) || !in_array($uri[2], ['reverse', 'owner'], true) ||
        // Validate $uri[3] - the actual value. $domainName still holds
        // strtolower($uri[2]) here, i.e. the literal word "reverse"/"owner"; the
        // destructuring that loads the real value runs only after this guard passes.
        // Neither value is lowercased on the way in, and neither should be: both
        // validators require canonical lowercase form (see isAddressWellFormed and
        // isValidEd25519Pubkey), which is what keeps the host and the circuit
        // agreeing on the bytes.
        ($uri[2] === 'reverse' && !Protocol::isAddressValid($uri[3])) ||
        ($uri[2] === 'owner' && !Protocol::isValidEd25519Pubkey($uri[3]))) {
        throw new RuntimeException(API_ERROR_WRONG_INCOMING_PARAM_VALUE, INT_EXC_API_ERROR);
    }
    [,,$lookupType, $domainName] = $uri;
}

$qAdd = '';
$request = [
    'extended' => false,
    'with_checkpoint' => false,
    // Reverse and owner lookups only: a page of at most `count` names, in name order, starting
    // after the name `after` (null: from the first).
    'count' => API_RESOLVE_MAX_COUNT,
    'after' => null,
];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($raw = file_get_contents('php://input')) !== '') {
        $input = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
    }
    $qAdd = '';
    if (isset($input['extended']) && $input['extended'] === true) {
        $request['extended'] = true;
        $qAdd .= ', `domains`.`created_block_id`, `domains_history`.`domain_tx`';
    }

    if (isset($input['with_checkpoint']) && $input['with_checkpoint'] === true) {
        $request['with_checkpoint'] = true;
    }

    if ($request['extended'] === true || $request['with_checkpoint'] === true) {
        $qAdd .= ', `domains`.`updated_block_id`';
    }

    if ($lookupType !== 'name') {
        // Paging for the list lookups. Every name in the answer costs a Merkle proof, so one
        // answer was as long as the list: an owner with N names, or N names pointed at one
        // address, cost N proofs per request.
        $request['count'] = getParamCount($input['count'] ?? 0, API_RESOLVE_MAX_COUNT, API_RESOLVE_MAX_COUNT);
        if (isset($input['after'])) {
            if (!is_string($input['after']) || !Protocol::isDomainNameValid($input['after'])) {
                throw new RuntimeException(API_ERROR_WRONG_INCOMING_PARAM_VALUE, INT_EXC_API_ERROR);
            }
            $request['after'] = $input['after'];
        }
    }
}

$where = match ($lookupType) {
    'name' => ' `domains`.`domain_name` = {:target:}',
    'reverse' => ' `domains_history`.`target_address` = {:target:}',
    'owner' => ' `domains_history`.`owner_pubkey` = {:target:}',
    default => throw new RuntimeException(API_ERROR_INTERNAL)
};
$map = ['{:target:}' => $domainName];

// The list lookups answer one page, in name order. Both values are validated above, so the
// cursor cannot carry a placeholder of its own into the query.
$qPage = '';
if ($lookupType !== 'name') {
    if ($request['after'] !== null) {
        $where .= ' AND `domains`.`domain_name` > {:after:}';
        $map['{:after:}'] = $request['after'];
    }
    $qPage = ' ORDER BY `domains`.`domain_name` LIMIT ' . $request['count'];
}

$rocksDb = new RustRocksDBProxy(INDEXER_ROCKSDB_PROXY_SOCKET_PATH);
$smt = new SparseMerkleTree($rocksDb);

// The names come from MySQL and their proofs from the tree, two reads of two stores. A sync
// commits the tree first and MySQL right after it, so a lookup landing between the two could pair
// the fields of one state with a proof made in the next, which no client can verify. The rows are
// read together with the height they were committed at (one statement, so one snapshot), and the
// proofs are used only if their root is the root of the checkpoint the sync last committed at or
// below that height (see resolveStateRoot()). Otherwise a commit is landing right then, and the
// lookup is made again a moment later.
for ($attempt = 1; ; $attempt++) {
    $qr = $db->doPlainQuery(
        'SELECT `domains`.`domain_name`, `domains_history`.`target_address`, `domains_history`.`owner_pubkey`,
                `domains_history`.`price`, `domains`.`nonce`' . $qAdd . ',
                (SELECT `value` FROM `params` WHERE `param` = {:state_param:}) AS `state_block`
        FROM `domains`
        LEFT JOIN `domains_history` ON `domains_history`.`domain_name` = `domains`.`domain_name`
                                    AND `domains_history`.`domain_block_id` = `domains`.`updated_block_id`
                                    AND `domains_history`.`nonce` = `domains`.`nonce`
        WHERE ' . $where . $qPage,
        $map + ['{:state_param:}' => PARAM_LAST_PROCESSED_COIN_BLOCK]
    );

    if ($qr === false) {
        throw new RuntimeException(API_ERROR_INTERNAL, INT_EXC_DB);
    }
    if ($qr->num_rows === 0) {
        // A page past the last name is an empty list, not a lookup that found nothing.
        if ($request['after'] !== null) {
            apiAnswer('response', []);
        }
        throw new RuntimeException(API_ERROR_NOT_FOUND, INT_EXC_API_ERROR);
    }

    $dbData = $qr->fetch_all(MYSQLI_ASSOC);
    // The same in every row; not a field of the answer.
    $stateBlock = $dbData[0]['state_block'];
    foreach (array_keys($dbData) as $i) {
        unset($dbData[$i]['state_block']);
    }

    // Fetch every proof and the root it was built from in a single call. Each proof and its root
    // come from one database snapshot, so a commit landing mid-request can no longer return a proof
    // that fails to verify against the root beside it. The whole list comes from one snapshot too,
    // in one round trip to rr-proxy where it took one per name; an rr-proxy too old for that is
    // asked name by name as before (see RustRocksDBProxy::smtProofs()).
    $smtStates = $smt->getProofsWithRoot(array_column($dbData, 'domain_name'));

    $stateRoot = resolveStateRoot($stateBlock, $db);
    $matched = true;
    foreach ($smtStates as $smtState) {
        if (bin2hex(pack('C*', ...$smtState['root'])) !== $stateRoot) {
            $matched = false;
            break;
        }
    }
    if ($matched) {
        break;
    }
    if ($attempt >= API_RESOLVE_STATE_ATTEMPTS) {
        // Still apart after the retries: a sync that broke off between its two commits leaves the
        // tree ahead of MySQL until its next run repairs that. Nothing provable to answer with.
        throw new RuntimeException(API_ERROR_STATE_CHANGING, INT_EXC_API_ERROR);
    }
    usleep(API_RESOLVE_STATE_RETRY_USEC);
}

$out = [];
// The checkpoint found for each root already looked up in this request. A list of names is
// usually proved against one root, and looking it up again for every name cost one query each.
$checkpointByRoot = [];
foreach ($dbData as $i => $oneDomain) {
    $out[] = $oneDomain;
    $index = array_key_last($out);
    $smtState = $smtStates[$i];

    // Convert the raw bytes to a clean JSON array of Hex strings for the API.
    //
    // The tree is compact now, so a proof is variable length and the sibling list
    // alone is no longer enough to verify one: a verifier also needs the depth and
    // the terminal kind. `merkle_proof` keeps its existing name and shape so current
    // consumers are unaffected, and the fields beside it carry the rest.
    $hexProof = [];
    foreach ($smtState['proof']['siblings'] as $siblingBytes) {
        // Pack the bytes back into a string, then convert to hex
        $hexProof[] = bin2hex(pack('C*', ...$siblingBytes));
    }
    // The root the proof was built against, in every answer. It used to be left out whenever
    // `with_checkpoint` was asked for, on the grounds that the checkpoint carries it - but the
    // checkpoint can be null (see below), and the test for it read the raw input while the one
    // below read $request, so `"with_checkpoint": 1` returned neither.
    $out[$index] += [
        'smt_root' => bin2hex(pack('C*', ...$smtState['root'])),
        'merkle_proof' => $hexProof,
        'proof_depth' => $smtState['proof']['depth'],
        // "Occupied" or "Vacant", or {"Blocked":{"record":{…}}} when another domain
        // holds this slot in the compact tree.
        'proof_terminal' => $smtState['proof']['terminal'],
    ];

    if ($request['with_checkpoint']) {
        // The checkpoint whose root this proof was built against. Not simply the newest one:
        // discovery stores checkpoints before the sync replays them, so the newest can lie
        // ahead of the tree, and a proof checked against its root fails. The tree only ever
        // stops on an anchored root, so a matching checkpoint exists whenever it is at rest;
        // null means a sync is committing right now and the request is worth repeating.
        $rootHex = $out[$index]['smt_root'];
        if (!array_key_exists($rootHex, $checkpointByRoot)) {
            $qr = $db->doSelect(
                'checkpoints',
                ['idx', 'block_id', 'smt_root'],
                ['smt_root' => $rootHex],
                'ORDER BY `idx` DESC LIMIT 1',
            );
            if ($qr === false) {
                throw new RuntimeException(API_ERROR_INTERNAL, INT_EXC_DB);
            }
            $checkpointByRoot[$rootHex] = $qr->fetch_assoc();
        }
        $out[$index]['checkpoint'] = $checkpointByRoot[$rootHex];
    }
}

apiAnswer('response', $lookupType !== 'name' ? $out : $out[0]);
