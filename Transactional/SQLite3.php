<?php

namespace ATL\Transactional;

########
# SQLite3 derivative with transaction manager support
# constructor has transactionManager added as first option, then normal SQLite3 options follow

interface ISQLite3 { }

trait TSQLite3
{
    ########
    # API causing object to add itself to transaction manager

    public function __construct(/** @var \ATL\TransactionManager */ $transactionManager = null, ...$args)
    {
        # call real entity and SQLite3 constructors
        $this->constructTransactionalEntity($transactionManager);
        parent::__construct(...$args);
    }

    public function exec($query)
    {
        $this->tmAddToTransaction();
        return parent::exec($query);
    }

    public function query($query)
    {
        $this->tmAddToTransaction();
        return parent::query($query);
    }

    public function querySingle($query, $entireRow = false)
    {
        $this->tmAddToTransaction();
        return  parent::querySingle($query, $entireRow);
    }

    public function prepare($query)
    {
        # prepared statements need a wrapper to ensure they add us to transaction manager on execution
        return new \ATL\Transactional\SQLite3Statement($this, parent::prepare($query));
    }

    ########
    # Transactional\Entity API

    public function tmTransactionStart($options)
    {
        parent::tmTransactionStart($options);
        $result = parent::exec('BEGIN '.($options['SQLite3::beginQueryOptions'] ?? '').' TRANSACTION');
        if ($result === false) throw new \ATL\Transactional\SQLite3Exception("TRANSACTION START [SQLite3]: ".parent::lastErrorCode()." ".parent::lastErrorMsg());
    }

    public function tmTransactionPreCommit($options)
    {
        parent::tmTransactionPreCommit($options);
        if (parent::querySingle('SELECT 1') !== 1) throw new \ATL\Transactional\SQLite3Exception("TRANSACTION PRE-COMMIT [SQLite3]: Failed to ping the SQLite3 enging");
    }

    public function tmTransactionCommit($options)
    {
        parent::tmTransactionCommit($options);
        $result = parent::exec('COMMIT TRANSACTION');
        if ($result === false) throw new \ATL\Transactional\SQLite3Exception("TRANSACTION COMMIT [SQLite3]: ".parent::lastErrorCode()." ".parent::lastErrorMsg());
    }

    public function tmTransactionRollback($options)
    {
        parent::tmTransactionRollback($options);
        $result = parent::exec('ROLLBACK TRANSACTION');
        if ($result === false) throw new \ATL\Transactional\SQLite3Exception("TRANSACTION ROLLBACK [SQLite3]: ".parent::lastErrorCode()." ".parent::lastErrorMsg());
    }
}

class PSQLite3 extends \SQLite3 implements \ATL\Transactional\IEntity { use \ATL\Transactional\TEntity; }
class SQLite3 extends PSQLite3 implements \ATL\Transactional\ISQLite3 { use \ATL\Transactional\TSQLite3; }

########
# helper wrapper for running prepared statements in transactional SQLite3 class
# this class is crap, it is wrapper, it acts as proxy class for real SQLite3Stmt, cannot be identified as instanceof SQLite3Stmt, and has significant performance hit
# alas, no way around because original SQLite3Statement does not use any proper constructor defined and is directly returned by SQLite3 class

class SQLite3Statement
{
    protected $transactionalSQLite3;
    protected $transactionalSQLite3Stmt;

    public function __construct(/** @var SQLite3 */ $sqlite3, $sqlite3stmt)
    {
        $this->transactionalSQLite3 = $sqlite3;
        $this->transactionalSQLite3Stmt = $sqlite3stmt;
   }

    public function execute()
    {
        $this->transactionalSQLite3->addToTransaction();
        return $this->transactionalSQLite3Stmt->execute();
    }

    public function __set($name, $value) { $this->transactionalSQLite3Stmt->$name = $value; }
    public function __get($name) { return $this->transactionalSQLite3Stmt->$name; }
    public function __isset($name) { return isset($this->transactionalSQLite3Stmt->$name); }
    public function __unset($name) { unset($this->transactionalSQLite3Stmt->$name); }
    public function __call($method, $args) { return $this->transactionalSQLite3Stmt->$method(...$args); }
    public static function __callStatic($method, $args) { return SQLite3Statement::$method(...$args); }
}

# Exception classes

class SQLite3Exception extends \ATL\Transactional\TransactionException { }
