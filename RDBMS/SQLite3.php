<?php

namespace ATL\RDBMS;

interface ISQLite3
{
    # additional supported query generation parameters
    const RDBMS_SQLITE3_FROM_PREPEND          = 201;
    const RDBMS_SQLITE3_FROM_APPEND           = 202;
    const RDBMS_SQLITE3_INTO_PREPEND          = 203;
    const RDBMS_SQLITE3_INTO_APPEND           = 204;
    const RDBMS_SQLITE3_VALUES_PREPEND        = 204;
    const RDBMS_SQLITE3_VALUES_APPEND         = 206;
    const RDBMS_SQLITE3_SET_PREPEND           = 207;
    const RDBMS_SQLITE3_SET_APPEND            = 208;
    const RDBMS_SQLITE3_ORDER_EXTENSION       = 210;
    const RDBMS_SQLITE3_LIMIT_EXTENSION       = 211;
}

trait TSQLite3
{
    public function escapeNameEntity($name)
    {
        return '"'.strtr($name, ['"' => '""']).'"';
    }

    public function escapeValue($value)
    {
        # as we do not have good escaping routine, we go with check for if it is number and escape to string otherwise
        return ($value !== null) ? $value : (is_numeric($value) ? $value : $this->escapeStringValue($value));
    }

    public function escapeStringValue($value)
    {
        return ($value !== null) ? "'".strtr($value, ["'" => "''"])."'" : 'NULL';
    }

    public function escapeBinaryValue($value)
    {
        return ($value !== null) ? "X'".bin2hex($value)."'" : 'NULL';
    }

    public function query($query, $unbuffered = false, $expectResult = null, $throwOnError = true)
    {
        $this->addToTransactionManager();
        if ($expectResult) {
            $result = $this->database->query($query);
            if (!is_object($result)) throw new \ATL\RDBMS\DriverException('Expected result but got none from the database engine');
        } else {
            $result = $this->database->query($query);
            if ($result->numColumns() > 0) throw new \ATL\RDBMS\DriverException('Expected no result but got one from the query');
            $result->finalize();
            $result = null;
        }
        if ($result === false) throw new \ATL\RDBMS\DriverException("RDBMS [SQLite3]: ".$this->database->lastErrorCode()." ".$this->database->lastErrorMsg());
        return $result;
    }

    public function endQuery($result)
    {
        if (!is_object($result)) throw new \ATL\RDBMS\DriverException('Invalid result object, cannot end it');
        $result->finalize();
    }

    public function queryRows($query, $callback, $unbuffered = false)
    {
        $this->addToTransactionManager();
        $result = $this->query($query, $unbuffered, true);
        if ($result->numColumns() > 0) {
            $continue = true;
            while (is_array($row = $result->fetchArray(SQLITE3_ASSOC))) {
                $continue = $callback($row);
                if ($continue === false) break; # callback requested us to stop
            }
        }
        $result->finalize();
    }

    public function getInsertID()
    {
        return (($id = $this->database->lastInsertRowID()) != 0) ? $id : false;
    }

    public function getAffectedRows()
    {
        return (($changes = $this->database->changes()) >= 0) ? $changes : false;
    }

    public function startTransaction()
    {
        $result = $this->database->query('BEGIN TRANSACTION');
        if ($result === false) throw new \ATL\RDBMS\DriverException("RDBMS [SQLite3]: ".$this->database->lastErrorCode()." ".$this->database->lastErrorMsg());
    }

    public function commitTransaction()
    {
        $result = $this->database->query('COMMIT TRANSACTION');
        if ($result === false) throw new \ATL\RDBMS\DriverException("RDBMS [SQLite3]: ".$this->database->lastErrorCode()." ".$this->database->lastErrorMsg());
    }

    public function rollbackTransaction()
    {
        $result = $this->database->query('ROLLBACK TRANSACTION');
        if ($result === false) throw new \ATL\RDBMS\DriverException("RDBMS [SQLite3]: ".$this->database->lastErrorCode()." ".$this->database->lastErrorMsg());
    }

    public function mixinLimitParameters(&$parameters, $limit)
    {
        if (is_array($limit)) {
            if (count($limit) == 1) {
                # [$count]
                $parameters[$this::RDBMS_SQLITE3_LIMIT_EXTENSION] = 'LIMIT '.reset($limit);
            } elseif (count($limit) == 2) {
                # [$start,$count]
                $parameters[$this::RDBMS_SQLITE3_LIMIT_EXTENSION] = 'LIMIT '.end($limit).' OFFSET '.reset($limit);
            } else {
                throw new \ATL\RDBMS\DriverException("RDBMS [SQLite3] Invalid LIMIT parameters for SQLite3");
            }
        } else {
            # $count
            $parameters[$this::RDBMS_SQLITE3_LIMIT_EXTENSION] = 'LIMIT '.$limit;
        }
    }

    public function mixinOrderParameters(&$parameters, $order)
    {
        if (is_array($order)) {
            $parameters[$this::RDBMS_SQLITE3_ORDER_EXTENSION] = 'ORDER BY '.implode(',', array_map(function ($expr, $sort) {
                return $expr.' '.(is_bool($sort) ? ($sort ? 'ASC' : 'DESC') : $sort);
            }, array_keys($order), $order));
        } else {
            $parameters[$this::RDBMS_SQLITE3_ORDER_EXTENSION] = 'ORDER BY '.$order;
        }
    }

    # Transaction\Entity database ping in case database is not transactional
    public function tmTransactionPreCommit($options)
    {
        parent::tmTransactionPreCommit($options);
        if (!$this->isDatabaseTransactional)
            if ($this->database->querySingle('SELECT 1') !== 1)
                throw new \ATL\RDBMS\DriverException("TRANSACTION PRE-COMMIT [SQLite3]: Failed to ping the SQLite3 enging");
    }
}

class SQLite3 extends \ATL\RDBMS\Driver implements \ATL\RDBMS\ISQLite3
{
    use \ATL\RDBMS\TSQLite3;

    # SQLite3-specific query templates
    const selectQuery = [
        self::RDBMS_GLOBAL_PREPEND,
        self::RDBMS_SELECT_PREPEND, 'SELECT', self::RDBMS_SELECT_APPEND,
        self::RDBMS_FIELD_EXPRESSIONS_PREPEND, self::RDBMS_FIELD_EXPRESSIONS, self::RDBMS_FIELD_EXPRESSIONS_APPEND,
        self::RDBMS_SQLITE3_FROM_PREPEND, 'FROM', self::RDBMS_SQLITE3_FROM_APPEND,
        self::RDBMS_TABLE_NAME_PREPEND, self::RDBMS_TABLE_NAME, self::RDBMS_TABLE_NAME_APPEND,
        self::RDBMS_WHERE_PREPEND, 'WHERE', self::RDBMS_WHERE_APPEND,
        self::RDBMS_WHERE_CONDITION_PREPEND, self::RDBMS_WHERE_CONDITION, self::RDBMS_WHERE_CONDITION_APPEND,
        self::RDBMS_SQLITE3_ORDER_EXTENSION,
        self::RDBMS_SQLITE3_LIMIT_EXTENSION,
        self::RDBMS_GLOBAL_APPEND,
    ];

    const insertQuery = [
        self::RDBMS_GLOBAL_PREPEND,
        self::RDBMS_CREATE_PREPEND, self::RDBMS_INSERT_PREPEND, 'INSERT', self::RDBMS_INSERT_APPEND, self::RDBMS_CREATE_APPEND,
        self::RDBMS_SQLITE3_INTO_PREPEND, 'INTO', self::RDBMS_SQLITE3_INTO_APPEND,
        self::RDBMS_TABLE_NAME_PREPEND, self::RDBMS_TABLE_NAME, self::RDBMS_TABLE_NAME_APPEND,
        self::RDBMS_FIELD_NAMESET_PREPEND, '(', self::RDBMS_FIELD_NAMES_PREPEND, self::RDBMS_FIELD_NAMES, self::RDBMS_FIELD_NAMES_APPEND, ')', self::RDBMS_FIELD_NAMESET_APPEND,
        self::RDBMS_SQLITE3_VALUES_PREPEND, 'VALUES', self::RDBMS_SQLITE3_VALUES_APPEND,
        self::RDBMS_FIELD_VALUESET_PREPEND, '(', self::RDBMS_FIELD_VALUES_PREPEND, self::RDBMS_FIELD_VALUES, self::RDBMS_FIELD_VALUES_APPEND, ')', self::RDBMS_FIELD_VALUESET_APPEND,
        self::RDBMS_GLOBAL_APPEND,
    ];

    const multiInsertQuery = [
        self::RDBMS_GLOBAL_PREPEND,
        self::RDBMS_CREATE_PREPEND, self::RDBMS_INSERT_PREPEND, 'INSERT', self::RDBMS_INSERT_APPEND, self::RDBMS_CREATE_APPEND,
        self::RDBMS_SQLITE3_INTO_PREPEND, 'INTO', self::RDBMS_SQLITE3_INTO_APPEND,
        self::RDBMS_TABLE_NAME_PREPEND, self::RDBMS_TABLE_NAME, self::RDBMS_TABLE_NAME_APPEND,
        self::RDBMS_FIELD_NAMESET_PREPEND, '(', self::RDBMS_FIELD_NAMES_PREPEND, self::RDBMS_FIELD_NAMES, self::RDBMS_FIELD_NAMES_APPEND, ')', self::RDBMS_FIELD_NAMESET_APPEND,
        self::RDBMS_SQLITE3_VALUES_PREPEND, 'VALUES', self::RDBMS_SQLITE3_VALUES_APPEND,
        self::RDBMS_MULTI_VALUESET_PREPEND, self::RDBMS_MULTI_VALUESET, self::RDBMS_MULTI_VALUESET_APPEND,
        self::RDBMS_GLOBAL_APPEND,
    ];

    const replaceQuery = [
        self::RDBMS_GLOBAL_PREPEND,
        self::RDBMS_CREATE_PREPEND, self::RDBMS_REPLACE_PREPEND, 'INSERT OR REPLACE', self::RDBMS_REPLACE_APPEND, self::RDBMS_CREATE_APPEND,
        self::RDBMS_SQLITE3_INTO_PREPEND, 'INTO', self::RDBMS_SQLITE3_INTO_APPEND,
        self::RDBMS_TABLE_NAME_PREPEND, self::RDBMS_TABLE_NAME, self::RDBMS_TABLE_NAME_APPEND,
        self::RDBMS_FIELD_NAMESET_PREPEND, '(', self::RDBMS_FIELD_NAMES_PREPEND, self::RDBMS_FIELD_NAMES, self::RDBMS_FIELD_NAMES_APPEND, ')', self::RDBMS_FIELD_NAMESET_APPEND,
        self::RDBMS_SQLITE3_VALUES_PREPEND, 'VALUES', self::RDBMS_SQLITE3_VALUES_APPEND,
        self::RDBMS_FIELD_VALUESET_PREPEND, '(', self::RDBMS_FIELD_VALUES_PREPEND, self::RDBMS_FIELD_VALUES, self::RDBMS_FIELD_VALUES_APPEND, ')', self::RDBMS_FIELD_VALUESET_APPEND,
        self::RDBMS_GLOBAL_APPEND,
    ];

    const multiReplaceQuery = [
        self::RDBMS_GLOBAL_PREPEND,
        self::RDBMS_CREATE_PREPEND, self::RDBMS_REPLACE_PREPEND, 'INSERT OR REPLACE', self::RDBMS_REPLACE_APPEND, self::RDBMS_CREATE_APPEND,
        self::RDBMS_SQLITE3_INTO_PREPEND, 'INTO', self::RDBMS_SQLITE3_INTO_APPEND,
        self::RDBMS_TABLE_NAME_PREPEND, self::RDBMS_TABLE_NAME, self::RDBMS_TABLE_NAME_APPEND,
        self::RDBMS_FIELD_NAMESET_PREPEND, '(', self::RDBMS_FIELD_NAMES_PREPEND, self::RDBMS_FIELD_NAMES, self::RDBMS_FIELD_NAMES_APPEND, ')', self::RDBMS_FIELD_NAMESET_APPEND,
        self::RDBMS_SQLITE3_VALUES_PREPEND, 'VALUES', self::RDBMS_SQLITE3_VALUES_APPEND,
        self::RDBMS_MULTI_VALUESET_PREPEND, self::RDBMS_MULTI_VALUESET, self::RDBMS_MULTI_VALUESET_APPEND,
        self::RDBMS_GLOBAL_APPEND,
    ];

    const updateQuery = [
        self::RDBMS_GLOBAL_PREPEND,
        self::RDBMS_UPDATE_PREPEND, 'UPDATE', self::RDBMS_UPDATE_APPEND,
        self::RDBMS_TABLE_NAME_PREPEND, self::RDBMS_TABLE_NAME, self::RDBMS_TABLE_NAME_APPEND,
        self::RDBMS_SQLITE3_SET_PREPEND, 'SET', self::RDBMS_SQLITE3_SET_APPEND,
        self::RDBMS_FIELD_CHANGES_PREPEND, self::RDBMS_FIELD_CHANGES, self::RDBMS_FIELD_CHANGES_APPEND,
        self::RDBMS_WHERE_PREPEND, 'WHERE', self::RDBMS_WHERE_APPEND,
        self::RDBMS_WHERE_CONDITION_PREPEND, self::RDBMS_WHERE_CONDITION, self::RDBMS_WHERE_CONDITION_APPEND,
        self::RDBMS_SQLITE3_ORDER_EXTENSION,
        self::RDBMS_SQLITE3_LIMIT_EXTENSION,
        self::RDBMS_GLOBAL_APPEND,
    ];

    const deleteQuery = [
        self::RDBMS_GLOBAL_PREPEND,
        self::RDBMS_DELETE_PREPEND, 'DELETE', self::RDBMS_DELETE_APPEND,
        self::RDBMS_SQLITE3_FROM_PREPEND, 'FROM', self::RDBMS_SQLITE3_FROM_APPEND,
        self::RDBMS_TABLE_NAME_PREPEND, self::RDBMS_TABLE_NAME, self::RDBMS_TABLE_NAME_APPEND,
        self::RDBMS_WHERE_PREPEND, 'WHERE', self::RDBMS_WHERE_APPEND,
        self::RDBMS_WHERE_CONDITION_PREPEND, self::RDBMS_WHERE_CONDITION, self::RDBMS_WHERE_CONDITION_APPEND,
        self::RDBMS_SQLITE3_ORDER_EXTENSION,
        self::RDBMS_SQLITE3_LIMIT_EXTENSION,
        self::RDBMS_GLOBAL_APPEND,
    ];

    const affectedRowsSupported = true;
    const limitSupported = true;
    const limitStartSupported = true;
    const orderBySupported = true;
}
