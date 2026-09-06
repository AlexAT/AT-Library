<?php

namespace ATL\Transactional;

########
# transactional entities (objects) are intended to work in conjunction with transaction manager
# extend transactional entity class or use interface/trait pair to make your object support transactions via transaction manager
# before each operation in your object that may be transactional (i.e. query), call $this->tmAddToTransaction() or transaction manager addToTransaction($this), it will return true if you are in transaction, false otherwise

# if you add yourself for the first time while in transaction, transaction manager will call your tmTransactionStart() routine to actually start the transaction
# to commit or rollback, transaction manager will call either tmTransactionCommit() or tmTransactionRollback() to inform you about the operation, before removing you from transaction
# transaction options array is passed to each transaction* call as the only argument, it may contain a multitude of options referring to all possible transaction entities, so filter it
# to add some extra safety despite being not XA, before calling tmTransactionCommit(), transaction manager will call tmTransactionPreCommit(), so you may do some dummy operation to i.e. verify database connectivity in it
# nested transactions are not supported, so do not expect nesting, but call original parent implementations of overridden methods before you do anything to ensure this is checked and transaction state is changed

# why this seemingly strange model of calling addToTransaction() before each change instead of pre-adding objects and creating transactions?
# imagine you have 4 databases managed by the transaction manager object, but only need to update two
# if we have a pre-add model, all 4 will have to start a transaction and commit it, but 2 of them will actually start and commit empty transaction, which adds communication overhead for 2 queries of start and commit * 2 databases
# in our add on change model, only 2 databases updated will be added to transaction entities, only these 2 will actually start transaction and commit it, while the rest would lie dormant

# tmIsInTransaction() will return true if object is linked to some transaction, false otherwise
# tmGetTransactionManager() will return transaction owner transaction manager if in transaction or null if no transaction

# see i.e. Transactional\MySQLi for actual implementation example

########
# how to reuse ATL interface-traits-class triads:
# due to PHP not supporting multiple inheritance and heavily limiting overrides from interfaces and traits, we have to use inheritance workaround

# variant 1 (simple): you need only the single ATL interface-trait in your class
#   just extend your class with the interface-trait class with base trait based class, like \ATL\Transactional\Entity
#     class MyClass extends \ATL\Transactional\Entity

# variant 2 (complex): you need multiple ATL interfaces-traits in your class (complex)
#   imagine we want both Transactional\Entity and BindableObject in a single class
#   create your class prototype class definition using both interfaces and both traits, this will implement both but will not allow you to override anything
#     class MyClassPrototype implements \ATL\Transactional\IEntity, \ATL\IBindableObject, { use \ATL\Transactional\Entity, \ATL\TBindableObject; }
#   inherit your class prototype class into your primary class, this workaround gets over PHP override limits and allows you to freely change anything you want
#     class MyClass extends MyClassPrototype { ...your class code... }

interface IEntity
{
    # constructor
    public function constructTransactionalEntity(/** @var \ATL\TransactionManager */ $transactionManager);

    # API to override
    public function tmIsInTransaction();
    public function tmGetTransactionManager();
    public function tmAddToTransaction();
    public function tmTransactionStart($options);
    public function tmTransactionPreCommit($options);
    public function tmTransactionCommit($options);
    public function tmTransactionRollback($options);
}

trait TEntity
{
    protected $tmInTransaction = false;
    /** @var \ATL\TransactionManager */ protected $tmTransactionManager;

    # real constructor that allows to use of trait, call from your constructor (see item object instantiation)
    # constructs an unstored instance of item, use store put() method to add it to the store
    public function constructTransactionalEntity(/** @var \ATL\TransactionManager */ $transactionManager)
    {
        $this->tmTransactionManager = $transactionManager;
    }

    # override this to perform your transaction start operations, always call parent implementation first
    public function tmTransactionStart($options)
    {
        if ($this->tmInTransaction) throw new \ATL\Transactional\TransactionException("Attempted to start transaction while it is already started");
        $this->tmInTransaction = true;
    }

    # override this to perform health checks before transaction commits, always call parent implementation first
    public function tmTransactionPreCommit($options)
    {
        if (!$this->tmInTransaction) throw new \ATL\Transactional\TransactionException("Attempted to do pre-commit check while transaction is not started");
    }

    # override this to perform your transaction commit operations, always call parent implementation first
    public function tmTransactionCommit($options)
    {
        if (!$this->tmInTransaction) throw new \ATL\Transactional\TransactionException("Attempted to commit transaction while it is not started");
        $this->tmInTransaction = false;
    }

    # override this to perform your transaction rollback operations, always call parent implementation first
    public function tmTransactionRollback($options)
    {
        if (!$this->tmInTransaction) throw new \ATL\Transactional\TransactionException("Attempted to rollback transaction while it is not started");
        $this->tmInTransaction = false;
    }

    # these usually do not need any override

    public function tmIsInTransaction()
    {
        return $this->tmInTransaction;
    }

    public function tmGetTransactionManager()
    {
        return $this->tmTransactionManager;
    }

    public function tmAddToTransaction()
    {
        if ($this->tmTransactionManager === null) return false; # no transaction manager = never in transaction
        return $this->tmTransactionManager->addToTransaction($this);
    }
}

class Entity implements \ATL\Transactional\IEntity
{
    use \ATL\Transactional\TEntity;

    public function __construct(/** @var \ATL\ObjectStore */ $transactionManager = null)
    {
        # call real item constructor
        $this->constructTransactionalEntity($transactionManager);
    }
}

# Exception classes

class TransactionException extends \Exception { }
