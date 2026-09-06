<?php

namespace ATL;

########
# this is generic direct file locking API, exposed by file based lock managers, but this API is low level and may not be strictly related to specific lock manager functions
# this API may not be interchangeable between lock managers, take care and try to use the interchangeable lock manager API instead

interface IFileLocking
{
    const LOCK_READ = 0;
    const LOCK_WRITE = 1;

    # public interchangeable API common for file-based lock managers
    # take care this basic API cannot take any other options, extended API of specific lock managers should be used in case this one is not sufficient
    public function lock($name, $lockingMode = null, $timeout = 60, $throw = null, $unlinkOnExit = null);
    public function unlock($name, $unlink = false);
    public function canLock($name);

    # internal API
    public function grabFileLock($file, $timeout = 60, $throw = false, $mode = LOCK_EX);
    public function tryOpenFileWithChmod($file, &$handle);
    public function tryGrabbingFileLock($handle, $mode = LOCK_EX);
    public function releaseFileLock($handle, $close = true);
    public function checkIfFileIsLocked($file, $mode = LOCK_EX);
    public function unlinkFileLock($file);
}

trait TFileLocking
{
    public $timeoutRemainder; # you can use this to retrieve remaining timeout if you need to obtain multiple locks at once

    # the following attempts to change file attributes in atomic way so higher privilege users can keep file inaccessible while they chmod
    public $lockChown = null; # set this to user name to chown lock files to on creation
    public $lockChgrp = null; # set this to user name to chgrp lock files to on creation
    public $lockChmod = null; # set this to user name to chmod lock files to on creation

    # returns locked file handle or false on failure, throws LockingException if could not grab lock if $throw is true
    public function grabFileLock($file, $timeout = 60, $throw = false, $mode = LOCK_EX)
    {
        $handle = null;
        $this->timeoutRemainder = $timeout;

        # try to open the file
        $ok = false;
        try {
            $ok = $this->tryOpenFileWithChmod($file, $handle);
        } catch (\Error $e) {
            throw new \ErrorException("Could not open lock file `{$file}`"); # failed to open in any way
        }
        if (!$ok) {
            try {
                $ok = \ATL\Routines::tryUntilTimeout($this->timeoutRemainder, function () use ($file, &$handle) { return $this->tryOpenFileWithChmod($file, $handle); });
            } catch (\Error $e) {
                throw new \ErrorException("Could not open lock file `{$file}`"); # failed to open in any way
            }
        }
        if (!$ok) throw new \ErrorException("Could not open lock file `{$file}`"); # failed to open in any way

        # try to grab the lock immediately
        $ok = false;
        try {
            $ok = $this->tryGrabbingFileLock($handle, $mode);
        } catch (\Error $e) {
            throw new \ErrorException("Locking operation failed for lock file `{$file}`");
        }

        # try to obtain the lock waiting for timeout
        if (!$ok) {
            try {
                $ok = \ATL\Routines::tryUntilTimeout($this->timeoutRemainder, function () use ($handle, $mode) { return $this->tryGrabbingFileLock($handle, $mode); });
                if ($ok) {
                    # in a very rare race condition, we could have just locked file that was just unlinked (and possibly relocked), check for this rare condition
                    $changed = false;
                    $fStat = fstat($handle);
                    clearstatcache(true, $file);
                    if (@is_file($file)) {
                        $cStat = stat($file);
                        if (($fStat['ino'] !== $cStat['ino']) || ($fStat['mtime'] !== $cStat['mtime']) || ($fStat['ctime'] !== $cStat['ctime'])) $changed = true; # inode, ctime or mtime changed
                    } else {
                        $changed = true;
                    }
                    if ($changed) {
                        # file changed, repeat the process with remaining timeout
                        $this->releaseFileLock($handle);
                        return $this->grabFileLock($file, $this->timeoutRemainder, $throw, $mode);
                    }
                } else {
                    # timed out
                    if (!$throw) return false;
                    throw new \ATL\LockingException("Locking timed out for lock file `{$file}` ({$timeout} seconds)");
                }
            } catch (\Error $e) {
                throw new \ErrorException("Locking operation failed for lock file `{$file}`");
            }
        }
        return $handle;
    }

    public function tryOpenFileWithChmod($file, &$handle)
    {
        # try opening the file without creating
        clearstatcache(true, $file);
        $handle = @fopen($file, 'rb+');
        if ($handle) return true; # all is fine, opened from the first attempt

        # try creating the file, it probably does not exist
        clearstatcache(true, $file);
        $handle = @fopen($file, 'ab+');
        if (!$handle) {
            if (($this->lockChown !== null) || ($this->lockChgrp !== null) || ($this->lockChmod !== null))
                if (@is_file($file)) return false; # someone with higher privileges can be holding the lockfile so we just cannot open it
            throw new \ErrorException("Could not open lock file `{$file}`"); # nope, we could not even create it, failure
        }

        # as we have just created the file we need to chown, etc. it properly
        if (($this->lockChown !== null) || ($this->lockChgrp !== null) || ($this->lockChmod !== null)) {
            if ($this->lockChmod !== null) @chmod($file, $this->lockChmod);
            if ($this->lockChown !== null) @chown($file, $this->lockChown);
            if ($this->lockChgrp !== null) @chgrp($file, $this->lockChgrp);
        }

        # done
        return true;
    }

    public function tryGrabbingFileLock($handle, $mode = LOCK_EX)
    {
        $fail = false;
        if (!flock($handle, LOCK_NB | $mode, $fail) && !$fail) throw new \ErrorException("Locking operation failed");
        return !$fail;
    }

    public function releaseFileLock($handle, $close = true)
    {
        flock($handle, LOCK_UN);
        if ($close) fclose($handle);
    }

    public function checkIfFileIsLocked($file, $mode = LOCK_EX)
    {
        clearstatcache(true, $file);
        if (!@is_file($file)) return false; # no lock file
        $handle = @fopen($file, 'rb+');
        if (!$handle) return false; # could not open lock file
        try {
            $ok = $this->tryGrabbingFileLock($handle, $mode);
        } catch (\Error $e) {
            throw new \ErrorException("Locking operation failed for lock file `{$file}`");
        }
        $this->releaseFileLock($handle);
        return !$ok; # either we could not grab the lock and it is locked, or we could and it is not
    }

    public function unlinkFileLock($file)
    {
        # before unlinking lock, we grab it to assure it is not owned by anyone at the moment of unlinking
        clearstatcache(true, $file);
        if (!@is_file($file)) return false; # no lock file
        $handle = @fopen($file, 'rb+');
        if (!$handle) return false; # could not open lock file
        try {
            $ok = $this->tryGrabbingFileLock($handle, LOCK_EX);
        } catch (\Error $e) {
            throw new \ErrorException("Locking operation failed for lock file `{$file}`");
        }
        # if we could grab the lock, unlink the file and release the lock
        # some people can be waiting on the lock already while we unlink it, this is handled in the grabFileLock specifically
        if ($ok) unlink($file);
        $this->releaseFileLock($handle);
        return $ok;
    }
}

abstract class FileLocking implements \ATL\IFileLocking { use \ATL\TFileLocking; }

# Exception classes

class LockingException extends \Exception { };
