<?php

namespace ATL\Locking;

########
# this lock manager implements locking mechanism with safe read/write locks and safe lock upgrades/downgrades, trying to avoid races and heavy contention on the way
# beware: existing lock files should be never ever manually unlinked when running, this can cause false positive locking if the lock was held by someone
# opportunistic lock file unlinking at unlock operation exists for the purpose of unlinking files only when they are surely not used by anyone else
# otherwise all locks marked to be opportunistically unlocked will be attempted to be unlinked only at the program exit (in the shutdown function)
# you can use the opportunistic unlinking mechanism for locks that are desired to be unlinked, but it costs 2 file open/close and 6 locking operations per unlock
# if you want lock file names to be shorter, you can play with $convertLockNameCB callback (i.e. return name MD5 hash, so a fixed filename length), but this may result in lock collisions

########
# please take note two lock managers can coexist in the same locking directory only if lock prefix is different

# read lock:
# - GLOBAL: take shared lock
# - LOCK: try shared lock
# - GLOBAL: unlock
# - repeat until timeout

# write lock:
# - GLOBAL: take shared lock
# - LOCKOPS: try exclusive lock
# -- LOCK: try exclusive lock
# -- LOCKOPS: unlock
# - GLOBAL: unlock
# - repeat until timeout

# read to write lock upgrade:
# - GLOBAL: take shared lock
# - (we are already locking file shared, so no one could grab an exclusive lock in prior)
# - LOCKOPS: try exclusive lock
# -- (no one can relock our file exclusively here)
# -- LOCK: unlock
# -- (readers can obtain shared locks there)
# -- LOCK: try exclusive lock
# --- ERROR => LOCK: shared lock (no timeout can happen there because no one could lock the file exclusively, and other shared locks taken do not matter)
# -- LOCKOPS: unlock
# - GLOBAL: unlock
# - repeat until timeout expires

# write to read lock downgrade
# - GLOBAL: take shared lock
# - (we are already locking the file exclusively, so no one else could)
# - LOCKOPS: try exclusive lock
# -- (no one can relock our file exclusively here)
# -- LOCK: unlock
# -- (readers can obtain shared locks there)
# -- LOCK: shared lock (no timeout can happen there because no one could lock the file exclusively, and other shared locks taken do not matter)
# -- LOCKOPS: unlock
# - GLOBAL: unlock
# - repeat until timeout expires (should be a very rare case on heavy LOCKOPS contention with lots of writers)

# opportunistic unlinking
# - GLOBAL: take exclusive lock
# -- LOCKOPS: try exclusive lock
# --- LOCK: try exclusive lock
# ---- LOCK: unlink
# ---- LOCKOPS: unlink
# - GLOBAL: unlock

interface IRW extends \ATL\IFileLocking
{
    const LOCK_FILE = 0;
    const LOCK_HANDLE = 1;
    const LOCK_MODE = 2;
    const LOCK_UNLINK = 3;
}

trait TRW
{
    protected $lockExtension;
    protected $lockPrefix;
    protected $lockDir;

    protected $throwByDefault;
    protected $unlinkByDefault;
    protected $convertLockNameCB;
    protected $defaultLockingMode;

    protected $globalLockFile;
    protected $globalLockHandle;
    protected $globalLockMode;

    protected $locks = [];
    protected $locksToUnlink = [];

    ########
    # interchangeable file locking API, you can use other lock managers if using only this API and only the general options

    public function __construct($lockDir, $lockExtension = '.lock', $lockPrefix = '', $throwByDefault = false, $unlinkByDefault = false, $convertLockNameCB = null, $defaultLockingMode = self::LOCK_WRITE)
    {
        if (!@is_dir($lockDir)) throw new \ErrorException("Invalid lock files path `{$lockDir}`");

        $this->lockDir = $lockDir;
        $this->lockExtension = $lockExtension;
        $this->lockPrefix = $lockPrefix;
        $this->throwByDefault = $throwByDefault;
        $this->unlinkByDefault = $unlinkByDefault;
        $this->convertLockNameCB = ($convertLockNameCB === null) ? function ($name) { return bin2hex($name); } : \ATL\Routines::callableToClosure($convertLockNameCB);
        $this->defaultLockingMode = $defaultLockingMode;
        $this->globalLockFile = $lockPrefix.'global'.$lockExtension.'.dirlock';

        register_shutdown_function([$this, 'unlinkLocksOnShutdown']); # unlink all requested locks on termination if they are free
    }

    # this function attempts to obtain the lock in question
    # $timeout is in seconds (can be fractional) to wait for the lock, if set to zero, effectively turns it into opportunistic lock (can be taken immediately or not)
    # returns true if lock was taken inside timeout, false otherwise, but if $throw is true, throws LockingException on failure instead of returning false
    # in unlinkOnExit is set, lock file will be scheduled to be unlinked on program termination
    # take care: even a lock downgrade can fail sometimes with high writer contention, but if downgrade fails, it is wise to still continue running with exclusive lock taken
    public function lock($name, $lockingMode = null, $timeout = 60, $throw = null, $unlinkOnExit = null)
    {
        $this->timeoutRemainder = $timeout;
        $lockingMode = $lockingMode ?? $this->defaultLockingMode;
        if (isset($this->locks[$name]) && ($this->locks[$name][$this::LOCK_MODE] == $lockingMode)) return true; # already locked with same lock mode, just return this back

        # on exception, we need to close files / release global directory lock, hence the catcher
        $lockHandle = null;
        $lockOpsHandle = null;
        $result = false;
        try {
            if (!\ATL\Routines::tryUntilTimeout($this->timeoutRemainder, function () { return $this->grabGlobalDirLock(); })) goto endLocking;

            $lockFile = $this->getLockName($name);
            $lockOpsFile = $lockFile.'.lockops';
            if (isset($this->locks[$name])) {
                # we already have a lock grabbed, so we have to upgrade / downgrade it
                if (!($unlinkOnExit ?? $this->unlinkByDefault)) unset($this->locksToUnlink[$name]);

                # for changes, we never set lockHandle so lock is not rerecorded into $this->locks
                switch ($lockingMode) {
                    case $this::LOCK_READ:
                    # lock downgrade
                    try {
                        if ($lockOpsHandle = $this->grabFileLock($lockOpsFile, $this->timeoutRemainder, $throw ?? $this->throwByDefault, LOCK_EX)) {
                            # and here we go
                            $this->releaseFileLock($this->lock[$name][$this::LOCK_HANDLE], false);

                            # here someone can grab shared lock on our lock file, but that is okay because we are going to grab shared lock as well
                            try {
                                $result = \ATL\Routines::tryUntilTimeout($this->timeoutRemainder, function () use ($name) { return $this->tryGrabbingFileLock($this->lock[$name][$this::LOCK_HANDLE], LOCK_SH); });
                            } catch (\Error $e) {
                                throw new \ErrorException("Locking operation failed on lock file `{$lockFile}` for lock `{$name}`");
                            }
                            if (!$result) throw new \ErrorException("Lock downgrade for lock `{$name}`, file `{$lockFile}` failed, this indicates we have some internal error lurking"); # should never happen
                        }
                    } catch (\ATL\LockingException $e) {
                        throw new \ATL\LockingException("Locking timed out for lock `{$name}`, lock operations file `{$lockOpsFile}` ({$timeout} seconds)");
                    } catch (\Error $e) {
                        throw new \ErrorException("Locking operation failed on lock operations file `{$lockOpsFile}` for lock `{$name}`");
                    }
                    goto endLocking;

                    case $this::LOCK_WRITE:
                    # lock upgrade
                    try {
                        if ($lockOpsHandle = $this->grabFileLock($lockOpsFile, $this->timeoutRemainder, $throw ?? $this->throwByDefault, LOCK_EX)) {
                            # and here we go
                            $this->releaseFileLock($this->lock[$name][$this::LOCK_HANDLE], false);

                            # here someone can grab shared lock on our lock file, but that is okay, we can reobtain shared lock back safely if this happens
                            try {
                                $result = \ATL\Routines::tryUntilTimeout($this->timeoutRemainder, function () use ($name) { return $this->tryGrabbingFileLock($this->lock[$name][$this::LOCK_HANDLE], LOCK_EX); });
                                if (!$result) {
                                    $result = \ATL\Routines::tryUntilTimeout($this->timeoutRemainder, function () use ($name) { return $this->tryGrabbingFileLock($this->lock[$name][$this::LOCK_HANDLE], LOCK_SH); });
                                    if (!$result) throw new \ErrorException("Lock upgrade relock shared for lock `{$name}`, file `{$lockFile}` failed, this indicates we have some internal error lurking"); # should never happen
                                    $result = false; # still, indicate the failure, we just regrabbed the shared lock back
                                }
                            } catch (\Error $e) {
                                throw new \ErrorException("Locking operation failed on lock file `{$lockFile}` for lock `{$name}`");
                            }
                        }
                    } catch (\ATL\LockingException $e) {
                        throw new \ATL\LockingException("Locking timed out for lock `{$name}`, lock operations file `{$lockOpsFile}` ({$timeout} seconds)");
                    } catch (\Error $e) {
                        throw new \ErrorException("Locking operation failed on lock operations file `{$lockOpsFile}` for lock `{$name}`");
                    }
                    goto endLocking;

                    default:
                    throw new \ErrorException("Unsupported lock type for lock `{$name}`"); # unsupported lock type
                }
            }

            # we are creating a new lock
            $lockHandle = null;
            $ok = false;
            try {
                $ok = $this->tryOpenFileWithChmod($lockFile, $lockHandle);
            } catch (\Error $e) {
                throw new \ATL\LockingException("Failed to open lock file `{$lockFile}` for lock `{$name}`");
            }
            if (!$ok) {
                try {
                    $ok = \ATL\Routines::tryUntilTimeout($this->timeoutRemainder, function () use ($lockFile, &$lockHandle) { return $this->tryOpenFileWithChmod($lockFile, $lockHandle); });
                } catch (\Error $e) {
                    throw new \ATL\LockingException("Failed to open lock file `{$lockFile}` for lock `{$name}`");
                }
            }
            if (!$ok) throw new \ATL\LockingException("Failed to open lock file `{$lockFile}` for lock `{$name}`");

            switch ($lockingMode) {
                # shared lock
                case $this::LOCK_READ:
                try {
                    $result = \ATL\Routines::tryUntilTimeout($this->timeoutRemainder, function () use ($lockHandle, $name) { return $this->tryGrabbingFileLock($lockHandle, LOCK_SH); });
                } catch (\Error $e) {
                    throw new \ErrorException("Locking operation failed on lock file `{$lockFile}` for lock `{$name}`");
                }
                goto endLocking;

                # exclusive lock
                case $this::LOCK_WRITE:
                try {
                    if ($lockOpsHandle = $this->grabFileLock($lockOpsFile, $this->timeoutRemainder, $throw ?? $this->throwByDefault, LOCK_EX)) {
                        try {
                            $result = \ATL\Routines::tryUntilTimeout($this->timeoutRemainder, function () use ($lockHandle, $name) { return $this->tryGrabbingFileLock($lockHandle, LOCK_EX); });
                        } catch (\Error $e) {
                            throw new \ErrorException("Locking operation failed on lock file `{$lockFile}` for lock `{$name}`");
                        }
                    }
                } catch (\ATL\LockingException $e) {
                    throw new \ATL\LockingException("Locking timed out for lock `{$name}`, lock operations file `{$lockOpsFile}` ({$timeout} seconds)");
                } catch (\Error $e) {
                    throw new \ErrorException("Locking operation failed on lock operations file `{$lockOpsFile}` for lock `{$name}`");
                }
                goto endLocking;

                default:
                throw new \ErrorException("Unsupported lock type for lock `{$name}`"); # unsupported lock type
            }
        } catch (\ATL\LockingException $e) {
            # release locks that are not needed anymore
            if ($lockHandle) $this->releaseFileLock($lockHandle);
            if ($lockOpsHandle) $this->releaseFileLock($lockOpsHandle);
            $this->releaseGlobalDirLock();
            throw $e;
        } catch (\Error $e) {
            # release locks that are not needed anymore
            if ($lockHandle) $this->releaseFileLock($lockHandle);
            if ($lockOpsHandle) $this->releaseFileLock($lockOpsHandle);
            $this->releaseGlobalDirLock();
            throw $e;
        }

endLocking:
        # store lock if it succeeds
        if ($result && $lockHandle) {
            $this->locks[$name] = [$this::LOCK_FILE => $lockFile, $this::LOCK_MODE => $lockingMode, $this::LOCK_HANDLE => $lockHandle, $this::LOCK_UNLINK => $unlinkOnExit ?? $this->defaultLockingMode];
            if (!($unlinkOnExit ?? $this->unlinkByDefault)) unset($this->locksToUnlink[$name]);
            $lockHandle = null; # set to null so we do not release the acquired lock
        }

        # release locks that are not needed anymore
        if ($lockHandle) $this->releaseFileLock($lockHandle);
        if ($lockOpsHandle) $this->releaseFileLock($lockOpsHandle);
        $this->releaseGlobalDirLock();

        # return result or throw an exception
        if (!$result && ($throw ?? $this->throwByDefault)) throw new \ATL\LockingException("Failed to obtain ".(($lockingMode == $this::LOCK_READ) ? 'read' : 'write')." lock for `{$name}`");
        return $result;
    }

    # $unlink
    #   setting it to true enables opportunistic unlinking of lock files if they are not used by anyone else
    #   opportunistic unlinking here is not controlled by defaults, normally all unlinking happens at program termination
    #   you can use that for temporary locks which files are desirable to be removed right now, but it costs 2 file open/close and 6 locking operations per unlock
    #   do not use opportunistic unlinking for locks that are used heavily, you will just increase load on the system without gaining much by removing files
    public function unlock($name, $unlink = false)
    {
        $lockStruct = $this->locks[$name];
        if (isset($lockStruct[$name])) {
            # unlock
            $this->releaseFileLock($lockStruct[$name][$this::LOCK_HANDLE]);
            unset($lockStruct[$name]);
        }

        if ($lockStruct[$this::LOCK_UNLINK]) $this->locksToUnlink[$name] = $lockStruct;
        if ($unlink) {
            # try opportunistic unlinking
            if ($this->grabGlobalDirLock(LOCK_EX)) {
                # we could grab the exclusive directory lock, so no one waits or can start waiting on lockops and lock files now and we can safely remove
                if ($this->tryUnlinkLock($lockStruct)) unset($this->locksToUnlink[$name]);
                $this->releaseGlobalDirLock();
            }
        }
    }

    # this uses no timeout for all operations so it may return false negative even when lock itself is able to be locked again just because i.e. global dir lock exists
    # so do use it only when you need to verify massive amount of locks, i.e. to detect which seemingly stale processes still run or something like that
    public function canLock($name, $lockingMode = null)
    {
        $lockingMode = $lockingMode ?? $this->defaultLockingMode;
        if (!$this->grabGlobalDirLock()) return false;
        $lockFile = $this->getLockName($name);
        $lockOpsFile = $lockName.'.lockops';
        if ($this->checkIfFileIsLocked($lockOpsFile, LOCK_EX)) { $this->releaseGlobalDirLock(); return false; } # lock operations file is locked
        if ($this->checkIfFileIsLocked($lockFile, $lockingMode)) { $this->releaseGlobalDirLock(); return false; } # lock file is locked
        $this->releaseGlobalDirLock();
        return true;
    }

    ########
    # internal API, may be partially exposed but is not recommended to use

    public function getLockName($name)
    {
        return $this->lockDir."/".$this->lockPrefix.($this->convertLockNameCB)($name).$this->lockExtension;
    }

    protected function tryUnlinkLock($lockStruct)
    {
        $lockHandle = null;
        $lockOpsHandle = null;
        $lockFile = $lockStruct[$this::LOCK_FILE];
        $lockOpsFile = $lockFile.'.lockops';

        $result = false;
        if ($lockOpsHandle = $this->grabFileLock($lockOpsFile, 0, false, LOCK_EX)) {
            if ($lockHandle = $this->grabFileLock($lockFile, 0, false, LOCK_EX)) {
                # we could grab all the exclusive locks so files are not held by anyone and free to remove
                unlink($lockFile);
                unlink($lockOpsFile);
                $result = true;
            }
        }

        if ($lockHandle) $this->releaseFileLock($lockHandle);
        if ($lockOpsHandle) $this->releaseFileLock($lockOpsHandle);

        return $result;
    }

    public function unlinkLocksOnShutdown()
    {
        # release all remaining locks
        foreach ($this->locks as $name => $lockStruct)
            $this->unlock($name);

        # unlink all locks scheduled to be unlinked
        $haveGlobalLock = false;
        foreach ($this->locksToUnlink as $name => $lockStruct) {
            if (!$haveGlobalLock)
                if ($this->grabGlobalDirLock(LOCK_EX))
                    $haveGlobalLock = true;
            if ($haveGlobalLock) $this->tryUnlinkLock($lockStruct);
            unset($this->locksToUnlink[$name]);
        }
        if ($haveGlobalLock) $this->releaseGlobalDirLock();
    }

    protected function grabGlobalDirLock($mode = LOCK_SH)
    {
        if ($this->globalLockMode !== null) throw new \ErrorException('Tried to lock global directory lock file while it is already locked, this means we have some internal error lurking');

        if (!$this->globalLockHandle) {
            $lockFile = "{$this->lockDir}/{$this->globalLockFile}";
            $lockHandle = null;
            $ok = false;
            try {
                $ok = $this->tryOpenFileWithChmod($lockFile, $lockHandle);
            } catch (\Error $e) {
                throw new \ErrorException("Failed to open global directory lock file `{$this->globalLockFile}`");
            }
            if (!$ok) {
                try {
                    $ok = \ATL\Routines::tryUntilTimeout($this->timeoutRemainder, function () use ($lockFile, &$lockHandle) { return $this->tryOpenFileWithChmod($lockFile, $lockHandle); });
                } catch (\Error $e) {
                    throw new \ErrorException("Failed to open global directory lock file `{$this->globalLockFile}`");
                }
            }
            if (!$ok) throw new \ErrorException("Failed to open global directory lock file `{$this->globalLockFile}`");

            $this->globalLockHandle = $lockHandle;
        }

        try {
            $result = $this->tryGrabbingFileLock($this->globalLockHandle, LOCK_NB | $mode);
        } catch (\Error $e) {
            throw new \ErrorException("Locking operation failed on global directory lock file `{$this->globalLockFile}`");
        }
        if ($result) $this->globalLockMode = $mode;
        return $result;
    }

    protected function releaseGlobalDirLock()
    {
        if ($this->globalLockMode === null) return; # no lock grabbed, skip the operation
        if ($this->globalLockHandle)
            $this->releaseFileLock($this->globalLockHandle, false);
        $this->globalLockMode = null;
    }
}

class RW extends \ATL\FileLocking implements \ATL\Locking\IRW { use \ATL\Locking\TRW; }
