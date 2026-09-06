<?php

namespace ATL\RDBMS;

interface IMySQLi
{
    # additional supported query generation parameters
    const RDBMS_MYSQL_FROM_PREPEND          = 201;
    const RDBMS_MYSQL_FROM_APPEND           = 202;
    const RDBMS_MYSQL_INTO_PREPEND          = 203;
    const RDBMS_MYSQL_INTO_APPEND           = 204;
    const RDBMS_MYSQL_VALUES_PREPEND        = 204;
    const RDBMS_MYSQL_VALUES_APPEND         = 206;
    const RDBMS_MYSQL_SET_PREPEND           = 207;
    const RDBMS_MYSQL_SET_APPEND            = 208;
    const RDBMS_MYSQL_FOR_UPDATE_EXTENSION  = 209;
    const RDBMS_MYSQL_ORDER_EXTENSION       = 210;
    const RDBMS_MYSQL_LIMIT_EXTENSION       = 211;
}

trait TMySQLi
{
    public function escapeNameEntity($name)
    {
        return '`'.$this->database->real_escape_string($name).'`';
    }

    public function escapeValue($value)
    {
        return ($value !== null) ? $this->database->real_escape_string($value) : 'NULL';
    }

    public function escapeStringValue($value)
    {
        return ($value !== null) ? "'".$this->database->real_escape_string($value)."'" : 'NULL';
    }

    public function escapeBinaryValue($value)
    {
        return ($value !== null) ? "X'".bin2hex($value)."'" : 'NULL';
    }

    public function query($query, $unbuffered = false, $expectResult = null, $throwOnError = true)
    {
        $this->addToTransactionManager();
        $result = $this->database->query($query, $unbuffered ? MYSQLI_USE_RESULT : MYSQLI_STORE_RESULT);
        if (($result === false) && $throwOnError) throw new \ATL\RDBMS\DriverException("RDBMS [MySQLi]: ".$this->database->errno." ".$this->database->error);
        if (($expectResult === true) && !is_object($result)) throw new \ATL\RDBMS\DriverException('Expected result but got none from the query');
        if (($expectResult === false) && is_object($result)) throw new \ATL\RDBMS\DriverException('Expected no result but got one from the query');
        return $result;
    }

    public function endQuery($result)
    {
        if (!is_object($result)) throw new \ATL\RDBMS\DriverException('Invalid result object, cannot end it');
        $result->free();
    }

    public function queryRows($query, $callback, $unbuffered = false)
    {
        $this->addToTransactionManager();
        $result = $this->query($query, $unbuffered, true);
        $continue = true;
        while (is_array($row = $result->fetch_assoc())) {
            $continue = $callback($row);
            if ($continue === false) break; # callback requested us to stop
        }
        $result->free();
    }

    public function getInsertID()
    {
        return (($id = $this->database->insert_id) != 0) ? $id : false;
    }

    public function getAffectedRows()
    {
        return (($changes = $this->database->affected_rows) >= 0) ? $changes : false;
    }

    public function startTransaction()
    {
        $result = $this->database->query('START TRANSACTION');
        if ($result === false) throw new \ATL\RDBMS\DriverException("RDBMS [MySQLi]: ".$this->database->errno." ".$this->database->error);
    }

    public function commitTransaction()
    {
        $result = $this->database->query('COMMIT');
        if ($result === false) throw new \ATL\RDBMS\DriverException("RDBMS [MySQLi]: ".$this->database->errno." ".$this->database->error);
    }

    public function rollbackTransaction()
    {
        $result = $this->database->query('ROLLBACK');
        if ($result === false) throw new \ATL\RDBMS\DriverException("RDBMS [MySQLi]: ".$this->database->errno." ".$this->database->error);
    }

    public function mixinSelectForUpdateParameters(&$parameters)
    {
        $parameters[$this::RDBMS_MYSQL_FOR_UPDATE_EXTENSION] = ['FOR UPDATE'];
    }

    public function mixinLimitParameters(&$parameters, $limit)
    {
        if (is_array($limit)) {
            if (count($limit) == 1) {
                # [$count]
                $parameters[$this::RDBMS_MYSQL_LIMIT_EXTENSION] = 'LIMIT '.reset($limit);
            } elseif (count($limit) == 2) {
                # [$start,$count]
                $parameters[$this::RDBMS_MYSQL_LIMIT_EXTENSION] = 'LIMIT '.implode(',', $limit);
            } else {
                throw new \ATL\RDBMS\DriverException("RDBMS [MySQLi] Invalid LIMIT parameters for MySQL");
            }
        } else {
            # $count
            $parameters[$this::RDBMS_MYSQL_LIMIT_EXTENSION] = 'LIMIT '.$limit;
        }
    }

    public function mixinOrderParameters(&$parameters, $order)
    {
        if (is_array($order)) {
            $parameters[$this::RDBMS_MYSQL_ORDER_EXTENSION] = 'ORDER BY '.implode(',', array_map(function ($expr, $sort) {
                return $expr.' '.(is_bool($sort) ? ($sort ? 'ASC' : 'DESC') : $sort);
            }, array_keys($order), $order));
        } else {
            $parameters[$this::RDBMS_MYSQL_ORDER_EXTENSION] = 'ORDER BY '.$order;
        }
    }

    # Transaction\Entity database ping in case database is not transactional
    public function tmTransactionPreCommit($options)
    {
        parent::tmTransactionPreCommit($options);
        if (!$this->isDatabaseTransactional)
            if (!$this->database->ping())
                throw new \ATL\RDBMS\DriverException("RDBMS [MySQLi]: ".$this->database->errno." ".$this->database->error);
    }

    # Asynchronous query Task handler
    # returns mysql_query result: mysqli_result object (if applicable) or true on success, false on non-timeout failures (or throws exceptions as desired)
    # in case Task has terminated prematurely or timed out, returns null
    # take care entire MySQLi connection is blocked during asynchronous query execution so you cannot use it for other queries while this Task is running
    public function asyncQueryTask($taskObject, $query, $unbuffered = false, $expectResult = null, $timeout = null, $throwOnError = true)
    {
        $this->addToTransactionManager();

        if (!($result = mysqli_query($this->database, $query, ($unbuffered ? MYSQLI_USE_RESULT : MYSQLI_STORE_RESULT) | MYSQLI_ASYNC))) {
            # failed to query before polling starts
            if ($throwOnError) throw new \ATL\RDBMS\DriverException("RDBMS [MySQLi]: ".$this->database->errno." ".$this->database->error);
            return false;
        }

        $timeout = $timeout ?? 86400000; # the default query timeout is insane (1000 days), but we just need some concept of 'infinity' here
        do {
            $read = $error = $reject = [$this->database];
            if (\MySQLi::poll($read, $error, $reject, 0, 0)) {
                if (empty($read) || !empty($error) || !empty($reject)) return false; # failed somehow
                $result = $this->database->reap_async_query();
                if (($expectResult === true) && !is_object($result)) throw new \ATL\RDBMS\DriverException('Expected result but got none from the query');
                if (($expectResult === false) && is_object($result)) throw new \ATL\RDBMS\DriverException('Expected no result but got one from the query');
                if (($result === false) && $throwOnError) throw new \ATL\RDBMS\DriverException("RDBMS [MySQLi]: ".$this->database->errno." ".$this->database->error);
                return $result;
            }
            $timeout -= yield 0; # wait before polling next time
        } while ($timeout > 0);

        if ($throwOnError) throw new \ATL\RDBMS\DriverException("RDBMS [MySQLi]: timed out executing asynchronous query");
    }

    # similar to queryRows, but is asynchronous Task handler using asynchronous query
    # take care that unbuffered queries can still run semi-synchronously, waiting for the next line in result, this cannot be amended
    # callback can return false to stop fetching the result, or can return true to make task relinquish control to scheduler
    # supplementary and is not recommended to use with complex result row handlers, write your own handling Task instead
    public function asyncQueryRowsTask($taskObject, $query, $callback, $unbuffered = false, $timeout = null)
    {
        $this->addToTransactionManager();
        $result = yield new \ATL\Task([$this, 'asyncQueryTask'], $query, $unbuffered, true, $timeout);
        if ($result === null) throw new \ATL\RDBMS\DriverException("RDBMS [MySQLi]: failed to execute asynchronous query"); # can happen if taskTerminate() is used on query task explicitly
        $continue = true;
        while (is_array($row = $result->fetch_assoc())) {
            $continue = $callback($row);
            if ($continue === false) break; # callback requested us to stop
            if ($continue === true) yield true; # callback requested us to relinquish control
        }
        $result->free();
    }
}

class MySQLi extends \ATL\RDBMS\Driver implements \ATL\RDBMS\IMySQLi
{
    use \ATL\RDBMS\TMySQLi;

    # MySQL-specific query templates
    const selectQuery = [
        self::RDBMS_GLOBAL_PREPEND,
        self::RDBMS_SELECT_PREPEND, 'SELECT', self::RDBMS_SELECT_APPEND,
        self::RDBMS_FIELD_EXPRESSIONS_PREPEND, self::RDBMS_FIELD_EXPRESSIONS, self::RDBMS_FIELD_EXPRESSIONS_APPEND,
        self::RDBMS_MYSQL_FROM_PREPEND, 'FROM', self::RDBMS_MYSQL_FROM_APPEND,
        self::RDBMS_TABLE_NAME_PREPEND, self::RDBMS_TABLE_NAME, self::RDBMS_TABLE_NAME_APPEND,
        self::RDBMS_WHERE_PREPEND, 'WHERE', self::RDBMS_WHERE_APPEND,
        self::RDBMS_WHERE_CONDITION_PREPEND, self::RDBMS_WHERE_CONDITION, self::RDBMS_WHERE_CONDITION_APPEND,
        self::RDBMS_MYSQL_ORDER_EXTENSION,
        self::RDBMS_MYSQL_LIMIT_EXTENSION,
        self::RDBMS_MYSQL_FOR_UPDATE_EXTENSION,
        self::RDBMS_GLOBAL_APPEND,
    ];

    const insertQuery = [
        self::RDBMS_GLOBAL_PREPEND,
        self::RDBMS_CREATE_PREPEND, self::RDBMS_INSERT_PREPEND, 'INSERT', self::RDBMS_INSERT_APPEND, self::RDBMS_CREATE_APPEND,
        self::RDBMS_MYSQL_INTO_PREPEND, 'INTO', self::RDBMS_MYSQL_INTO_APPEND,
        self::RDBMS_TABLE_NAME_PREPEND, self::RDBMS_TABLE_NAME, self::RDBMS_TABLE_NAME_APPEND,
        self::RDBMS_FIELD_NAMESET_PREPEND, '(', self::RDBMS_FIELD_NAMES_PREPEND, self::RDBMS_FIELD_NAMES, self::RDBMS_FIELD_NAMES_APPEND, ')', self::RDBMS_FIELD_NAMESET_APPEND,
        self::RDBMS_MYSQL_VALUES_PREPEND, 'VALUES', self::RDBMS_MYSQL_VALUES_APPEND,
        self::RDBMS_FIELD_VALUESET_PREPEND, '(', self::RDBMS_FIELD_VALUES_PREPEND, self::RDBMS_FIELD_VALUES, self::RDBMS_FIELD_VALUES_APPEND, ')', self::RDBMS_FIELD_VALUESET_APPEND,
        self::RDBMS_GLOBAL_APPEND,
    ];

    const multiInsertQuery = [
        self::RDBMS_GLOBAL_PREPEND,
        self::RDBMS_CREATE_PREPEND, self::RDBMS_INSERT_PREPEND, 'INSERT', self::RDBMS_INSERT_APPEND, self::RDBMS_CREATE_APPEND,
        self::RDBMS_MYSQL_INTO_PREPEND, 'INTO', self::RDBMS_MYSQL_INTO_APPEND,
        self::RDBMS_TABLE_NAME_PREPEND, self::RDBMS_TABLE_NAME, self::RDBMS_TABLE_NAME_APPEND,
        self::RDBMS_FIELD_NAMESET_PREPEND, '(', self::RDBMS_FIELD_NAMES_PREPEND, self::RDBMS_FIELD_NAMES, self::RDBMS_FIELD_NAMES_APPEND, ')', self::RDBMS_FIELD_NAMESET_APPEND,
        self::RDBMS_MYSQL_VALUES_PREPEND, 'VALUES', self::RDBMS_MYSQL_VALUES_APPEND,
        self::RDBMS_MULTI_VALUESET_PREPEND, self::RDBMS_MULTI_VALUESET, self::RDBMS_MULTI_VALUESET_APPEND,
        self::RDBMS_GLOBAL_APPEND,
    ];

    const replaceQuery = [
        self::RDBMS_GLOBAL_PREPEND,
        self::RDBMS_CREATE_PREPEND, self::RDBMS_REPLACE_PREPEND, 'REPLACE', self::RDBMS_REPLACE_APPEND, self::RDBMS_CREATE_APPEND,
        self::RDBMS_MYSQL_INTO_PREPEND, 'INTO', self::RDBMS_MYSQL_INTO_APPEND,
        self::RDBMS_TABLE_NAME_PREPEND, self::RDBMS_TABLE_NAME, self::RDBMS_TABLE_NAME_APPEND,
        self::RDBMS_FIELD_NAMESET_PREPEND, '(', self::RDBMS_FIELD_NAMES_PREPEND, self::RDBMS_FIELD_NAMES, self::RDBMS_FIELD_NAMES_APPEND, ')', self::RDBMS_FIELD_NAMESET_APPEND,
        self::RDBMS_MYSQL_VALUES_PREPEND, 'VALUES', self::RDBMS_MYSQL_VALUES_APPEND,
        self::RDBMS_FIELD_VALUESET_PREPEND, '(', self::RDBMS_FIELD_VALUES_PREPEND, self::RDBMS_FIELD_VALUES, self::RDBMS_FIELD_VALUES_APPEND, ')', self::RDBMS_FIELD_VALUESET_APPEND,
        self::RDBMS_GLOBAL_APPEND,
    ];

    const multiReplaceQuery = [
        self::RDBMS_GLOBAL_PREPEND,
        self::RDBMS_CREATE_PREPEND, self::RDBMS_REPLACE_PREPEND, 'REPLACE', self::RDBMS_REPLACE_APPEND, self::RDBMS_CREATE_APPEND,
        self::RDBMS_MYSQL_INTO_PREPEND, 'INTO', self::RDBMS_MYSQL_INTO_APPEND,
        self::RDBMS_TABLE_NAME_PREPEND, self::RDBMS_TABLE_NAME, self::RDBMS_TABLE_NAME_APPEND,
        self::RDBMS_FIELD_NAMESET_PREPEND, '(', self::RDBMS_FIELD_NAMES_PREPEND, self::RDBMS_FIELD_NAMES, self::RDBMS_FIELD_NAMES_APPEND, ')', self::RDBMS_FIELD_NAMESET_APPEND,
        self::RDBMS_MYSQL_VALUES_PREPEND, 'VALUES', self::RDBMS_MYSQL_VALUES_APPEND,
        self::RDBMS_MULTI_VALUESET_PREPEND, self::RDBMS_MULTI_VALUESET, self::RDBMS_MULTI_VALUESET_APPEND,
        self::RDBMS_GLOBAL_APPEND,
    ];

    const updateQuery = [
        self::RDBMS_GLOBAL_PREPEND,
        self::RDBMS_UPDATE_PREPEND, 'UPDATE', self::RDBMS_UPDATE_APPEND,
        self::RDBMS_TABLE_NAME_PREPEND, self::RDBMS_TABLE_NAME, self::RDBMS_TABLE_NAME_APPEND,
        self::RDBMS_MYSQL_SET_PREPEND, 'SET', self::RDBMS_MYSQL_SET_APPEND,
        self::RDBMS_FIELD_CHANGES_PREPEND, self::RDBMS_FIELD_CHANGES, self::RDBMS_FIELD_CHANGES_APPEND,
        self::RDBMS_WHERE_PREPEND, 'WHERE', self::RDBMS_WHERE_APPEND,
        self::RDBMS_WHERE_CONDITION_PREPEND, self::RDBMS_WHERE_CONDITION, self::RDBMS_WHERE_CONDITION_APPEND,
        self::RDBMS_MYSQL_ORDER_EXTENSION,
        self::RDBMS_MYSQL_LIMIT_EXTENSION,
        self::RDBMS_GLOBAL_APPEND,
    ];

    const deleteQuery = [
        self::RDBMS_GLOBAL_PREPEND,
        self::RDBMS_DELETE_PREPEND, 'DELETE', self::RDBMS_DELETE_APPEND,
        self::RDBMS_MYSQL_FROM_PREPEND, 'FROM', self::RDBMS_MYSQL_FROM_APPEND,
        self::RDBMS_TABLE_NAME_PREPEND, self::RDBMS_TABLE_NAME, self::RDBMS_TABLE_NAME_APPEND,
        self::RDBMS_WHERE_PREPEND, 'WHERE', self::RDBMS_WHERE_APPEND,
        self::RDBMS_WHERE_CONDITION_PREPEND, self::RDBMS_WHERE_CONDITION, self::RDBMS_WHERE_CONDITION_APPEND,
        self::RDBMS_MYSQL_ORDER_EXTENSION,
        self::RDBMS_MYSQL_LIMIT_EXTENSION,
        self::RDBMS_GLOBAL_APPEND,
    ];

    const affectedRowsSupported = true;
    const selectForUpdateSupported = true;
    const limitSupported = true;
    const limitStartSupported = true;
    const orderBySupported = true;
    const asynchronousQuerySupported = true;
}
