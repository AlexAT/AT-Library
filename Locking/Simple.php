<?php

namespace ATL\Locking;

########
# this is a simple exclusive lock manager that is faster than RW if you do not need fine-grained exclusive/shared lock control
# please take note two lock managers can coexist in the same locking directory only if lock prefix or lock extension is different and does not clash internally
# if you want lock file names to be shorter, you can play with $convertLockNameCB callback (i.e. return name MD5 hash, so a fixed filename length), but this may result in lock collisions

interface ISimple extends \ATL\IFileLocking
{
    const LOCK_FILE = 0;
    const LOCK_HANDLE = 1;
    const LOCK_UNLINK = 2;
}

trait TSimple
{
    protected $lockDir;
    protected $lockExtension;
    protected $lockPrefix;

    protected $throwByDefault;
    protected $unlinkByDefault;
    protected $convertLockNameCB;

    protected $locks = [];
    protected $locksToUnlink = [];

    ########
    # interchangeable file locking API, you can use other lock managers if using only this API and only the general options

    public function __construct($lockDir, $lockExtension = '.lock', $lockPrefix = '', $throwByDefault = false, $unlinkByDefault = false, $convertLockNameCB = null)
    {
        if (!@is_dir($lockDir)) throw new \ErrorException("Invalid lock files path `{$lockDir}`");

        $this->lockDir = $lockDir;
        $this->lockExtension = $lockExtension;
        $this->lockPrefix = $lockPrefix;
        $this->throwByDefault = $throwByDefault;
        $this->unlinkByDefault = $unlinkByDefault;
        $this->convertLockNameCB = ($convertLockNameCB === null) ? function ($name) { return bin2hex($name); } : \ATL\Routines::callableToClosure($convertLockNameCB);

        register_shutdown_function([$this, 'unlinkLocksOnShutdown']); # unlink all requested locks on termination if they are free
    }

    # interchangeable options: $name, $timeout, $throw
    # returns true if lock was taken inside timeout, false otherwise, but if $throw is true, throws LockingException on failure instead of returning false
    # in unlinkOnExit is set, lock file will be scheduled to be unlinked on program termination
    # lockingMode is ignored, this lock manager always locks exclusively
    public function lock($name, $lockingMode = null, $timeout = 60, $throw = null, $unlinkOnExit = null)
    {
        $this->timeoutRemainder = $timeout;
        if (isset($this->locks[$name])) return true; # lock already grabbed

        try {
            $file = $this->getLockName($name);
            if ($handle = $this->grabFileLock($file, $this->timeoutRemainder, $throw ?? $this->throwByDefault, LOCK_EX)) {
                # locking succeeded
                $this->locks[$name] = [$this::LOCK_FILE => $file, $this::LOCK_HANDLE => $handle, $this::LOCK_UNLINK => $unlinkOnExit ?? $this->unlinkByDefault];
                if (!($unlinkOnExit ?? $this->unlinkByDefault)) unset($this->locksToUnlink[$name]);
                return true;
            }
        } catch (\ATL\LockingException $e) {
            # we can only get there if $throw = true
            throw new \ATL\LockingException("Locking timed out for lock `{$name}`, lock file `{$file}` ({$timeout} seconds)");
        }

        return false;
    }

    # interchangeable options: $name
    # opportunistic unlinking here is not controlled by defaults, normally all unlinking happens at program termination
    public function unlock($name, $unlink = false)
    {
        if (!isset($this->locks[$name])) return; # not grabbed

        # release the lock
        $lockStruct = $this->locks[$name];
        unset($this->locks[$name]);
        if ($unlink || $lockStruct[$this::LOCK_UNLINK])
            $this->locksToUnlink[$name] = $lockStruct[$this::LOCK_FILE];
        $this->releaseFileLock($lockStruct[$this::LOCK_HANDLE]);

        # attempt to unlink the lock now if requested directly
        if ($unlink && $this->unlinkFileLock($lockStruct[$this::LOCK_FILE]))
            unset($this->locksToUnlink[$name]);
    }

    # interchangeable options: $name
    # this uses no timeout for the operation so it may return false negative even when lock itself is able to be locked again just in a moment from there
    # so do use it only when you need to verify massive amount of locks, i.e. to detect which seemingly stale processes still run or something like that
    public function canLock($name)
    {
        return !$this->checkIfFileIsLocked($this->getLockName($name));
    }

    ########
    # internal API, may be partially exposed but is not recommended to use

    public function unlinkLocksOnShutdown()
    {
        # release all remaining locks
        foreach ($this->locks as $name => $lockStruct)
            $this->unlock($name);

        # unlink all locks scheduled to be unlinked
        foreach ($this->locksToUnlink as $name => $file) {
            $this->unlinkFileLock($this->locksToUnlink[$name]);
            unset($this->locksToUnlink[$name]);
        }
    }

    public function getLockName($name)
    {
        return $this->lockDir."/".$this->lockPrefix.($this->convertLockNameCB)($name).$this->lockExtension;
    }
}

class Simple extends \ATL\FileLocking implements \ATL\Locking\ISimple { use \ATL\Locking\TSimple; }
