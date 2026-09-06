<?php

namespace ATL\RDBMS;

interface IDriver
{
    # query generation parts (reserved IDs: 1-99)
    const RDBMS_FIELD_EXPRESSIONS               = 1;
    const RDBMS_FIELD_NAMES                     = 2;
    const RDBMS_FIELD_VALUES                    = 3;
    const RDBMS_FIELD_CHANGES                   = 4;
    const RDBMS_TABLE_NAME                      = 5;
    const RDBMS_WHERE_CONDITION                 = 6;
    const RDBMS_MULTI_VALUESET                  = 7;

    # generally supported query generation parameters (reserved IDs: 100-189, IDs safe to use by drivers: 200-299)
    const RDBMS_GLOBAL_PREPEND                  = 100;
    const RDBMS_GLOBAL_APPEND                   = 101;
    const RDBMS_SELECT_PREPEND                  = 102;
    const RDBMS_SELECT_APPEND                   = 103;
    const RDBMS_CREATE_PREPEND                  = 104;
    const RDBMS_CREATE_APPEND                   = 105;
    const RDBMS_INSERT_PREPEND                  = 106;
    const RDBMS_INSERT_APPEND                   = 107;
    const RDBMS_REPLACE_PREPEND                 = 108;
    const RDBMS_REPLACE_APPEND                  = 109;
    const RDBMS_UPDATE_PREPEND                  = 110;
    const RDBMS_UPDATE_APPEND                   = 111;
    const RDBMS_DELETE_PREPEND                  = 112;
    const RDBMS_DELETE_APPEND                   = 113;
    const RDBMS_WHERE_PREPEND                   = 114;
    const RDBMS_WHERE_APPEND                    = 115;
    const RDBMS_TABLE_NAME_PREPEND              = 116;
    const RDBMS_TABLE_NAME_APPEND               = 117;
    const RDBMS_WHERE_CONDITION_PREPEND         = 118;
    const RDBMS_WHERE_CONDITION_APPEND          = 119;
    const RDBMS_FIELD_EXPRESSIONS_PREPEND       = 120;
    const RDBMS_FIELD_EXPRESSIONS_APPEND        = 121;
    const RDBMS_FIELD_NAMESET_PREPEND           = 122;
    const RDBMS_FIELD_NAMESET_APPEND            = 123;
    const RDBMS_FIELD_NAMES_PREPEND             = 124;
    const RDBMS_FIELD_NAMES_APPEND              = 125;
    const RDBMS_FIELD_VALUESET_PREPEND          = 126;
    const RDBMS_FIELD_VALUESET_APPEND           = 127;
    const RDBMS_FIELD_VALUES_PREPEND            = 128;
    const RDBMS_FIELD_VALUES_APPEND             = 129;
    const RDBMS_FIELD_CHANGES_PREPEND           = 130;
    const RDBMS_FIELD_CHANGES_APPEND            = 131;
    const RDBMS_MULTI_VALUESET_PREPEND          = 132;
    const RDBMS_MULTI_VALUESET_APPEND           = 133;

    # base query template samples (should work at least in MySQL): storage drivers are expected to override that
    const selectQuery = [
        self::RDBMS_GLOBAL_PREPEND,
        self::RDBMS_SELECT_PREPEND, 'SELECT', self::RDBMS_SELECT_APPEND,
        self::RDBMS_FIELD_EXPRESSIONS_PREPEND, self::RDBMS_FIELD_EXPRESSIONS, self::RDBMS_FIELD_EXPRESSIONS_APPEND,
        'FROM',
        self::RDBMS_TABLE_NAME_PREPEND, self::RDBMS_TABLE_NAME, self::RDBMS_TABLE_NAME_APPEND,
        self::RDBMS_WHERE_PREPEND, 'WHERE', self::RDBMS_WHERE_APPEND,
        self::RDBMS_WHERE_CONDITION_PREPEND, self::RDBMS_WHERE_CONDITION, self::RDBMS_WHERE_CONDITION_APPEND,
        self::RDBMS_GLOBAL_APPEND,
    ];

    const insertQuery = [
        self::RDBMS_GLOBAL_PREPEND,
        self::RDBMS_CREATE_PREPEND, self::RDBMS_INSERT_PREPEND, 'INSERT', self::RDBMS_INSERT_APPEND, self::RDBMS_CREATE_APPEND,
        'INTO',
        self::RDBMS_TABLE_NAME_PREPEND, self::RDBMS_TABLE_NAME, self::RDBMS_TABLE_NAME_APPEND,
        self::RDBMS_FIELD_NAMESET_PREPEND, '(', self::RDBMS_FIELD_NAMES_PREPEND, self::RDBMS_FIELD_NAMES, self::RDBMS_FIELD_NAMES_APPEND, ')', self::RDBMS_FIELD_NAMESET_APPEND,
        'VALUES',
        self::RDBMS_FIELD_VALUESET_PREPEND, '(', self::RDBMS_FIELD_VALUES_PREPEND, self::RDBMS_FIELD_VALUES, self::RDBMS_FIELD_VALUES_APPEND, ')', self::RDBMS_FIELD_VALUESET_APPEND,
        self::RDBMS_GLOBAL_APPEND,
    ];

    const multiInsertQuery = [
        self::RDBMS_GLOBAL_PREPEND,
        self::RDBMS_CREATE_PREPEND, self::RDBMS_INSERT_PREPEND, 'INSERT', self::RDBMS_INSERT_APPEND, self::RDBMS_CREATE_APPEND,
        'INTO',
        self::RDBMS_TABLE_NAME_PREPEND, self::RDBMS_TABLE_NAME, self::RDBMS_TABLE_NAME_APPEND,
        self::RDBMS_FIELD_NAMESET_PREPEND, '(', self::RDBMS_FIELD_NAMES_PREPEND, self::RDBMS_FIELD_NAMES, self::RDBMS_FIELD_NAMES_APPEND, ')', self::RDBMS_FIELD_NAMESET_APPEND,
        'VALUES',
        self::RDBMS_MULTI_VALUESET_PREPEND, self::RDBMS_MULTI_VALUESET, self::RDBMS_MULTI_VALUESET_APPEND,
        self::RDBMS_GLOBAL_APPEND,
    ];

    const multiInsertValueset = [
        '(', self::RDBMS_FIELD_VALUES_PREPEND, self::RDBMS_FIELD_VALUES, self::RDBMS_FIELD_VALUES_APPEND, ')',
    ];

    const replaceQuery = [
        self::RDBMS_GLOBAL_PREPEND,
        self::RDBMS_CREATE_PREPEND, self::RDBMS_REPLACE_PREPEND, 'REPLACE', self::RDBMS_REPLACE_APPEND, self::RDBMS_CREATE_APPEND,
        'INTO',
        self::RDBMS_TABLE_NAME_PREPEND, self::RDBMS_TABLE_NAME, self::RDBMS_TABLE_NAME_APPEND,
        self::RDBMS_FIELD_NAMESET_PREPEND, '(', self::RDBMS_FIELD_NAMES_PREPEND, self::RDBMS_FIELD_NAMES, self::RDBMS_FIELD_NAMES_APPEND, ')', self::RDBMS_FIELD_NAMESET_APPEND,
        'VALUES',
        self::RDBMS_FIELD_VALUESET_PREPEND, '(', self::RDBMS_FIELD_VALUES_PREPEND, self::RDBMS_FIELD_VALUES, self::RDBMS_FIELD_VALUES_APPEND, ')', self::RDBMS_FIELD_VALUESET_APPEND,
        self::RDBMS_GLOBAL_APPEND,
    ];

    const multiReplaceQuery = [
        self::RDBMS_GLOBAL_PREPEND,
        self::RDBMS_CREATE_PREPEND, self::RDBMS_REPLACE_PREPEND, 'REPLACE', self::RDBMS_REPLACE_APPEND, self::RDBMS_CREATE_APPEND,
        'INTO',
        self::RDBMS_TABLE_NAME_PREPEND, self::RDBMS_TABLE_NAME, self::RDBMS_TABLE_NAME_APPEND,
        self::RDBMS_FIELD_NAMESET_PREPEND, '(', self::RDBMS_FIELD_NAMES_PREPEND, self::RDBMS_FIELD_NAMES, self::RDBMS_FIELD_NAMES_APPEND, ')', self::RDBMS_FIELD_NAMESET_APPEND,
        'VALUES',
        self::RDBMS_MULTI_VALUESET_PREPEND, self::RDBMS_MULTI_VALUESET, self::RDBMS_MULTI_VALUESET_APPEND,
        self::RDBMS_GLOBAL_APPEND,
    ];

    const multiReplaceValueset = [
        '(', self::RDBMS_FIELD_VALUES_PREPEND, self::RDBMS_FIELD_VALUES, self::RDBMS_FIELD_VALUES_APPEND, ')',
    ];

    const updateQuery = [
        self::RDBMS_GLOBAL_PREPEND,
        self::RDBMS_UPDATE_PREPEND, 'UPDATE', self::RDBMS_UPDATE_APPEND,
        self::RDBMS_TABLE_NAME_PREPEND, self::RDBMS_TABLE_NAME, self::RDBMS_TABLE_NAME_APPEND,
        'SET',
        self::RDBMS_FIELD_CHANGES_PREPEND, self::RDBMS_FIELD_CHANGES, self::RDBMS_FIELD_CHANGES_APPEND,
        self::RDBMS_WHERE_PREPEND, 'WHERE', self::RDBMS_WHERE_APPEND,
        self::RDBMS_WHERE_CONDITION_PREPEND, self::RDBMS_WHERE_CONDITION, self::RDBMS_WHERE_CONDITION_APPEND,
        self::RDBMS_GLOBAL_APPEND,
    ];

    const deleteQuery = [
        self::RDBMS_GLOBAL_PREPEND,
        self::RDBMS_DELETE_PREPEND, 'DELETE', self::RDBMS_DELETE_APPEND,
        'FROM',
        self::RDBMS_TABLE_NAME_PREPEND, self::RDBMS_TABLE_NAME, self::RDBMS_TABLE_NAME_APPEND,
        self::RDBMS_WHERE_PREPEND, 'WHERE', self::RDBMS_WHERE_APPEND,
        self::RDBMS_WHERE_CONDITION_PREPEND, self::RDBMS_WHERE_CONDITION, self::RDBMS_WHERE_CONDITION_APPEND,
        self::RDBMS_GLOBAL_APPEND,
    ];

    # minor flags that can indicate your driver support for specific features
    const replaceSupported = true; # if set to false, replaceQuery loses meaning, and any REPLACE will be emulated by DELETE+INSERT from RDBMS store
    const inSupported = true; # if set to false, IN () query condition optimizations in RDBMS store become impossible
    const autoincrementSupported = true; # if set to false, autoincrement fields become not supported
    const insertIDSupported = true; # if set to false, getting last insert ID is not supported
    const affectedRowsSupported = false; # if set to true, indicates getting affected rows count from queries is supported
    const multiInsertSupported = true; # if set to true, indicates inserting multiple rows by one query is supported
    const multiReplaceSupported = true; # if set to true, indicates replacing multiple rows by one query is supported
    const selectForUpdateSupported = false; # if set to true, indicates transactional SELECT ... FOR UPDATE or its alternative is supported
    const limitSupported = false; # if set to true, indicates LIMIT <count> or its variant is supported
    const limitStartSupported = false; # if set to true, indicates LIMIT is supported with <start>,<count> arguments
    const orderBySupported = false; # if set to true, indicates ORDER BY <field> <ASC|DESC> with field list is supported
    const asynchronousQuerySupported = false; # if set to true, indicates asynchronous query asyncQueryTask()/asyncQueryRowsTask() handlers are supported and provided by the driver
}

trait TDriver
{
    public /** \ATL\Transactional\MySQLi */ $database;
    protected $isDatabaseTransactional = false;

    public function __construct(/** \ATL\Transactional\MySQLi */ $database, /** \ATL\TransactionManager */ $transactionManager = null)
    {
        $this->database = $database ?? $this->database;
        $this->constructTransactionalEntity($transactionManager);

        if ($this->database instanceof \ATL\Transactional\IEntity) {
            # we have transactional database, so we expect it to handle adding itself into transactions and manage transaction state
            if (($this->tmTransactionManager !== null) && ($this->tmTransactionManager !== $this->database->tmGetTransactionManager()))
                throw new \ATL\RDBMS\DriverException("Transactional RDMBS database driver cannot be used with transactional database engine using different transaction manager");
            $this->isDatabaseTransactional = true;
        }
    }

    # different well-known extensions parameters mixins (override in your driver class)
    # $limit can be either direct value or array of single value indicating <count>, or array of two values indicating <start>,<count>
    # $order must be either array of expressions and true/false as values (true means ascending sort order, false means descending sort order), or array of expressions to specific ordering strings, or just a string
    public function mixinSelectForUpdateParameters(&$parameters) { }
    public function mixinLimitParameters(&$parameters, $limit) { }
    public function mixinOrderParameters(&$parameters, $order) { }

    # query builder
    public function buildQuery($queryArray, $parameters)
    {
        return implode(' ', array_filter(array_map(function ($element) use ($parameters) {
            if (is_string($element)) return $element;
            return $parameters[$element] ?? '';
        }, $queryArray), function ($v) { return $v !== ''; }));
    }

    # equality condition
    public function buildEQ($left, $right, $not = false)
    {
        if (!strcasecmp($left, 'null')) {
            return $this->buildISNULL($right, $not);
        } elseif (!strcasecmp($right, 'null')) {
            return $this->buildISNULL($left, $not);
        } else {
            return $left.($not ? '<>' : '=').$right;
        }
    }
    public function buildNEQ($left, $right) { return $this->buildEQ($left, $right, true); }

    # SET key-value pair
    public function buildSETKV($field, $expression)
    {
        return $field.'='.$expression;
    }

    # IS NULL / IS NOT NULL
    public function buildISNULL($expression, $not = false)
    {
        return $expression.($not ? ' IS NOT NULL' : 'IS NULL');
    }
    public function buildISNOTNULL($expression) { return $this->buildISNULL($expression, true); }

    # expression AS alias
    public function buildAS($expression, $alias)
    {
        return $expression.' AS '.$alias;
    }

    # IN () / NOT IN () condition optimization builder, if there is no IN () in your RDBMS, do not forget to set inSupported to false
    public function buildIN($field, $values, $not = false)
    {
        if (count($values) == 0) return '0'; # false condition

        # escaped values can contain NULL in them, and this is a problem
        # they may also be non-unique and this is solved along, although it is a certain performance hog
        $hasNULL = false;
        $inValues = [];
        foreach ($values as $value) {
            if (!strcasecmp($value, 'null')) {
                $hasNULL = true;
                continue;
            }
            $inValues[$value] = $value;
        }

        if ($hasNULL && (count($inValues) == 0)) return $this->buildISNULL($field, $not); # yeah, this CAN happen
        $in = $field.' '.($not ? 'NOT ': '').'IN ('.implode(',', $inValues).')';
        return $hasNULL ? $this->buildOR([$in, $this->buildISNULL($field, $not)]) : $in;
    }
    public function buildNOTIN($field, $values) { return $this->buildIN($field, $values, true); }

    # NOT (inversed) condition builder
    public function buildNOT($condition)
    {
        if ($condition === '1') return '0';
        if ($condition === '0') return '1';
        return 'NOT ('.$condition.')';
    }

    # AND'ed list of conditions builder
    public function buildAND($conditions)
    {
        if (count($conditions) == 0) return '1'; # no conditions is true condition
        if (count($conditions) == 1) return reset($conditions); # nothing to AND with
        return '('.implode(') AND (', $conditions).')';
    }

    # OR'ed list of conditions builder
    public function buildOR($conditions)
    {
        if (count($conditions) == 0) return '1'; # no conditions is true condition
        if (count($conditions) == 1) return reset($conditions); # nothing to OR with
        return '('.implode(') OR (', $conditions).')';
    }

    # name and value escaping routines (abstract, override in your RDBMS driver), string value escape must properly frame string with quotes, NULL values must be properly handled
    abstract public function escapeNameEntity($name);
    abstract public function escapeValue($name);
    abstract public function escapeStringValue($name);
    abstract public function escapeBinaryValue($name);

    # these escape routines may be overridden in your RDBMS driver, but most RDBMS use point-delimited entity levels, so we provide generic implementation here
    public function escapeTableName($table)
    {
        if (!is_array($table)) return $this->escapeNameEntity($table);
        return implode('.', array_map([$this, 'escapeNameEntity'], $table));
    }

    public function escapeFieldName($field)
    {
        if (!is_array($field)) return $this->escapeNameEntity($field);
        return implode('.', array_map([$this, 'escapeNameEntity'], $field));
    }

    # query routines
    # do not forget to add yourself to transaction manager by calling addToTransactionManager()
    abstract public function query($query, $unbuffered = false, $expectResult = null);
    abstract public function endQuery($query);
    abstract public function queryRows($query, $callback, $unbuffered = false);

    # informational routines
    abstract public function getInsertID();
    abstract public function getAffectedRows();

    # transaction routines
    abstract public function startTransaction();
    abstract public function commitTransaction();
    abstract public function rollbackTransaction();

    protected function addToTransactionManager()
    {
        if (!$this->isDatabaseTransactional && ($this->tmTransactionManager !== null))
            $this->tmTransactionManager->addToTransaction($this);
    }

    # Transactional\Entity API

    public function tmTransactionStart($options)
    {
        parent::tmTransactionStart($options);
        if (!$this->isDatabaseTransactional) $this->startTransaction(); # if the database driver is not transactional, start the transaction here
    }

    # override this to actually ping your database if it is not transactional itself
    public function tmTransactionPreCommit($options)
    {
        parent::tmTransactionPreCommit($options);
    }

    public function tmTransactionCommit($options)
    {
        parent::tmTransactionCommit($options);
        if (!$this->isDatabaseTransactional) $this->commitTransaction(); # if the database driver is not transactional, commit the transaction here
    }

    public function tmTransactionRollback($options)
    {
        parent::tmTransactionRollback($options);
        if (!$this->isDatabaseTransactional) $this->rollbackTransaction(); # if the database driver is not transactional, rollback the transaction here
    }
}

abstract class Driver extends \ATL\Transactional\Entity implements \ATL\RDBMS\IDriver { use \ATL\RDBMS\TDriver; }

# Exception classes

class DriverException extends \Exception { }
