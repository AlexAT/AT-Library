<?php

namespace ATL\Cache;

########
# file based cache implements caching mechanism based on batch files, supporting fine grained locking and invalidation for cache groups + parameters hash
# cache group name ($group) MUST NOT contain any special characters besides '-' and '_', it is used in file access calls directly
# parameters ($param) must be a string (serialize anything if you need it)
# arbitrary cache ID ($cacheID) can be used to distinguish lock files for multiple caches, but it does not affect cache file naming
# if you use parameter sets, avoid using null for $param when caching because it will lock the whole group (you can (ab)use it to store some group globals though)
# cache parameter hash is fast MD5 hash, it is designed only to avoid collisions and does not need to be long, you can easily override hash function by extending the class
# if collision happens, cache would be valid only for the last parameter set written
# renew function for tryFromCache - $renewFunction($cache, $group, $param) - must call writeCacheBatch itself, it will be auto framed by startCacheWrite and endCacheWrite though

interface IBatchDataFile
{
    public function invalidate($group, $param = null, $lockTimeout = null);
    public function tryFromCache($group, $renewFunction, $param = null);
    public function tryReadCache($group, $param = null, $returnIfLocked = false);
    public function startCacheWrite($group, $param = null);
    public function writeCacheBatch($group, $batch, $param = null);
    public function endCacheWrite($group, $param = null);
    public function lockCache($group, $mode = null, $param = null, $lockTimeout = null);
    public function unlockCache($group, $param = null);
}

trait TBatchDataFile
{
    protected $cacheId;
    protected $cacheDir;
    protected $lockManager;
    protected $lockTimeout = 60;

    public function __construct($cacheDir, $lockManager, $lockTimeout = 60, $cacheId = '')
    {
        $this->cacheDir = $cacheDir;
        $this->lockManager = $lockManager;
        $this->lockTimeout = $lockTimeout;
    }

    public function invalidate($group, $param = null, $lockTimeout = null)
    {
        $this->lockManager->lock($this->getLockName($group, $param), $this->lockManager::LOCK_WRITE, $lockTimeout ?? $this->lockTimeout, true);
        if ($param === null) file_put_contents("{$this->cacheDir}/{$group}.invalidate", $this->getStamp()); # invalidate the whole group
        if (@is_file($this->getCacheFileName($group, $param, 'cache'))) unlink($this->getCacheFileName($group, $param, 'cache'));
        if (@is_file($this->getCacheFileName($group, $param, 'stamp'))) unlink($this->getCacheFileName($group, $param, 'stamp'));
        if (@is_file($this->getCacheFileName($group, $param, 'id'))) unlink($this->getCacheFileName($group, $param, 'id'));
        $this->lockManager->unlock($this->getLockName($group, $param));
    }

    public function tryFromCache($group, $renewFunction, $param = null)
    {
        # lock cache entry for reading
        $this->lockManager->lock($this->getLockName($group, $param), $this->lockManager::LOCK_READ, $this->lockTimeout, true);

        try {
            # read global invalidation stamp
            $this->lockManager->lock($this->getLockName($group), $this->lockManager::LOCK_READ, $this->lockTimeout, true);
            if (($iStamp = @file_get_contents("{$this->cacheDir}/{$group}.invalidate")) === false) $iStamp = 0;
            $this->lockManager->unlock($this->getLockName($group));

            # read stamp for a specific parameter set
            if (($cStamp = @file_get_contents($this->getCacheFileName($group, $param, 'stamp'))) !== false) {
                if ($cStamp >= $iStamp) {

                }
            }

            # no cache data could be read, process with renew function
        } catch (\Exception $e) {
            # release locks
            $this->lockManager->unlock($this->getLockName($group, $param));
            $this->lockManager->unlock($this->getLockName($group));
        }
    }

    public function tryReadCache($group, $param = null, $returnIfLocked = false)
    {
    }

    public function startCacheWrite($group, $param = null)
    {
        $this->lockManager->lock($this->getLockName($group, $param), $this->lockManager::LOCK_WRITE, $this->lockTimeout, true);
        if (@is_file($this->getCacheFileName($group, $param, 'cache'))) unlink($this->getCacheFileName($group, $param, 'cache'));
        file_put_contents($this->getCacheFileName($group, $param, 'stamp'), $this->getStamp());
    }

    public function writeCacheBatch($group, $batch, $param = null)
    {
    }

    public function endCacheWrite($group, $param = null)
    {
        $this->lockManager->unlock($this->getLockName($group, $param));
    }

    public function lockCache($group, $mode = null, $param = null, $lockTimeout = null)
    {
        if ($mode === null) $mode = $this->lockManager::LOCK_READ;
        return $this->lockManager->lock($this->getLockName($group, $param), $mode, $lockTimeout ?? $this->lockTimeout);
    }

    public function unlockCache($group, $param = null)
    {
        $this->lockManager->unlock($this->getLockName($group, $param));
    }

    protected function getLockName($group, $param = null)
    {
        return ($param === null) ? "cache/{$cacheId}/{$group}" : "cache/{$cacheId}/{$group}/".$this->hashData($param);
    }

    protected function getCacheFileName($group, $param = null, $mode = 'cache')
    {
        return ($param === null) ? "{$this->cacheDir}/{$group}.{$mode}" : "{$this->cacheDir}/{$group}.{$mode}.".$this->hashData($param);
    }

    protected function getStamp()
    {
        return round(microtime(true) * 1000000);
    }

    protected function hashData($data)
    {
        return md5($data);
    }
}

class BatchDataFile implements \ATL\Cache\IBatchDataFile { use \ATL\Cache\TBatchDataFile; }
