<?php

namespace ATL;

########
# transaction manager helps you to coordinate single transaction between multiple transaction objects, i.e. multiple databases
# these transactions are not necessarily XA transactions, i.e. in case of one object failure others may still be partially committed
# but the transaction objects may use transactionalObjectVerifyBeforeCommit() call to check i.e. the database connectivity and abort early before anything is committed to make it safer
# the primary purpose of transaction manager is to make objects dynamically add them to transaction and avoid keeping track of transaction starts, commits and rollbacks calls manually
# transaction manager is transactional object in itself so you can nest transaction manager objects to create hierarchical transaction implementations
# to add transactional object to transaction, call addTransactionObject() before each operation that may be transactional, if the transaction is started you will get transactionalObjectStartTransaction() call on addTransactionObject() and true in return
# calling addTransactionObject() multiple times is okay, so you can call it everywhere, it will not call transactionalObjectStartTransaction() multiple times, but will still return true if the transaction is already started (false if no transaction)
# all transactional objects must work in autocommit mode until transaction is started, and in auto-rollback mode after transaction is started (so if no commit call happens, transactional changes should be rolled back)

# why this seemingly strange model of calling addTransactionObject() before each change instead of pre-adding objects and creating transactions?
# imagine you have 4 databases managed by the transaction manager object, but only need to update two
# if we have a pre-add model, all 4 will have to start a transaction and commit it, but 2 of them will actually start and commit empty transaction, which adds communication overhead for 2 queries of start and commit * 2 databases
# in our add on change model, only 2 databases updated will be added to transaction objects, only these 2 will actually start transaction and commit it, while the rest would lie dormant

interface ITransactionManager
{

}

trait TTransactionManager
{
    public $transactionOptions = [];
    /** @var Transactional\Entity[] */ public $transactionEntities = [];

    public function startTransaction($options = [], $throwIfStarted = true)
    {
        if ($this->tmInTransaction) {
            if ($throwIfStarted) throw new \ATL\TransactionManagerException("Attempted to start transaction while it is already started");
            return false;
        }

        $this->transactionOptions = $options;
        $this->transactionEntities = [];

        if ($this->tmTransactionManager === null) {
            # direct implementation
            $this->tmInTransaction = true;
        } else {
            # nested implementation
            return $this->tmTransactionManager->addToTransaction($this);
        }
        return true;
    }

    public function addToTransaction($entity)
    {
        if (!$this->tmInTransaction) return false; # we do not need to actually add the object because we are not currently in transaction, indicate we have no transaction
        if (!($entity instanceof Transactional\IEntity)) throw new \ATL\TransactionManagerException("Tried to add object of class `".get_class($entity)."` which is not a Transactional\\IEntity implementation");

        # objects are only added once, subsequent calls to addToTransaction() are ignored and do not cause tmTransactionStart() calls
        if (!isset($this->transactionEntities[\ATL\Routines::getUniqueObjectID($entity)])) {
            $this->transactionEntities[\ATL\Routines::getUniqueObjectID($entity)] = $entity;
            $entity->tmTransactionStart($this->transactionOptions);
        }
        return true; # indicate we have transaction running
    }

    public function commitTransaction($throwIfNotStarted = true)
    {
        if (!$this->tmInTransaction) {
            if ($throwIfNotStarted) throw new \ATL\TransactionManagerException("Attempted to commit transaction while it is not started");
            return false;
        }

        foreach ($this->transactionEntities as $entity) $entity->tmTransactionPreCommit($this->transactionOptions);
        foreach ($this->transactionEntities as $entity) $entity->tmTransactionCommit($this->transactionOptions);

        $this->tmInTransaction = false;
        $this->transactionEntities = [];
        $this->transactionOptions = [];
        return true;
    }

    public function rollbackTransaction($throwIfNotStarted = true)
    {
        if (!$this->tmInTransaction) {
            if ($throwIfNotStarted) throw new \ATL\TransactionManagerException("Attempted to rollback transaction while it is not started");
            return false;
        }

        foreach ($this->transactionEntities as $entity) $entity->tmTransactionRollback($this->transactionOptions);

        $this->tmInTransaction = false;
        $this->transactionEntities = [];
        $this->transactionOptions = [];
        return true;
    }

    public function isInTransaction()
    {
        return $this->tmInTransaction;
    }

    ########
    # Transactional\Entity API for nesting transaction managers

    public function tmTransactionStart($options)
    {
        parent::tmTransactionStart($options);

        foreach ($options as $k => $v)
            if (!array_key_exists($k, $this->transactionOptions))
                $this->transactionOptions[$k] = $v;

        $this->startTransaction($this->transactionOptions, false);
    }

    public function tmTransactionPreCommit($options)
    {
        parent::tmTransactionPreCommit($options);
        foreach ($this->transactionEntities as $entity) $entity->tmTransactionPreCommit($this->transactionOptions);
    }

    public function tmTransactionCommit($options)
    {
        parent::tmTransactionCommit($options);
        $this->commitTransaction(false);
    }

    public function tmTransactionRollback($options)
    {
        parent::tmTransactionRollback($options);
        $this->rollbackTransaction(false);
    }
}

class TransactionManager extends \ATL\Transactional\Entity implements \ATL\ITransactionManager { use \ATL\TTransactionManager; }

# Exception classes

class TransactionManagerException extends \Exception { }
