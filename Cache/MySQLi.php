<?php

namespace ATL\Cache;

########
# this simple MySQL cache implements straightforward caching mechanism
# cache can be accessed by triads of group-key-params, where group is caching group, key is specific caching key, and data is unique parameter set
# parameter set is hashed (SHA256 by default), on hash collisions cache is considered invalid - no collision resolution is performed
# each entry can have its own TTL (zero means infinite), and invalidation can be performed by setting current microtime(true) stamp and TTL to negative
# on cache updates, new microtime(true) timestamp should be created just before cache read attempt, update performed and cache written
# on cache writes, timestamp is important: older timestamps than ones stored in database never update cache line, this allows for safe invalidation

# this cache controller does not use locking and/or avoids writer collisions, the writer starting update latest will win, so if writers herd is collected,
# each writer will calculate the new cache datases (if you do not avoid it by specific interlocking of course)
# also, this cache controller does not use any database transactions, completely relying on single requests
# reader and writer collisions cannot happen because writers internally lock the database row in question
# this cache controller uses MySQL specific INSERT ON DUPLICATE KEY UPDATE construct to add cache rows atomically, verifying
# expired cache rows are deleted only on first cache initilization and never afterwards, take note TTL field is stored in microseconds

# why group, key and parameter set? imagine you have different sets of data affecting different pages, where each page caches something for itself
# group specifies set you can invalidate in one go, without thinking which pages cache exactly you do need to invalidate
# key specifies exact data set page needs, i.e. page name, or some specific dataset name if pages use multiple datasets (and all can be invalidated by group)
# parameter set is a dependency, if cache lines should differ by i.e. some user input, you can give caching routines this dataset to distinguish
# parameter set is hashed to quickly retrieve required line from database, but anyways is serialized to be compared inline, so do not make it too big

# typical cache table structure:
# CREATE TABLE `cache` (
#   `id` bigint(20) NOT NULL AUTO_INCREMENT,
#   `group` varchar(80) NOT NULL,
#   `key` varchar(80) NOT NULL,
#   `hash` varchar(80) NOT NULL,
#   `parameters` longblob NOT NULL,
#   `timestamp` bigint(20) NOT NULL,
#   `ttl` bigint(20) NOT NULL,
#   `content` longblob NOT NULL,
#   `updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
#   PRIMARY KEY (`id`),
#   UNIQUE KEY `uidx_group_key_hash` (`group`,`key`,`hash`),
#   KEY `idx_ttl` (`ttl`)
# ) DEFAULT CHARSET=utf8;


# minimal cache table structure:
# CREATE TABLE `cache` (
#   `group` varchar(80) NOT NULL,
#   `key` varchar(80) NOT NULL,
#   `hash` varchar(80) NOT NULL,
#   `parameters` longblob NOT NULL,
#   `timestamp` bigint(20) NOT NULL,
#   `ttl` bigint(20) NOT NULL,
#   `content` longblob NOT NULL,
#   PRIMARY KEY `uidx_group_key_hash` (`group`,`key`,`hash`),
#   KEY `idx_ttl` (`ttl`)
# ) DEFAULT CHARSET=utf8;

interface IMySQLi
{
    public function read($group, $key, $parameters = null, $timestamp = null);
    public function store($group, $key, $parameters = null, $content = null, $timestamp = null, $ttl = null);
    public function readOrCreate($group, $key, $parameters, $createCallback, $timestamp = null, $ttl = null, $createParameters = null);
    public function invalidate($group = null, $key = null, $parameters = null, $timestamp = null);
    public function cleanup();
    public function getCacheStatistics();
}

trait TMySQLi
{
    # changeable outside
    public $defaultTTL = 86400;
    public $readPostprocessor = null;
    public $writePreprocessor = null;
    public $parametersPreprocessor = null;

    # readable outside
    public $lastReadOrCreateCached;

    # database and table
    /** @var MySQLi */ protected $database;
    protected $table;

    # prepared statements
    protected $preparedGet;
    protected $preparedStore;
    protected $preparedInvalidateAll;
    protected $preparedInvalidateGroup;
    protected $preparedInvalidateGroupKey;
    protected $preparedInvalidateGroupHash;
    protected $preparedInvalidateGroupKeyHash;
    protected $preparedInvalidateKey;
    protected $preparedInvalidateKeyHash;
    protected $preparedInvalidateHash;

    # specific constructor
    public function __construct(/** @var MySQLi */ $database, $table = 'cache', $cleanup = false, $defaultTTL = null, $readPostprocessor = null, $writePreprocessor = null, $parametersPreprocessor = null)
    {
        # store parameters
        $this->database = $database;
        $this->table = $table;
        if ($defaultTTL !== null) $this->defaultTTL = $defaultTTL;
        if ($readPostprocessor !== null) $this->readPostprocessor = $readPostprocessor;
        if ($writePreprocessor !== null) $this->writePreprocessor = $writePreprocessor;
        if ($parametersPreprocessor !== null) $this->parametersPreprocessor = $parametersPreprocessor;

        # prepare common statements

        # get
        $query = 'SELECT `parameters`, `content` FROM `'.$this->database->real_escape_string($this->table).'`'.
            ' WHERE (`group` = ?) AND (`key` = ?) AND (`hash` = ?) AND (`ttl` >= 0) AND ((`ttl` = 0) OR ((`timestamp` + `ttl`) > ?))';
        $this->preparedGet = $this->database->prepare($query);

        # store
        $query = 'INSERT INTO `'.$this->database->real_escape_string($this->table).'` (`group`,`key`,`hash`,`parameters`,`timestamp`,`ttl`,`content`)'.
            ' VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE '.
            ' `parameters` = IF((@doUpdate := (`timestamp` < VALUES(`timestamp`))),VALUES(`parameters`),`parameters`)'.
            ',`ttl` = IF(@doUpdate,VALUES(`ttl`),`ttl`)'.
            ',`content` = IF(@doUpdate,VALUES(`content`),`content`)'.
            ',`timestamp` = IF(@doUpdate,VALUES(`timestamp`),`timestamp`)';
        $this->preparedStore = $this->database->prepare($query);

        # invalidate - all
        $query = 'UPDATE `'.$this->database->real_escape_string($this->table).'` SET `timestamp` = ?, `ttl` = -1';
        $this->preparedInvalidateAll = $this->database->prepare($query);

        # invalidate - group
        $query2 = $query.' WHERE (`group` = ?)';
        $this->preparedInvalidateGroup = $this->database->prepare($query2);

        # invalidate - group + key
        $query2 = $query.' WHERE (`group` = ?) AND (`key` = ?)';
        $this->preparedInvalidateGroupKey = $this->database->prepare($query2);

        # invalidate - group + hash
        $query2 = $query.' WHERE (`group` = ?) AND (`hash` = ?)';
        $this->preparedInvalidateGroupHash = $this->database->prepare($query2);

        # invalidate - group + key + hash
        $query2 = $query.' WHERE (`group` = ?) AND (`key` = ?) AND (`hash` = ?)';
        $this->preparedInvalidateGroupKeyHash = $this->database->prepare($query2);

        # invalidate - key
        $query2 = $query.' WHERE (`key` = ?)';
        $this->preparedInvalidateKey = $this->database->prepare($query2);

        # invalidate - key + hash
        $query2 = $query.' WHERE (`key` = ?) AND (`hash` = ?)';
        $this->preparedInvalidateKeyHash = $this->database->prepare($query2);

        # invalidate - hash
        $query2 = $query.' WHERE (`hash` = ?)';
        $this->preparedInvalidateHash = $this->database->prepare($query2);

        # optional startup cleanup
        if ($cleanup) $this->cleanup();
    }

    public function read($group, $key, $parameters = null, $timestamp = null)
    {
        if ($timestamp === null) $timestamp = $this->getNewTimestamp();
        $parametersString = ($parameters !== null) ? (($this->parametersPreprocessor === null) ? serialize($parameters) : ($this->parametersPreprocessor)($parameters)) : '';
        $parametersHash = $this->hashParameters($parametersString);
        $parameters = null; $content = null;
        $this->preparedGet->bind_result($parameters, $content);
        $this->preparedGet->bind_param('sssi', $group, $key, $parametersHash, $timestamp);
        $this->preparedGet->execute();
        $status = $this->preparedGet->fetch();
        $this->preparedGet->free_result();
        if (!$status) return null; # no data
        if ($parameters !== $parametersString) return null; # parameters mismatch
        return ($this->readPostprocessor === null) ? $content : ($this->readPostprocessor)($content);
    }

    public function store($group, $key, $parameters = null, $content = null, $timestamp = null, $ttl = null)
    {
        if ($timestamp === null) $timestamp = $this->getNewTimestamp();
        $parametersString = ($parameters !== null) ? (($this->parametersPreprocessor === null) ? serialize($parameters) : ($this->parametersPreprocessor)($parameters)) : '';
        $parametersHash = $this->hashParameters($parametersString);
        $ttl = $this->adjustTTLFromSeconds($ttl ?? $this->defaultTTL);
        if ($this->writePreprocessor !== null) $content = ($this->writePreprocessor)($content);
        $this->preparedStore->bind_param('ssssiis', $group, $key, $parametersHash, $parametersString, $timestamp, $ttl, $content);
        $this->preparedStore->execute();
    }

    public function readOrCreate($group, $key, $parameters, $createCallback, $timestamp = null, $ttl = null, $createParameters = null)
    {
        $this->lastReadOrCreateCached = true;
        if ($timestamp === null) $timestamp = $this->getNewTimestamp();
        if (($content = $this->read($group, $key, $parameters, $timestamp)) !== null) return $content;
        $this->lastReadOrCreateCached = false;
        $content = $createCallback(...($createParameters ?? $parameters));
        if ($content === null) return null; # nothing to store
        $ttl = $this->adjustTTLFromSeconds($ttl ?? $this->defaultTTL);
        $this->store($group, $key, $parameters, $content, $timestamp, $ttl);
        return $content;
    }

    public function invalidate($group = null, $key = null, $parameters = null, $timestamp = null)
    {
        if ($timestamp === null) $timestamp = $this->getNewTimestamp();

        $hash = null;
        if ($parameters !== null) {
            $parametersString = ($parameters !== null) ? (($this->parametersPreprocessor === null) ? serialize($parameters) : ($this->parametersPreprocessor)($parameters)) : '';
            $hash = $this->hashParameters($parametersString);
        }

        if ($group !== null) {
            if ($key !== null) {
                if ($hash !== null) {
                    $this->preparedInvalidateGroupKeyHash->bind_param('isss', $timestamp, $group, $key, $hash);
                    $this->preparedInvalidateGroupKeyHash->execute();
                } else {
                    $this->preparedInvalidateGroupKey->bind_param('iss', $timestamp, $group, $key);
                    $this->preparedInvalidateGroupKey->execute();
                }
            } elseif ($hash !== null) {
                $this->preparedInvalidateGroupHash->bind_param('iss', $timestamp, $group, $hash);
                $this->preparedInvalidateGroupHash->execute();
            } else {
                $this->preparedInvalidateGroup->bind_param('is', $timestamp, $group);
                $this->preparedInvalidateGroup->execute();
            }
        } elseif ($key !== null) {
            if ($hash !== null) {
                $this->preparedInvalidateKeyHash->bind_param('uss', $timestamp, $key, $hash);
                $this->preparedInvalidateKeyHash->execute();
            } else {
                $this->preparedInvalidateKey->bind_param('is', $timestamp, $key);
                $this->preparedInvalidateKey->execute();
            }
        } elseif ($hash !== null) {
            $this->preparedInvalidateHash->bind_param('is', $timestamp, $hash);
            $this->preparedInvalidateHash->execute();
        } else {
            $this->preparedInvalidateAll->bind_param('i', $timestamp);
            $this->preparedInvalidateAll->execute();
        }
    }

    public function cleanup()
    {
        $query = 'DELETE FROM `'.$this->database->real_escape_string($this->table).'` WHERE (`ttl` < 0) OR ((`ttl` <> 0) AND ((`timestamp` + `ttl`) < '.$this->database->real_escape_string($this->getNewTimestamp()).'))';
        $this->database->query($query);
    }

    public function getCacheStatistics()
    {
        $stats = [];
        $query = 'SELECT `group`, `key`, COUNT(1) AS `count`, SUM(LENGTH(`content`)) AS `size` FROM `'.$this->database->real_escape_string($this->table).'` WHERE (`ttl` >= 0) AND ((`ttl` = 0) OR ((`timestamp` + `ttl`) > '.$this->database->real_escape_string($this->getNewTimestamp()).')) GROUP BY `group`, `key`';
        $result = $this->database->query($query);
        while (is_array($row = $result->fetch_assoc()))
            $stats[$row['group']][$row['key']] = ['count' => $row['count'], 'size' => $row['size']];
        $result->free();
        return $stats;
    }

    protected function hashParameters($parametersString)
    {
        if (empty($parametersString)) return '';
        return hash('sha256', $parametersString);
    }

    protected function adjustTTLFromSeconds($ttl)
    {
        return floor($ttl * 1000000);
    }

    protected function getNewTimestamp()
    {
        return floor(microtime(true) * 1000000);
    }
}

class MySQLi implements \ATL\Cache\IMySQLi { use \ATL\Cache\TMySQLi; }
