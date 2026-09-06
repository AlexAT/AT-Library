<?php

namespace ATL\Transactional;

########
# MySQLi derivative with transaction manager support
# constructor has transactionManager added as first option, then normal MySQLi options follow

interface IMySQLi { }

trait TMySQLi
{
    ########
    # API causing object to add itself to transaction manager

    public function __construct(/** @var \ATL\TransactionManager */ $transactionManager = null, ...$args)
    {
        # call real entity and MySQLi constructors
        $this->constructTransactionalEntity($transactionManager);
        parent::__construct(...$args);
    }

    #[\ReturnTypeWillChange]
    public function multi_query($query)
    {
        $this->tmAddToTransaction();
        return parent::multi_query($query);
    }

    #[\ReturnTypeWillChange]
    public function query($query, $resultmode = null)
    {
        $this->tmAddToTransaction();
        return parent::query($query);
    }

    #[\ReturnTypeWillChange]
    public function real_query($query)
    {
        $this->tmAddToTransaction();
        return  parent::real_query($query);
    }

    #[\ReturnTypeWillChange]
    public function prepare($query)
    {
        # prepared statements need a wrapper to ensure they add us to transaction manager on execution
        return new \ATL\Transactional\MySQLiStatement($this, $query);
    }

    ########
    # Transactional\Entity API

    public function tmTransactionStart($options)
    {
        parent::tmTransactionStart($options);
        $result = parent::query('START TRANSACTION '.($options['MySQLi::startQuerySuffix'] ?? ''));
        if ($result === false) throw new \ATL\Transactional\MySQLiException("TRANSACTION START [MySQLi]: ".parent::errno." ".parent::error);
    }

    public function tmTransactionPreCommit($options)
    {
        parent::tmTransactionPreCommit($options);
        if (!parent::ping()) throw new \ATL\Transactional\MySQLiException("TRANSACTION PRE-COMMIT [MySQLi]: Failed to ping the MySQL server");
    }

    public function tmTransactionCommit($options)
    {
        parent::tmTransactionCommit($options);
        $result = parent::query('COMMIT');
        if ($result === false) throw new \ATL\Transactional\MySQLiException("TRANSACTION COMMIT [MySQLi]: ".parent::errno." ".parent::error);
    }

    public function tmTransactionRollback($options)
    {
        parent::tmTransactionRollback($options);
        $result = parent::query('ROLLBACK');
        if ($result === false) throw new \ATL\Transactional\MySQLiException("TRANSACTION ROLLBACK [MySQLi]: ".parent::errno." ".parent::error);
    }
}

class PMySQLi extends \MySQLi implements \ATL\Transactional\IEntity { use \ATL\Transactional\TEntity; }
class MySQLi extends \ATL\Transactional\PMySQLi implements \ATL\Transactional\IMySQLi { use \ATL\Transactional\TMySQLi; }

########
# helper wrapper for running prepared statements in transactional MySQLi class

class MySQLiStatement extends \MySQLi_stmt
{
    protected $transactionalMySQLi;

    public function __construct(/** @var MySQLi */ $mysqli, $query = null)
    {
        $this->transactionalMySQLi = $mysqli;
        parent::__construct($mysqli, $query);
    }

    #[\ReturnTypeWillChange]
    public function execute($params = null)
    {
        $this->transactionalMySQLi->addToTransaction();
        return parent::execute($params);
    }
}

# Exception classes

class MySQLiException extends \ATL\Transactional\TransactionException { }
