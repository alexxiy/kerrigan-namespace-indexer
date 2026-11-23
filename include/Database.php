<?php declare(strict_types=1);

namespace Indexer;

use mysqli;
use mysqli_result;
use mysqli_sql_exception;

class Database
{
    private array $dbLink = [];

    private const array CONN_ERROR_CODES = [
        2002, // Can't connect to local MySQL server through socket '%s' (%d)
        2003, // Can't connect to MySQL server on '%s' (%d)
        2006, // MySQL server has gone away
        2011, // %s via TCP/IP
        2012, // Error in server handshake
        2013, // Lost connection to MySQL server during query
        2048, // Invalid connection handle
        2055, // Lost connection to MySQL server at '%s', system error: %d
        1213, // Deadlock happened - need to retry,
        7777, // We throw this exception on mysqli_options = false
    ];

    private bool $persistent;

    private array $tx_id = [];
    private const int XA_TX_STATUS_BLANK = 0;
    private const int XA_TX_STATUS_START = 1;
    private const int XA_TX_STATUS_END = 2;
    private const int XA_TX_STATUS_PREPARE = 3;
    private const int XA_TX_STATUS_COMMIT = 4;
    private const array XA_TX_FINALIZE_STAGES = [
        self::XA_TX_STATUS_END => 'END',
        self::XA_TX_STATUS_PREPARE => 'PREPARE',
        self::XA_TX_STATUS_COMMIT => 'COMMIT'
    ];

    public int $lastError = 0;

    private array $config;

    /**
     * @param bool $persistent
     * @param array|null $config the connection (host, user, pass, db_name): DB_CONFIG unless given
     */
    public function __construct(bool $persistent = false, ?array $config = null)
    {
        $this->persistent = $persistent;
        $this->config = $config ?? DB_CONFIG;
    }

    public function setPersistent(bool $persistent): void
    {
        if ($this->persistent !== $persistent) {
            $this->persistent = $persistent;
            $this->free();
        }
    }

    private function connect(int $retries = 2): bool
    {
        try {
            $link = mysqli_connect(
                ($this->persistent ? 'p:' : '') . $this->config['host'],
                $this->config['user'],
                $this->config['pass'],
                $this->config['db_name']
            );
            if ($link === false) {
                throw new mysqli_sql_exception('mysqli_connect returned false', 7777);
            }

            mysqli_options(
                $link,
                MYSQLI_OPT_INT_AND_FLOAT_NATIVE,
                1
            ) or throw new mysqli_sql_exception('mysqli_options returned false', 7777);
            mysqli_set_charset(
                $link,
                'utf8mb4'
            ) or throw new mysqli_sql_exception('mysqli_set_charset returned false', 7777);
            mysqli_query(
                $link,
                'SET NAMES utf8mb4'
            ) or throw new mysqli_sql_exception('mysqli_query set names returned false', 7777);

            $this->dbLink = [
                'link' => $link,
                'autocommit' => true,
                'xa_tx_status' => self::XA_TX_STATUS_BLANK,
                'xa_has_changes' => false
            ];

            return true;
        } catch (mysqli_sql_exception $e) {
            $this->dbErrorLog($this->dbDebug('CONNECT TO DATABASE: ' . $this->config['db_name'], $e));
            if ($retries > 0 && in_array($e->getCode(), self::CONN_ERROR_CODES, true)) {
                usleep(500000);
                return $this->connect($retries - 1);
            }
        }

        return false;
    }

    private function xaTXQuery(string $q): bool
    {
        try {
            if (mysqli_query($this->dbLink['link'], $q) === false) {
                throw new mysqli_sql_exception('xaTXQuery returned false', 7777);
            }
        } catch (mysqli_sql_exception $e) {
            $this->dbErrorLog($this->dbDebug($q, $e));
            $this->lastError = $e->getCode();
            return false;
        }

        return true;
    }

    private function formatXATX(): string
    {
        return '\'' . implode('\', \'', $this->tx_id) . '\'';
    }

    public function xaTXStart(array $tx_id = []): bool
    {
        if ($this->dbLink === [] && !$this->connect()) {
            return false;
        }

        if ($this->dbLink['xa_tx_status'] === self::XA_TX_STATUS_BLANK) {
            if ($this->tx_id === []) {
                if ($tx_id === []) {
                    $this->tx_id = ['XA-' . microtime(true) . random_int(1, 100000), ''];
                } else {
                    $tx_id[1] = PROJECT_NAME . '-' . $tx_id[1];
                    $this->tx_id = $tx_id;
                }
            }

            if (!$this->xaTXQuery('XA START ' . $this->formatXATX())) {
                return false;
            }
            $this->dbLink['xa_tx_status'] = self::XA_TX_STATUS_START;
        } elseif ($this->dbLink['xa_tx_status'] === self::XA_TX_STATUS_START) {
            return true;
        } else {
            $this->dbErrorLog('Trying to START XA TX with current XT status: ' . $this->dbLink['xa_tx_status']);
            return false;
        }

        return true;
    }

    public function xaTXFinalize(array $stages = self::XA_TX_FINALIZE_STAGES, bool $skipCommit = false): bool
    {
        // No connection (released by a stage that failed): no transaction of it is left to finalize.
        if (($this->dbLink['xa_tx_status'] ?? self::XA_TX_STATUS_BLANK) !== self::XA_TX_STATUS_BLANK) {
            foreach ($stages as $statusID => $queryCommand) {
                if ($skipCommit && $statusID === self::XA_TX_STATUS_COMMIT) {
                    return true;
                }

                if ($statusID === self::XA_TX_STATUS_PREPARE && $this->dbLink['xa_has_changes'] === false) {
                    $statusID = self::XA_TX_STATUS_BLANK;
                    $queryCommand = 'ROLLBACK';
                }
                if (!$this->xaTXQuery('XA ' . $queryCommand . ' ' . $this->formatXATX())) {
                    if ($statusID === self::XA_TX_STATUS_COMMIT) {
                        //setFallback('db_tx', 'COMMIT^' . '^' . $this->tx_id);
                        // Not committed: the transaction stays PREPARED on the server past this
                        // connection, holding its locks, until checkUncommitedXATX() settles it at
                        // the start of the next run. The caller is told, and stops: what it went on
                        // to write would wait on those locks, and fail as another error. Its id is
                        // not this object's any more: the next transaction takes the one it is given.
                        $this->free();
                        $this->tx_id = [];
                        return false;
                    } elseif ($this->dbLink['xa_has_changes']) {
                        // if an error is occurred on eding/preparing XA TX which has changes - rollback everything
                        $this->xaHandleRollback();
                        return false;
                    }

                    // if error is occurred on ending XA TX which has NO changes (shouldn't have ones, but anyway)
                    // just release the connection, so mysql will not hold that TX state on the opened connection
                    $this->free();
                    // Nothing is left to finalize: a next stage would run on no connection.
                    break;
                }

                if ($statusID === self::XA_TX_STATUS_COMMIT) {
                    $this->dbLink['xa_tx_status'] = self::XA_TX_STATUS_BLANK;
                    $this->dbLink['xa_has_changes'] = false;
                } else {
                    $this->dbLink['xa_tx_status'] = $statusID;
                    if ($statusID === self::XA_TX_STATUS_BLANK) {
                        $this->dbLink['xa_has_changes'] = false;
                        // Rolled back, having no changes: nothing is left to commit, and an XA
                        // COMMIT of it would fail.
                        break;
                    }
                }
            }
        }
        $this->tx_id = [];
        return true;
    }

    public function xaTXCommit(): bool
    {
        return $this->xaTXFinalize([
            self::XA_TX_STATUS_COMMIT => 'COMMIT'
        ]);
    }

    public function xaHandleRollback(): void
    {
        if ($this->dbLink['xa_tx_status'] === self::XA_TX_STATUS_PREPARE ||
            ($this->dbLink['xa_tx_status'] !== self::XA_TX_STATUS_BLANK && $this->dbLink['xa_has_changes'])) {
            if ($this->xaTXQuery('XA ROLLBACK ' . $this->formatXATX())) {
                $this->dbLink['xa_tx_status'] = self::XA_TX_STATUS_BLANK;
                $this->dbLink['xa_has_changes'] = false;
            } else {
                //if ($this->dbLink['xa_tx_status'] === self::XA_TX_STATUS_PREPARE) {
                //    setFallback('db_tx', 'ROLLBACK^' . '^' . $this->tx_id);
                //}
                $this->free();
            }
        }
        $this->tx_id = [];
    }

    public function getAutoCommit(): bool
    {
        return $this->dbLink['autocommit'] ?? false;
    }

    public function autocommit(bool $autoCommit): bool
    {
        if ($this->dbLink === [] && !$this->connect()) {
            return false;
        }

        if ($this->dbLink['autocommit'] === $autoCommit) {
            return true;
        }

        try {
            mysqli_autocommit(
                $this->dbLink['link'],
                $autoCommit
            ) or throw new mysqli_sql_exception('mysql_autocommit returned false', 7777);
            $this->dbLink['autocommit'] = $autoCommit;
            return true;
        } catch (mysqli_sql_exception $e) {
            $this->dbErrorLog($this->dbDebug('AUTOCOMMIT', $e));
        }

        return false;
    }

    // Need to think how to prevent duplicate method call on already commited/rollbacked
    // ATM rely on my logic
    public function commit(): bool
    {
        try {
            if ($this->dbLink === []) {
                throw new mysqli_sql_exception('mysqli_commit on non-existent connection', 7777);
            }
            mysqli_commit($this->dbLink['link'])
            or throw new mysqli_sql_exception('mysqli_commit returned false', 7777);
            return true;
        } catch (mysqli_sql_exception $e) {
            $this->dbErrorLog($this->dbDebug('COMMIT', $e));
        }

        return false;
    }

    // Need to think how to prevent duplicate method call on already commited/rollbacked
    // ATM rely on my logic
    public function rollback(): bool
    {
        try {
            if ($this->dbLink === []) {
                throw new mysqli_sql_exception('mysqli_rollback on non-existent connection', 7777);
            }
            mysqli_rollback($this->dbLink['link'])
            or throw new mysqli_sql_exception('mysqli_rollback returned false', 7777);
            return true;
        } catch (mysqli_sql_exception $e) {
            $this->dbErrorLog($this->dbDebug('ROLLBACK', $e));
        }

        return false;
    }

    public function query(string $q, bool $checkConnect = true, int $retries = 2): mysqli_result|bool
    {
        if ($checkConnect && $this->dbLink === [] && !$this->connect()) {
            return false;
        }

        // security check for XA TX
        if ($this->dbLink['xa_tx_status'] > self::XA_TX_STATUS_START) {
            $this->dbErrorLog('Trying to query to XA TX on END or PREPARED TX with current XT status: ' . $this->dbLink['xa_tx_status']);
            return false;
        }

        try {
            $result = mysqli_query($this->dbLink['link'], $q);
            if ($result === false) {
                throw new mysqli_sql_exception('mysqli_query returned false', 7777);
            }
            if ($this->dbLink['xa_tx_status'] === self::XA_TX_STATUS_START &&
                $result === true && $this->affectedRows() > 0) {
                $this->dbLink['xa_has_changes'] = true;
            }
            $this->lastError = 0;
            return $result;
        } catch (mysqli_sql_exception $e) {
            $this->lastError = $e->getCode();
            if ($this->lastError !== 3572) {
                $this->dbErrorLog($this->dbDebug($q, $e));
                if ($this->dbLink['autocommit'] &&
                    $this->dbLink['xa_tx_status'] === self::XA_TX_STATUS_BLANK &&
                    $retries > 0 && in_array($e->getCode(), self::CONN_ERROR_CODES, true)) {
                    $this->free();
                    return $this->query($q, true, $retries - 1);
                }
            }
        }

        return false;
    }

    public function fetchAssoc(mysqli_result $res, bool $all = false): array
    {
        $result = [];

        if ($all === false) {
            $result = mysqli_fetch_assoc($res);
            return $result ?? [];
        }

        while ($r = mysqli_fetch_assoc($res)) {
            $result[] = $r;
        }
        return $result;
    }

    private function getQueryValue($value, string $ending = '', bool $compareSign = false): string
    {
        if ($value === null) {
            return ($compareSign === false ? '' :' IS ') . 'NULL' . $ending;
        }
        if (is_int($value) || is_float($value)) {
            return ($compareSign === false ? '' : ' = ') . $value . $ending;
        }
        if (is_bool($value)) {
            return ($compareSign === false ? '' : ' = ') . ($value === false ? 'false' : 'true') . $ending;
        }

        if (is_array($value)) {
            if (isset($value['sign']) && array_key_exists('value', $value)) {
                if ($value['sign'] === 'BETWEEN') {
                    foreach ($value['value'] as &$oneValue) {
                        $oneValue = $this->getQueryValue($oneValue);
                    }
                    return ' ' . $value['sign'] . ' ' . implode(' AND ', $value['value']) . $ending;
                }
                return ' ' . $value['sign'] . ' ' . $this->getQueryValue($value['value'], $ending);
            }
            $result = ($compareSign === false ? '' : ' IN') . ' (';
            foreach ($value as $oneVal) {
                $result .= $this->getQueryValue($oneVal) . ',';
            }
            return mb_substr($result, 0, -1) . ')' . $ending;
        }

        return ($compareSign === false ? '' : ' = ') . '\'' . mysqli_real_escape_string($this->dbLink['link'], $value) . '\'' . $ending;
    }

    private function processWhere(string &$q, array $where = []): void
    {
        if ($where !== []) {
            $q .= ' WHERE ';
            foreach ($where as $key => $value) {
                $q .= ' `' . $key . '`' . $this->getQueryValue($value, ' AND', true);
            }
            $q = mb_substr($q, 0, -3);
        }
    }

    public function doInsert(string $dbTable, array $data, bool $ignore = false, array $dup = [], string $extra = ''): mysqli_result|bool
    {
        if ($this->dbLink === [] && !$this->connect()) {
            return false;
        }

        // Each list is collected and joined once: cutting a trailing comma off the growing string
        // with mb_substr() scanned all of it again each time.
        $q = 'INSERT ' . ($ignore ? 'IGNORE ' : '') . 'INTO ' . $dbTable . ' (`' . implode('`,`', array_keys($data)) . '`) ';
        $values = [];
        foreach ($data as $value) {
            $values[] = $this->getQueryValue($value);
        }
        $v = 'VALUES (' . implode(',', $values) . ') ';

        if ($dup !== []) {
            $updates = [];
            foreach ($dup as $val) {
                $updates[] = $dbTable . '.`' . $val . '` = `sysdbins`.`' . $val . '`';
            }

            if ($extra !== '') {
                $updates[] = $extra;
            }

            $v .= 'AS `sysdbins` ON DUPLICATE KEY UPDATE ' . implode(',', $updates);
        } elseif ($extra !== '') {
            $v .= 'AS `sysdbins` ON DUPLICATE KEY UPDATE ' . $extra;
        }

        return $this->query($q . $v, false);
    }

    public function doBulkInsert(string $dbTable, array $data, bool $ignore = false, $dup = [], string $extra = ''): mysqli_result|bool
    {
        if ($this->dbLink === [] && !$this->connect()) {
            return false;
        }

        $q = 'INSERT ' . ($ignore ? 'IGNORE ':'') . 'INTO ' . $dbTable . ' (`' . implode('`,`', array_keys(current($data))) . '`) ';

        // The rows are collected and joined once: cutting a trailing comma off the growing VALUES
        // with mb_substr() after each row scanned all of it again, so a bulk insert of n rows (the
        // history of a full sync) took time in n². It also turned each invalid UTF-8 byte of the
        // statement into '?'; values now go as given, escaped (none of the callers' is binary).
        $rows = [];
        foreach ($data as $oneInsert) {
            $values = [];
            foreach ($oneInsert as $value) {
                $values[] = $this->getQueryValue($value);
            }
            $rows[] = '(' . implode(',', $values) . ')';
        }
        $v = 'VALUES ' . implode(',', $rows);

        if ($dup !== []) {
            $updates = [];
            foreach ($dup as $val) {
                $updates[] = '`' . $dbTable . '`.`' . $val . '` = `sysdbins`.`' . $val . '`';
            }

            $v .= ' AS `sysdbins` ON DUPLICATE KEY UPDATE ' . implode(',', $updates);
        } elseif ($extra !== '') {
            $v .= ' AS `sysdbins` ON DUPLICATE KEY UPDATE ' . $extra;
        }

        return $this->query($q . $v, false);
    }

    public function doUpdate(string $dbTable, array $data, array $where, bool $ignore = false, string $extra = ''): mysqli_result|bool
    {
        if ($this->dbLink === [] && !$this->connect()) {
            return false;
        }

        $q = 'UPDATE ' . ($ignore ? 'IGNORE ':'') . $dbTable . ' SET ';
        $w = $where !== [] ? ' WHERE' : '';

        foreach ($data as $key => $value) {
            $q .= '`' . $key . '` = ' . $this->getQueryValue($value, ',');
        }

        foreach ($where as $key => $value) {
            $w .= ' `' . $key . '`' . $this->getQueryValue($value, ' AND', true);
        }

        $q = mb_substr($q, 0, -1);
        $w = mb_substr($w, 0, -3);

        if ($extra !== '') {
            $w .= ' ' . $extra;
        }

        return $this->query($q . $w, false);
    }

    public function doSelect(string $dbTable, array|string $fields, array $where = [], string $extra = ''): mysqli_result|bool
    {
        if ($this->dbLink === [] && !$this->connect()) {
            return false;
        }

        $q = 'SELECT ' . (is_array($fields) ? '`' . implode('`,`', $fields) . '`' : $fields) . ' FROM `' . $dbTable . '`';
        $this->processWhere($q, $where);

        if ($extra !== '') {
            $q .= ' ' . $extra;
        }

        return $this->query($q, false);
    }

    public function doPlainQuery(string $q, array $map = []): mysqli_result|bool
    {
        if ($this->dbLink === [] && !$this->connect()) {
            return false;
        }

        // One pass: a value put in is never searched again, so a value holding the name of another
        // placeholder stays as it is. Replaced one by one, it got that placeholder's value inside
        // its own quotes.
        $q = strtr($q, array_map(fn($value) => $this->getQueryValue($value), $map));

        return $this->query($q, false);
    }

    public function doDelete(string $dbTable, array $where, string $extra = ''): mysqli_result|bool
    {
        if ($this->dbLink === [] && !$this->connect()) {
            return false;
        }

        $q = 'DELETE FROM `' . $dbTable . '`';
        $this->processWhere($q, $where);

        if ($extra !== '') {
            $q .= ' ' . $extra;
        }

        return $this->query($q, false);
    }

    public function insertId(): int
    {
        return mysqli_insert_id($this->dbLink['link']);
    }

    public function affectedRows(): int
    {
        return mysqli_affected_rows($this->dbLink['link']);
    }

    public function free(): void
    {
        if ($this->dbLink !== [] && $this->dbLink['link'] instanceof mysqli) {
            mysqli_close($this->dbLink['link']);
        }
        $this->dbLink = [];
    }

    private function dbDebug(string $q, ?mysqli_sql_exception $e = null): string
    {
        $backtrace = debug_backtrace();
        array_shift($backtrace); // remove self call
        $output = 'Stack:';
        foreach ($backtrace as $call) {
            $output .= "\n - ";
            if (isset($call['file'])) {
                $output .= basename($call['file']) . ', line '. $call['line'] . ': ';
            }

            if (isset($call['object']) &&
                method_exists($call['object'], '__toString')) {
                $output .= $call['object'];
            }

            if (isset($call['type'])) {
                if ($call['type'] === '->') {
                    $output .= $call['class'] . '->';
                } elseif ($call['type'] === '::') {
                    $output .= $call['class'] . '::';
                }
            }

            $output .= $call['function'] . '(';

            $strArgs = '';
            foreach ($call['args'] as $arg) {
                if ($arg === null) {
                    $strArgs .= 'null';
                } elseif (is_bool($arg)) {
                    $strArgs .= $arg ? 'true' : 'false';
                } elseif (is_string($arg)) {
                    $strArgs .= '"' . $arg . '"';
                } elseif (is_int($arg) || is_float($arg)) {
                    $strArgs .= $arg;
                } elseif (is_array($arg)) {
                    $strArgs .= 'array (' . count($arg) . ')';
                } elseif (is_object($arg)) {
                    $strArgs .= 'object (' . get_class($arg) . ')';
                } elseif (is_resource($arg)) {
                    $strArgs .= 'resource (' . get_resource_type($arg) . ')';
                }
                $strArgs .= ', ';
            }

            $strArgs = mb_substr($strArgs, 0, -2);
            $output .= $strArgs . ')';
        }

        if ($e !== null) {
            $output .= "\nError: " . $e->getMessage() . "\nError Code: " . $e->getCode() . "\nQuery: " . $q . "\n";
        }

        return $output;
    }

    private function dbErrorLog(string $str): void
    {
        $fp = fopen(WORKLOGS_PATH . '/' . 'error_db.log', 'ab');
        fwrite(
            $fp,
            "------" . date('Y-m-d H:i:s') . "------------------\n" . $str . "\n"
        );
        fclose($fp);
    }
}
