<?php

namespace ATL\ObjectStore;

########
# implements RDBMS-based object storage on top of ObjectStore, requires supplementary RDBMS ObjectStore\RDBMS\* driver object to provide escaping and final query building
# elements are normal ObjectStoreItem objects
# loading by keys also places WHERE on key fields, single key field multiple key loads are optimized by IN (), multiple key fields multiple key loads are placed as AND of INs and filtered on load
# if single key field IN optimization is generating queries too large, set singleKeyMultipleLoadCount to numeric value to limit number of IN () elements per query
# for single key field, set singleKeyMultipleLoadCount to false, 0 or 1 to load each item by its own query, for multiple key fields set multipleKeyMultipleLoad to 0 or 1 to load each item by its own query
# by default, when multipleKeyMutipleLoadInOptimization is set to false, multiple multi-field key loads are generated as OR'ed conditions of key fields AND conditions
# if the number of OR'ed key conditions needs to be limited, set multipleKeyMultipleLoadCount to numeric value to limit number of AND'ed conditions in a single query, setting it to false, 0 or 1 will single element per query
# setting multipleKeyMutipleLoadInOptimization to true enables AND'ed IN () optimization for multiple multi-field key loads, where more rows may be queried than loaded
# in the case of IN optimization set multipleKeyMultipleLoadCount to numeric value to limit count of IN () elements per query, setting it to false, 0 or 1 will resort to loading single element per query with just key fields AND condition
# OS_FIELD_STORAGE_FORMULA is available along with OS_FIELD_STORAGE_KEY, providing for use of arbitrary formulas to load the data, OS_FIELD_STORAGE_KEY or field name will still be used for updates if present
# OS_FIELD_STORAGE_FIELD_FORMULAs are appended by AS `field name` in generated queries, where field name is either OS_FIELD_STORAGE_KEY or real field name
# additional set of specific batch load functions is available:
#  loadAll($limit = null, $order = null, $replaceExistingItems = false, $replaceDetach = false, $unbuffered = false, $forUpdate = false) loads all data from the database, an alias to loadByCondition with NULL where
#  loadByCondition($where = null, $limit = null, $order = null, $replaceExistingItems = false, $replaceDetach = false, $unbuffered = false, $forUpdate = false) loads a batch of elements and returns all items loaded
#  loadByQuery($query, $replaceExistingItems = false, $replaceDetach = false, $unbuffered = false, $forUpdate = false) loads a batch of elements by specific query
#   the query must take care of naming fields according to OS_FIELD_STORAGE_KEYs or real field names if OS_FIELD_STORAGE_KEY is not defined
#  loadByFields($fieldValues, $replaceExistingItems = false, $replaceDetach = false, $unbuffered = false, $forUpdate = false) loads items by fields matching specific storage values
#   fields can be any storage loadable fields from the key map, take care storage values will be matched, and not post-load item values
#   all fields specified must match their respective values for item to be loaded, multiple values and OR based loading is not supported, use loadByCondition or loadByQuery for that
#  loadByIndex($index, $indexValues, $replaceExistingItems = false, $replaceDetach = false, $unbuffered = false, $forUpdate = false) loads a batch of elements by specific index fields
#   index is index name, indexValues are fields from the index that are participating in index condition (partial condition may be used, this is basically loadByFields with additional verification fields exist in index)
#   all fields specified must match their respective values for item to be loaded, multiple values and OR based loading is not supported, use loadByCondition or loadByQuery for that
#   a certain another benefit here is you may provide just numbered array of index fields, like you can do with key fields on normal load
# batch functions result arrays keep database order of elements and contain all the loaded items even if they were not replaced, and do not contain any items that were not loaded from the database
# replace and replaceDetach in batch load functions behave exactly like the ObjectStore load options (update or remove existing items)
# take care batch functions never cache their resulting queries, all queries in batch functions are always rebuilt from scratch (not a great penalty, especially given these functions purpose)
# $limit can take three forms: numeric value or array of single numeric value indicating <count>, array of two numeric values indicating <start>,<count>
#  array of array with single value which will be directly passed as LIMIT <non-escaped value> or equivalent
# $order can take three forms: single field name or array containing ObjectStore field names and true/false as values (true means ascending sort order, false means descending sort order), in case of single name ascending order is assumed
#  array of unescaped database (not ObjectStore) field names and strings that will be passed to ORDER BY or equivalent as <escaped field> <escaped string> parameter list
#  array of array with single value which will be directly passed to ORDER BY <non-escaped value> or equivalent
#  array of array protection for unescaped value is deliberate, it prevents your code from occasionally passing some unescaped input to the queries
# directly passing non-escaped values to LIMIT and ORDER BY is not recommended

# if you need something more complex than direct table query in your store, like JOINs and specific INSERT/REPLACE/DELETE handlers for updates, override specific functions to generate queries you need
# field names and table name can be arrays, in this case array elements will be escaped and delimited by points, like ['mydb', 'mytable'] will be converted to `mydb`.`mytable`
# additional arguments to base ObjectStore class like messaging engine can be provided after this class own arguments

interface IRDBMS
{
    const OS_FIELD_STORAGE_SELECT_FORMULA                           = 1001; # provide a query formula (will be passed to query unescaped) to retrieve field value, will be appended with AS `field` on selects
    const OS_FIELD_STORAGE_UPDATE_TRANSFORM_METHOD                  = 1002; # provide a callback to transform storage-converted field into storage formula on UPDATE queries, the result replaces name=value pair and will be passed to query unescaped
    const OS_FIELD_STORAGE_CONDITION_TRANSFORM_METHOD               = 1003; # provide a callback to transform storage-converted field into storage condition formula in all queries, the result will be passed to AND'ed query WHERE part unescaped
    const OS_FIELD_STORAGE_UPDATE_TRANSFORM_METHOD_ARGUMENTS        = 1004; # specifies additional arguments to pass to custom update field transform method
    const OS_FIELD_STORAGE_CONDITION_TRANSFORM_METHOD_ARGUMENTS     = 1006; # specifies additional arguments to pass to custom condition field transform method
    const OS_FIELD_STORAGE_SELECT_PARAMETERS                        = 1007; # provides array of parameters (RDBMS_*_APPEND / RDBMS_*_PREPEND) to apply to SELECT queries
    const OS_FIELD_STORAGE_CREATE_PARAMETERS                        = 1008; # provides array of parameters (RDBMS_*_APPEND / RDBMS_*_PREPEND) to apply to INSERT/REPLACE queries
    const OS_FIELD_STORAGE_UPDATE_PARAMETERS                        = 1009; # provides array of parameters (RDBMS_*_APPEND / RDBMS_*_PREPEND) to apply to UPDATE queries
    const OS_FIELD_STORAGE_UPDATE_CHANGED_PARAMETERS                = 1010; # provides array of parameters (RDBMS_*_APPEND / RDBMS_*_PREPEND) to apply to UPDATE queries, but ONLY if the field is present in changeset, applied AFTER general parameters
    const OS_FIELD_STORAGE_DELETE_PARAMETERS                        = 1011; # provides array of parameters (RDBMS_*_APPEND / RDBMS_*_PREPEND) to apply to DELETE queries
    const OS_FIELD_STORAGE_GENERAL_PARAMETERS                       = 1012; # provides array of parameters (RDBMS_*_APPEND / RDBMS_*_PREPEND) to apply to all queries, applied BEFORE query type specific parameters

    # take care using update transforms effectively disables caching for UPDATE queries and using condition transform on any keys effectively disables IN () optimizations for multiple key loads
    # internal (string defining internal method) callbacks execute in contexts of ObjectStore object, you can freely access $this properties, methods and other elements
    # external callbacks execute in their own context specified by the callback, all callbacks (internal and external) get ObjectStore object as their first argument
    # the second argument to all callbacks is storage-converted field value, the third argument is storage field name, the fourth argument is map field name, the fifth argument is map entry, all additional arguments are passed after that
    # callbacks are expected to return field name=value SET pair or field WHERE condition replacement that will be passed to query unescaped, so take care of escaping storage field name and value inside the generated result
    # all parameters are passed to queries unescaped, not all parameters apply to all queries, check the query definitions below for more information (you can modify them as well)
    # parameter list is available in \ATL\RDBMS\Driver (RDBMS_*_APPEND / RDBMS_*_PREPEND constants), different database drivers may support additional specific parameters, or have no support for some parameters, take care
    # in general, expect that if you use parameters or database-specific transforms in your store, you will be tied to specific RDBMS driver implementation and will not be able to easily switch one RDBMS with another

    # these override driver query templates, leave at null if you are fine with driver defaults (if overriding, you should in general expect to be tied to specific RDBMS driver implementation)
    const selectQuery = null;
    const insertQuery = null;
    const multiInsertQuery = null;
    const multiInsertValueset = null;
    const replaceQuery = null;
    const multiReplaceQuery = null;
    const multiReplaceValueset = null;
    const updateQuery = null;
    const deleteQuery = null;
}

trait TRDBMS
{
    # these are explicitly public so you can use them, although such use exposes implementation details and thus discouraged
    /** @var \ATL\RDBMS\Driver */ public $driver; # you can override constructor to automatically provide this
    public $table; # override this in your store classes to provide default table name

    # some optimization flags are declared as public to allow code to control optimizations at runtime as necessary in corner cases, although such control exposes the implementation details and is strongly discouraged
    public $cacheQueries = true;
    public $cacheUpdateQueries = true;
    public $singleKeyMultipleLoadCount = true;
    public $multipleKeyMultipleLoadCount = true;
    public $multipleKeyMultipleLoadInOptimization = false;
    public $multiInsertReplaceOptimization = true;

    # volatile properties, do not override
    protected $escapedTableName;
    protected $escapedFieldNames = [];
    protected $escapedFieldList;
    protected $escapedFieldExpressions = [];
    protected $escapedFieldExpressionsList;
    protected $hasUpdateTransform = false;
    protected $hasKeyConditionTransform = false;
    protected $hasConditionTransform = false;
    protected $autoincrementField;
    protected $queryCache = [];

    public function __construct($driver, $table = null, ...$args)
    {
        parent::__construct(...$args);
        $this->driver = $driver ?? $this->driver;
        $this->table = $table ?? $this->table;

        # build table name and field lists, also check for specific optimization affecting conditions and transform callbacks to closures
        $this->escapedTableName = $this->driver->escapeTableName($this->table);
        foreach ($this->fieldMap as $field => &$info) {
            if (!(($info[$this::OS_FIELD_FLAGS] ?? 0) & $this::OS_FFLAG_VIRTUAL) || (($info[$this::OS_FIELD_FLAGS] ?? 0) & $this::OS_FFLAG_VIRTUAL_STORED)) {
                $this->escapedFieldNames[$field] = $this->driver->escapeFieldName($info[$this::OS_FIELD_STORAGE_KEY] ?? $field);

                $this->escapedFieldExpressions[$field] = $info[$this::OS_FIELD_STORAGE_SELECT_FORMULA] ?? $this->escapedFieldNames[$field];
                if (isset($info[$this::OS_FIELD_STORAGE_KEY]) || isset($info[$this::OS_FIELD_STORAGE_SELECT_FORMULA]))
                    $this->escapedFieldExpressions[$field] = $this->driver->buildAS($this->escapedFieldExpressions[$field], $this->driver->escapeFieldName($field));

                if (isset($info[$this::OS_FIELD_STORAGE_UPDATE_TRANSFORM_METHOD])) {
                    $this->hasUpdateTransform = true;
                    $info[$this::OS_FIELD_STORAGE_UPDATE_TRANSFORM_METHOD] = Routines::callableToClosure([$this, $info[$this::OS_FIELD_STORAGE_UPDATE_TRANSFORM_METHOD]], true);
                }

                if (isset($info[$this::OS_FIELD_STORAGE_CONDITION_TRANSFORM_METHOD])) {
                    if (($info[$this::OS_FIELD_FLAGS] ?? 0) & $this::OS_FFLAG_KEY) $this->hasKeyConditionTransform = true;
                    $this->hasConditionTransform = true;
                    $info[$this::OS_FIELD_STORAGE_CONDITION_TRANSFORM_METHOD] = Routines::callableToClosure([$this, $info[$this::OS_FIELD_STORAGE_CONDITION_TRANSFORM_METHOD]], true);
                }

                if (($info[$this::OS_FIELD_FLAGS] ?? 0) & $this::OS_FFLAG_KEY_STORAGE_AUTOINCREMENT) {
                    if ($this->autoincrementField !== null) throw new \ATL\ObjectStoreException("Only one key field is allowed to be flagged with `OS_FFLAG_KEY_STORAGE_AUTOINCREMENT`");
                    if (!$this->driver::autoincrementSupported) throw new \ATL\ObjectStoreException("RDBMS driver `".get_class($this->driver)."` does not support key fields marked with `OS_FFLAG_KEY_STORAGE_AUTOINCREMENT`");
                    $this->autoincrementField = $field;
                }
            }
        } unset($info);
        $this->escapedFieldList = implode(',', $this->escapedFieldNames); # build field list for faster access
        $this->escapedFieldExpressionsList = implode(',', $this->escapedFieldExpressions); # build field expressions for faster access
    }

    ########
    # Public API

    public function loadAll($limit = null, $order = null, $replaceExistingItems = false, $replaceDetach = false, $unbuffered = false, $forUpdate = false)
    {
        # a simple alias to condition load
        return $this->loadByCondition('1', $limit, $order, $replaceExistingItems, $replaceDetach, $unbuffered);
    }


    public function loadByCondition($where = null, $limit = null, $order = null, $replaceExistingItems = false, $replaceDetach = false, $unbuffered = false, $forUpdate = false)
    {
        # builds a condition based query and passes it to loadByQuery
        return $this->loadByQuery($this->loadByConditionGetQuery($where, $limit, $order, $forUpdate), $replaceExistingItems, $replaceDetach, $unbuffered);
    }

    protected function loadByConditionGetQuery($where = null, $limit = null, $order = null, $forUpdate = false)
    {
        $query = $this->getCompactedQuery(
            'CUSTOM',
            $this::selectQuery ?? $this->driver::selectQuery,
            [$this->driver::RDBMS_WHERE_CONDITION => true],
            [],
            function ($parameters) use ($limit, $order, $forUpdate) {
                $this->injectMapParameters($parameters, $this::OS_FIELD_STORAGE_GENERAL_PARAMETERS);
                $this->injectMapParameters($parameters, $this::OS_FIELD_STORAGE_SELECT_PARAMETERS);
                $this->injectBaseParameters($parameters);
                if ($limit) {
                    if (!$this->driver::limitSupported) throw new \ATL\ObjectStoreException("Cannot perform SELECT operation having limit with database driver that does not support limits");
                    if (is_array($limit) && (count($limit) > 1) && !$this->driver::limitStartSupported) throw new \ATL\ObjectStoreException("Cannot perform SELECT operation having limit start with database driver that does not support limit start setting");

                    # escaping here is a bit of protection against passing invalid direct values
                    if (is_array($limit)) {
                        if ((count($limit) == 1) && is_array($rlimit = reset($limit))) {
                            # [[$direct_string]]
                            $limit = $rlimit;
                        } else {
                            # [...]
                            $limit = array_map([$this->driver, 'escapeValue'], $limit);
                        }
                    } else {
                        # $count
                        $limit = $this->driver->escapeValue($limit);
                    }
                    $this->driver->mixinLimitParameters($parameters, $limit);
                }
                if ($order) {
                    if (!$this->driver::orderBySupported) throw new \ATL\ObjectStoreException("Cannot perform SELECT operation having order with database driver that does not support ordering");

                    # escaping here is a bit of protection against passing invalid direct values
                    if (is_array($order)) {
                        $dbOrder = [];
                        foreach ($order as $field => $sort) {
                            if (!is_bool($sort)) {
                                $field = $this->driver->escapeValue($field);
                                $sort = $this->driver->escapeValue($sort);
                            } else {
                                $field = isset($this->fieldMap[$field]) ? $this->escapedFieldExpressions[$field] : $this->driver->escapeValue($sort);
                            }
                            $dbOrder[$field] = $sort;
                        }
                    } else {
                        # $field ASC
                        $field = isset($this->fieldMap[$order]) ? $this->escapedFieldExpressions[$order] : $this->driver->escapeValue($order);
                        $dbOrder = [$field => true];
                    }
                    $this->driver->mixinOrderParameters($parameters, $dbOrder);
                }
                if ($forUpdate) $this->driver->mixinSelectForUpdateParameters($parameters);
                return $parameters;
            },
            false
        );

        return $this->driver->buildQuery($query, [$this->driver::RDBMS_WHERE_CONDITION => $where]);
    }

    public function loadByQuery($query, $replaceExistingItems = false, $replaceDetach = false, $unbuffered = false, $forUpdate = false)
    {
        # main query-based loader, preserves order of loaded items, returns all items loaded if they were loaded or already exist in ObjectStore
        $loadedItems = [];
        $this->driver->queryRows($query, function ($row) use (&$loadedItems, $replaceExistingItems, $replaceDetach) {
            $loadedItems[] = $this->loadItemFromStorageData($row, $replaceExistingItems, $replaceDetach);
        });
        return $loadedItems;
    }

    public function loadByFields($fieldValues, $replaceExistingItems = false, $replaceDetach = false, $unbuffered = false, $forUpdate = false)
    {
        # builds a field condition based query and passes it to loadByCondition
        array_walk($fieldValues, function (&$value, $field) {
            if (!isset($this->fieldMap[$field])) throw new \ATL\ObjectStoreException("Field `{$field}` does not exist in ObjectStore `".get_class($this)."`");
            $value = $this->convertItemFieldToStorageData($value, $field);
        });
        return $this->loadByCondition($this->buildSingleKeyCondition($fieldValues), $replaceExistingItems, $replaceDetach, $unbuffered);
    }

    public function loadByIndex($index, $indexValues, $replaceExistingItems = false, $replaceDetach = false, $unbuffered = false, $forUpdate = false)
    {
        # verifies field existence in index, maps numbered fields and returns a field condition load result
        if (!isset($this->indexMap[$index])) throw new \ATL\ObjectStoreException("Index `{$index}` does not exist in ObjectStore `".get_class($this)."`");

        # tricky stuff: we can have numbered or associative key array, so we start with indexed and try to fail back to named
        $fieldMatch = [];
        foreach ($this->indexMap[$index] as $iid => $field) {
            if (array_key_exists($iid, $indexValues)) {
                $fieldMatch[$field] = $indexValues[$iid];
            } elseif (array_key_exists($field, $indexValues)) {
                $fieldMatch[$field] = $indexValues[$field];
            }
        }
        if (count($fieldMatch) == 0) throw new \ATL\ObjectStoreException("No valid fields for index `{$index}` were supplied for ObjectStore `".get_class($this)."` index condition load");

        return $this->loadByFields($fieldMatch, $replaceExistingItems, $replaceDetach, $unbuffered);
    }

    # asynchronous versions of the above

    public function asyncLoadAllTask(/** @var \ATL\Task */ $taskObject, $limit = null, $order = null, $replaceExistingItems = false, $replaceDetach = false, $unbuffered = false, $forUpdate = false)
    {
        if (!$this->driver::asynchronousQuerySupported) return $this->loadAll($limit, $order, $replaceExistingItems, $replaceDetach, $unbuffered, $forUpdate); # in case driver does not support asynchronous queries, resort to synchronous routine

        # a simple alias to condition load task
        return yield new \ATL\Task([$this, 'asyncLoadByConditionTask'], '1', $limit, $order, $replaceExistingItems, $replaceDetach, $unbuffered);
    }

    public function asyncLoadByConditionTask(/** @var \ATL\Task */ $taskObject, $where = null, $limit = null, $order = null, $replaceExistingItems = false, $replaceDetach = false, $unbuffered = false, $forUpdate = false)
    {
        if (!$this->driver::asynchronousQuerySupported) return $this->loadByCondition($where, $limit, $order, $replaceExistingItems, $replaceDetach, $unbuffered, $forUpdate); # in case driver does not support asynchronous queries, resort to synchronous routine

        # builds a condition based query and passes it to asyncLoadByQuery
        return yield new \ATL\Task([$this, 'asyncLoadByQueryTask'], $this->loadByConditionGetQuery($where, $limit, $order, $forUpdate), $replaceExistingItems, $replaceDetach, $unbuffered);
    }

    public function asyncLoadByQueryTask(/** @var \ATL\Task */ $taskObject, $query, $replaceExistingItems = false, $replaceDetach = false, $unbuffered = false, $forUpdate = false)
    {
        if (!$this->driver::asynchronousQuerySupported) return $this->loadByQuery($query, $replaceExistingItems, $replaceDetach, $unbuffered, $forUpdate); # in case driver does not support asynchronous queries, resort to synchronous routine

        # main query-based loader, preserves order of loaded items, returns all items loaded if they were loaded or already exist in ObjectStore
        $loadedItems = [];
        yield new \ATL\Task([$this->driver, 'asyncQueryRowsTask'], $query, function ($row) use (&$loadedItems, $replaceExistingItems, $replaceDetach) {
            $loadedItems[] = $this->loadItemFromStorageData($row, $replaceExistingItems, $replaceDetach);
        });
        return $loadedItems;
    }

    public function asyncLoadByFieldsTask(/** @var \ATL\Task */ $taskObject, $fieldValues, $replaceExistingItems = false, $replaceDetach = false, $unbuffered = false, $forUpdate = false)
    {
        if (!$this->driver::asynchronousQuerySupported) return $this->loadByFields($fieldValues, $replaceExistingItems, $replaceDetach, $unbuffered, $forUpdate); # in case driver does not support asynchronous queries, resort to synchronous routine

        # builds a field condition based query and passes it to loadByCondition
        array_walk($fieldValues, function (&$value, $field) {
            if (!isset($this->fieldMap[$field])) throw new \ATL\ObjectStoreException("Field `{$field}` does not exist in ObjectStore `".get_class($this)."`");
            $value = $this->convertItemFieldToStorageData($value, $field);
        });
        return yield new \ATL\Task([$this, 'asyncLoadByConditionTask'], $this->buildSingleKeyCondition($fieldValues), $replaceExistingItems, $replaceDetach, $unbuffered);
    }

    public function asyncLoadByIndexTask(/** @var \ATL\Task */ $taskObject, $index, $indexValues, $replaceExistingItems = false, $replaceDetach = false, $unbuffered = false, $forUpdate = false)
    {
        if (!$this->driver::asynchronousQuerySupported) return $this->loadByIndex($index, $indexValues, $replaceExistingItems, $replaceDetach, $unbuffered, $forUpdate); # in case driver does not support asynchronous queries, resort to synchronous routine

        # verifies field existence in index, maps numbered fields and returns a field condition load result
        if (!isset($this->indexMap[$index])) throw new \ATL\ObjectStoreException("Index `{$index}` does not exist in ObjectStore `".get_class($this)."`");

        # tricky stuff: we can have numbered or associative key array, so we start with indexed and try to fail back to named
        $fieldMatch = [];
        foreach ($this->indexMap[$index] as $iid => $field) {
            if (array_key_exists($iid, $indexValues)) {
                $fieldMatch[$field] = $indexValues[$iid];
            } elseif (array_key_exists($field, $indexValues)) {
                $fieldMatch[$field] = $indexValues[$field];
            }
        }
        if (count($fieldMatch) == 0) throw new \ATL\ObjectStoreException("No valid fields for index `{$index}` were supplied for ObjectStore `".get_class($this)."` index condition load");

        return yield new \ATL\Task([$this, 'asyncLoadByFieldsTask'], $fieldMatch, $replaceExistingItems, $replaceDetach, $unbuffered);
    }

    ########
    # ObjectStore storage API

    # this helper function is needed to obtain list of queries and key filter to execute for data retrieval, reused in both synchronous and asynchronous routines
    protected function storageLoadGetQueries($selectQuery, $keys, &$keyFilter)
    {
        $queries = [];

        # do we do a simple linear process where we just build conditions with number of keys and load?
        if ($this->singleKeyMap || !$this->multipleKeyMultipleLoadInOptimization || !$this->driver::inSupported || $this->hasKeyConditionTransform) {
            # yes, go for it
            $keys = array_map([$this, 'convertItemDataToStorageData'], $keys); # convert key data to storage data
            $maxKeyCount = $this->singleKeyMap ?
                ($this->singleKeyMultipleLoadCount ? (($this->singleKeyMultipleLoadCount !== true) ? $this->singleKeyMultipleLoadCount : count($keys)) : 1)
                : ($this->multipleKeyMultipleLoadCount ? (($this->multipleKeyMultipleLoadCount !== true) ? $this->multipleKeyMultipleLoadCount : count($keys)) : 1);
            if ($this->hasKeyConditionTransform) $maxKeyCount = 1; # in case of condition transform, we cannot do more than one at a time

            foreach (array_chunk($keys, $maxKeyCount, true) as $keysToLoad)
                $queries[] = $this->driver->buildQuery($selectQuery, [$this->driver::RDBMS_WHERE_CONDITION => $this->buildSimpleCondition($keysToLoad)]);
        } else {
            # we only reach here when we do multiple values IN-optimization for multiple field keys, we detect distinct key values, split them all in batches and process batch by batch filtering the result
            $keyLevels = count($this->keyMap);
            $maxKeyLevel = $keyLevels - 1;
            $maxKeyCount = $this->multipleKeyMultipleLoadCount ? (($this->multipleKeyMultipleLoadCount !== true) ? $this->multipleKeyMultipleLoadCount : count($keys)) : 1;

            # build key filter index (take care: data should be store data, we use serialize() to stringify)
            $keyFilter = [];
            foreach ($keys as $keyID => $key)
                foreach ($key as $field => $value)
                    $keyFilter[$field][serialize($value)] = $keyID; # actually any unique value per key does it for checks, so just array index is fine

            $keys = array_map([$this, 'convertItemDataToStorageData'], $keys); # convert key data to storage data

            # build distinct key values lists
            $distinctValues = [];
            foreach ($keys as $key) {
                $level = 0;
                foreach ($key as $value) {
                    $distinctValues[$level][$value] = $value;
                    $level++;
                }
            }
            for ($i = 0; $i < $keyLevels; $i++)
                $distinctValues[$i] = array_chunk($distinctValues[$i], $maxKeyCount);

            # process keys level by level, querying per key set
            $processValues = $distinctValues;
            $currentLevel = 0;
            $currentKeys = [];
            do {
                # start level processing
                $currentKeys[$currentLevel] = array_pop($processValues[$currentLevel]);

                # at the last level?
                if ($currentLevel == $maxKeyLevel) {
                    $queries[] = $this->driver->buildQuery($selectQuery, [
                            $this->driver::RDBMS_WHERE_CONDITION => $this->driver->buildAND(array_map(function ($level, $values) {
                                return $this->driver->buildIN($this->escapedFieldNames[$this->keyMap[$level]], $values);
                            }, array_keys($currentKeys), $currentKeys))
                        ]);
                } else {
                    # no, go to the next level
                    $currentLevel++;
                    continue;
                }

                # check if the current level has ended (except level 0)
                if (($currentLevel > 0) && (count($processValues[$currentLevel]) == 0)) {
                    # yes, this level has ended, restore its values and go up a level
                    $processValues[$currentLevel] = $distinctValues[$currentLevel];
                    $currentLevel--;
                }
            } while (($currentLevel > 0) || (count($processValues[$currentLevel]) > 0));
        }

        return $queries;
    }

    protected function storageLoad($keys, $replaceExistingItems, $replaceDetach, $forUpdate)
    {
        if ($this->emptyKeyMap) throw new \ATL\ObjectStoreException("Cannot perform SELECT operation on ObjectStore with no keys defined, use bulk load operations to handle such ObjectStore data");
        if (count($keys) == 0) return; # nothing to load

        if (!$replaceExistingItems) {
            # filter out anything we do not need to load there
            $keys = array_filter($keys, function ($key) { return ($this->getIndexElement($this->key, $key) === null); });
            if (count($keys) == 0) return; # nothing to load anymore
        }

        # get compacted SELECT query template
        $selectQuery = $this->getCompactedSelectQuery($forUpdate);

        # get queries to execute and possibly key filter
        $keyFilter = null;
        $queries = $this->storageLoadGetQueries($selectQuery, $keys, $keyFilter);

        # perform queries
        if ($keyFilter === null) {
            # simple
            foreach ($queries as $query) {
                $this->driver->queryRows(
                    $query,
                    function ($row) use ($replaceExistingItems, $replaceDetach) { $this->loadItemFromStorageData($row, $replaceExistingItems, $replaceDetach); }
                );
            }
        } else {
            # filtered by key filter
            foreach ($queries as $query) {
                $this->driver->queryRows(
                    $query,
                    function ($row) use ($replaceExistingItems, $replaceDetach, $keyFilter) {
                        # check key filter
                        foreach ($this->keyMap as $id => $field) {
                            $value = serialize($row[$field]); # as the data can be arbitrary, we need to serialize
                            $kfData = ($id == 0) ? ($keyFilter[$field][$value] ?? []) : array_intersect($kfData, $keyFilter[$field][$value]);
                            if (count($kfData) == 0) return; # not in key filter
                        }
                        $this->loadItemFromStorageData($row, $replaceExistingItems, $replaceDetach);
                    }
                );
            }
        }
    }

    # this helper is reused in multiple places to check for existing transaction and optionally start internal transaction on updates, returns transaction manager if internal transaction was started, false otherwise
    protected function storageCRUDAttemptInternalTransaction()
    {
        # we want transaction to be opened in transaction manager, check if we are already in transaction and open one if requested
        $transactionManager = $this->driver->tmGetTransactionManager();
        if (($transactionManager === null) || $transactionManager->isInTransaction()) return false; # no transaction manager or already in transaction
        $transactionManager->startTransaction();
        return $transactionManager;
    }

    # this helper function is needed to obtain list of queries to execute for item creation, reused in both synchronous and asynchronous routines
    # it can return either string query or array of [query, item, field name] in case we need to fill auto-increment field back to the item
    protected function storageCreateGetQueries($items, $replace, &$wantsTransaction)
    {
        $queries = [];

        $wantsTransaction = false;
        $emulatedReplace = false;
        $canOptimize = (count($items) > 1) && $this->multiInsertReplaceOptimization && ($replace ? $this->driver::multiReplaceSupported : $this->driver::multiInsertSupported);
        $autoincrementField = $this->autoincrementField;

        # there is a tricky condition: if we can optimize, but any of the items autoincrement field values is null, we cannot optimize
        if ($canOptimize && ($autoincrementField !== null)) {
            foreach ($items as $item) {
                if ($item->$autoincrementField === null) {
                    $canOptimize = false;
                    break;
                }
            }
        }

        # start the query build operation
        if ($replace && !$this->driver::replaceSupported) {
            $replace = false;
            $emulatedReplace = true;
            if ($this->emptyKeyMap) throw new \ATL\ObjectStoreException("Cannot perform REPLACE operation on ObjectStore with no keys defined and no database support for REPLACE, use direct query operations to handle such sources");

            # as we are doing replace but replace is not supported, get compacted DELETE query template
            $deleteQuery = $this->getCompactedDeleteQuery();

            # as dual DELETE/INSERT queries are going to proceed, we utilize driver transaction manager to do operation in a single transaction if transaction is not already started
            $wantsTransaction = true;
        }

        if (!$canOptimize) {
            # normal unoptimized item by item insert/replace
            if (!$wantsTransaction && (count($items) > 1)) $wantsTransaction = true;  # as multiple queries are going to proceed, we utilize driver transaction manager to do operation in a single transaction if transaction is not already started

            # get compacted INSERT/REPLACE query template
            $insertReplaceQuery = $replace ? $this->getCompactedReplaceQuery() : $this->getCompactedInsertQuery();

            foreach ($items as $iid => $item) {
                if ($emulatedReplace) # we need to emulate replace by doing DELETE and INSERT
                    $queries[] = $this->driver->buildQuery($deleteQuery, [$this->driver::RDBMS_WHERE_CONDITION => $this->buildSingleKeyCondition($this->convertItemDataToStorageData($item->getObjectStoreItemIndexData()))]);

                # and now the actual INSERT/REPLACE goes
                $changes = $item->getObjectStoreItemStorageData();
                $query = $this->driver->buildQuery($insertReplaceQuery, [
                    $this->driver::RDBMS_FIELD_VALUES => implode(',', $changes),
                    $this->driver::RDBMS_FIELD_CHANGES => implode(',', array_map(function ($f, $v) { return $this->driver->buildSETKV($this->escapedFieldNames[$f], $v); }, array_keys($changes), $changes)),
                ]);

                # need autoincrement?
                $queries[] = (($autoincrementField !== null) && ($item->$autoincrementField === null)) ? [$query, $item, $autoincrementField] : $query;
            }
        } else {
            # optimized INSERT/REPLACE using multiple value sets
            if ($emulatedReplace) {
                # we need to emulate replace by doing DELETE and INSERT, but we can also do optimized DELETE query there
                # SELECT-specific filtered cross-IN key optimization does not apply there though
                $keys = array_map([$this, 'convertItemDataToStorageData'], array_map(function ($item) { return $item->getObjectStoreItemIndexData(); }, $items)); # convert item key data to storage data
                $maxKeyCount = $this->singleKeyMap ?
                    ($this->singleKeyMultipleLoadCount ? (($this->singleKeyMultipleLoadCount !== true) ? $this->singleKeyMultipleLoadCount : count($keys)) : 1)
                    : ($this->multipleKeyMultipleLoadCount ? (($this->multipleKeyMultipleLoadCount !== true) ? $this->multipleKeyMultipleLoadCount : count($keys)) : 1);
                if ($this->hasKeyConditionTransform) $maxKeyCount = 1; # in case of condition transform, we cannot do more than one at a time

                if (!$wantsTransaction && (count($keys) > $maxKeyCount)) $wantsTransaction = true; # as multiple queries are going to proceed, we utilize driver transaction manager to do operation in a single transaction if transaction is not already started

                foreach (array_chunk($keys, $maxKeyCount, true) as $keysToDelete)
                    $queries[] = $this->driver->buildQuery($deleteQuery, [$this->driver::RDBMS_WHERE_CONDITION => $this->buildSimpleCondition($keysToDelete)]);
            }

            # now perform optimized INSERT/REPLACE operation, building multiple field value sets
            $maxItemCount = $this->multiInsertReplaceOptimization ? (($this->multiInsertReplaceOptimization !== true) ? $this->multiInsertReplaceOptimization : count($items)) : 1;

            if (!$wantsTransaction && (count($items) > $maxItemCount)) $wantsTransaction = true; # as multiple queries are going to proceed, we utilize driver transaction manager to do operation in a single transaction if transaction is not already started

            # get compacted INSERT/REPLACE query template and valueset template
            $multiInsertReplaceQuery = $replace ? $this->getCompactedMultiReplaceQuery() : $this->getCompactedMultiInsertQuery();
            $multiInsertReplaceValueset = $replace ? $this->getCompactedMultiReplaceValueset() : $this->getCompactedMultiInsertValueset();

            # do the actual queries
            foreach (array_chunk($items, $maxItemCount, true) as $itemsToInsertReplace) {
                $queries[] = $this->driver->buildQuery($multiInsertReplaceQuery, [
                    $this->driver::RDBMS_MULTI_VALUESET => implode(',', array_map(function ($item) use ($multiInsertReplaceValueset) {
                        $changes = $item->getObjectStoreItemStorageData();
                        return $this->driver->buildQuery($multiInsertReplaceValueset, [
                            $this->driver::RDBMS_FIELD_VALUES => implode(',', $changes),
                            $this->driver::RDBMS_FIELD_CHANGES => implode(',', array_map(function ($f, $v) { return $this->driver->buildSETKV($this->escapedFieldNames[$f], $v); }, array_keys($changes), $changes)),
                        ]);
                    }, $items)),
                ]);
            }
        }

        return $queries;
    }

    protected function storageCreate($items, $replace)
    {
        if (count($items) == 0) return; # nothing to create

        $wantsTransaction = false;
        $queries = $this->storageCreateGetQueries($items, $replace, $wantsTransaction);
        $transactionManager = $wantsTransaction ? $this->storageCRUDAttemptInternalTransaction() : null;

        # execute queries, we need a try-catch block because we may have our own internal transaction we need to rollback in case of errors
        try {
            foreach ($queries as $query) {
                if (!is_array($query)) {
                    # normal query
                    $this->driver->query($query);
                } else {
                    # we need to fill auto-increment field back
                    $this->driver->query($query[0]);
                    if (($id = $this->driver->getInsertID()) === false) throw new \ATL\ObjectStoreException("Expected autoincrement ID from storage driver, but got none");
                    $autoincrementField = $query[2];
                    $query[1]->$autoincrementField = $id;
                }
            }
        } catch (\Exception $e) {
            try {
                if ($transactionManager) $transactionManager->rollbackTransaction(); # roll our own transaction back if we opened it earlier
            } catch (\Exception $e) { } # ignore rollback exceptions
            throw $e;
        }
        if ($transactionManager) $transactionManager->commitTransaction(); # commit our own transaction if we opened it earlier
    }

    # this helper function is needed to obtain list of queries to execute for item creation, reused in both synchronous and asynchronous routines
    protected function storageUpdateGetQueries($items, $keys, $changes, &$wantsTransaction)
    {
        $queries = [];

        $wantsTransaction = false;
        if (count($items) > 1) $wantsTransaction = true; # as multiple queries are going to proceed, we utilize driver transaction manager to do operation in a single transaction if transaction is not already started

        # prepare common parameters for compacting per-item queries
        $parameters = [];
        $this->injectMapParameters($parameters, $this::OS_FIELD_STORAGE_GENERAL_PARAMETERS);
        $this->injectMapParameters($parameters, $this::OS_FIELD_STORAGE_UPDATE_PARAMETERS);
        $this->injectBaseParameters($parameters);
        $cacheQueries = $this->cacheQueries && $this->cacheUpdateQueries && !$this->hasUpdateTransform;

        # item by item update
        foreach ($items as $iid => $item) {
            if (count($changes[$iid]) == 0) continue; # nothing to do for this item
            array_walk($changes[$iid], function (&$value, $field) use ($item) { $value = $item->$field; });
            $changeKeys = array_keys($changes[$iid]);
            $changeValues = $this->convertItemDataToStorageData($changes[$iid]);

            # retrieve compacted query from cache or compact a query
            $queries[] = $this->driver->buildQuery($this->getCompactedUpdateQuery($changes[$iid], $parameters, false, $cacheQueries), [
                $this->driver::RDBMS_FIELD_VALUES => implode(',', array_map(function ($field, $value) {
                    if (!isset($this->fieldMap[$field][$this::OS_FIELD_STORAGE_UPDATE_TRANSFORM_METHOD])) {
                        return $value;
                    } else {
                        return $this->fieldMap[$field][$this::OS_FIELD_STORAGE_UPDATE_TRANSFORM_METHOD]($this, $value, $this->escapedFieldNames[$field], $field, $this->fieldMap[$field], ...($this->fieldMap[$field][$this::OS_FIELD_STORAGE_UPDATE_TRANSFORM_METHOD_ARGUMENTS] ?? []));
                    }
                }, $changeKeys, $changeValues)),
                $this->driver::RDBMS_FIELD_CHANGES => implode(',', array_map(function ($field, $value) {
                    if (!isset($this->fieldMap[$field][$this::OS_FIELD_STORAGE_UPDATE_TRANSFORM_METHOD])) {
                        return $this->driver->buildSETKV($this->escapedFieldNames[$field], $value);
                    } else {
                        return $this->fieldMap[$field][$this::OS_FIELD_STORAGE_UPDATE_TRANSFORM_METHOD]($this, $value, $this->escapedFieldNames[$field], $field, $this->fieldMap[$field], ...($this->fieldMap[$field][$this::OS_FIELD_STORAGE_UPDATE_TRANSFORM_METHOD_ARGUMENTS] ?? []));
                    }
                }, $changeKeys, $changeValues)),
                $this->driver::RDBMS_WHERE_CONDITION => $this->buildSingleKeyCondition($this->convertItemDataToStorageData($keys[$iid])),
            ]);
        }

        return $queries;
    }

    protected function storageUpdate($items, $keys, $changes)
    {
        if ($this->emptyKeyMap) throw new \ATL\ObjectStoreException("Cannot perform UPDATE operation on ObjectStore with no keys defined, use direct query operations to handle such sources");
        if (count($items) == 0) return; # nothing to update

        $wantsTransaction = false;
        $queries = $this->storageUpdateGetQueries($items, $keys, $changes, $wantsTransaction);
        $transactionManager = $wantsTransaction ? $this->storageCRUDAttemptInternalTransaction() : null;

        # execute queries, we need a try-catch block because we may have our own internal transaction we need to rollback in case of errors
        try {
            foreach ($queries as $query)
                $this->driver->query($query);
        } catch (\Exception $e) {
            try {
                if ($transactionManager) $transactionManager->rollbackTransaction(); # roll our own transaction back if we opened it earlier
            } catch (\Exception $e) { } # ignore rollback exceptions
            throw $e;
        }
        if ($transactionManager) $transactionManager->commitTransaction(); # commit our own transaction if we opened it earlier
    }

    # this helper function is needed to obtain list of queries to execute for item creation, reused in both synchronous and asynchronous routines
    protected function storageDeleteGetQueries($items, $keys, &$wantsTransaction)
    {
        $queries = [];

        # get compacted DELETE query
        $parameters = [];
        $this->injectMapParameters($parameters, $this::OS_FIELD_STORAGE_GENERAL_PARAMETERS);
        $this->injectMapParameters($parameters, $this::OS_FIELD_STORAGE_DELETE_PARAMETERS);
        $this->injectBaseParameters($parameters);
        $deleteQuery = $this->getCompactedDeleteQuery();

        # SELECT-like key optimization, except for the cross-in optimization does not apply there
        $keys = array_map([$this, 'convertItemDataToStorageData'], $keys); # convert key data to storage data
        $maxKeyCount = $this->singleKeyMap ?
            ($this->singleKeyMultipleLoadCount ? (($this->singleKeyMultipleLoadCount !== true) ? $this->singleKeyMultipleLoadCount : count($keys)) : 1)
            : ($this->multipleKeyMultipleLoadCount ? (($this->multipleKeyMultipleLoadCount !== true) ? $this->multipleKeyMultipleLoadCount : count($keys)) : 1);
        if ($this->hasKeyConditionTransform) $maxKeyCount = 1; # in case of condition transform, we cannot do more than one at a time

        $wantsTransaction = false;
        if (count($keys) > $maxKeyCount) $wantsTransaction = true; # as multiple queries are going to proceed, we utilize driver transaction manager to do operation in a single transaction if transaction is not already started

        foreach (array_chunk($keys, $maxKeyCount, true) as $keysToDelete)
            $queries[] = $this->driver->buildQuery($deleteQuery, [$this->driver::RDBMS_WHERE_CONDITION => $this->buildSimpleCondition($keysToDelete)]);

        return $queries;
    }

    protected function storageDelete($items, $keys)
    {
        if ($this->emptyKeyMap) throw new \ATL\ObjectStoreException("Cannot perform DELETE operation on ObjectStore with no keys defined, use direct query operations to handle such sources");
        if (count($items) == 0) return; # nothing to delete

        $wantsTransaction = false;
        $queries = $this->storageDeleteGetQueries($items, $keys, $wantsTransaction);
        $transactionManager = $wantsTransaction ? $this->storageCRUDAttemptInternalTransaction() : null;

        # execute queries, we need a try-catch block because we may have our own internal transaction we need to rollback in case of errors
        try {
            foreach ($queries as $query)
                $this->driver->query($query);
        } catch (\Exception $e) {
            try {
                if ($transactionManager) $transactionManager->rollbackTransaction(); # roll our own transaction back if we opened it earlier
            } catch (\Exception $e) { } # ignore rollback exceptions
            throw $e;
        }
        if ($transactionManager) $transactionManager->commitTransaction(); # commit our own transaction if we opened it earlier
    }

    ########
    # ObjectStore asynchronous storage API

    public function asyncStorageLoadTask(/** @var \ATL\Task */ $taskObject, $keys, $replaceExistingItems, $replaceDetach, $forUpdate)
    {
        if (!$this->driver::asynchronousQuerySupported) {
            # no support for asynchronous queries by RDBMS driver, perform query synchronously using storageLoad
            $this->storageLoad($keys, $replaceExistingItems, $replaceDetach, $forUpdate);
            return true;
        }

        if ($this->emptyKeyMap) throw new \ATL\ObjectStoreException("Cannot perform SELECT operation on ObjectStore with no keys defined, use bulk load operations to handle such ObjectStore data");
        if (count($keys) == 0) return true; # nothing to load

        if (!$replaceExistingItems) {
            # filter out anything we do not need to load there
            $keys = array_filter($keys, function ($key) { return ($this->getIndexElement($this->key, $key) === null); });
            if (count($keys) == 0) return true; # nothing to load anymore
        }

        # get compacted SELECT query template
        $selectQuery = $this->getCompactedSelectQuery($forUpdate);

        # get queries to execute and possibly key filter
        $keyFilter = null;
        $queries = $this->storageLoadGetQueries($selectQuery, $keys, $keyFilter);

        # perform queries asynchronously
        if ($keyFilter === null) {
            # simple
            foreach ($queries as $query) {
                yield new \ATL\Task(
                    [$this->driver, 'asyncQueryRowsTask'],
                    $query,
                    function ($row) use ($replaceExistingItems, $replaceDetach) { $this->loadItemFromStorageData($row, $replaceExistingItems, $replaceDetach); }
                );
            }
        } else {
            # filtered by key filter
            foreach ($queries as $query) {
                yield new \ATL\Task(
                    [$this->driver, 'asyncQueryRowsTask'],
                    $query,
                    function ($row) use ($replaceExistingItems, $replaceDetach, $keyFilter) {
                        # check key filter
                        foreach ($this->keyMap as $id => $field) {
                            $value = serialize($row[$field]); # as the data can be arbitrary, we need to serialize
                            $kfData = ($id == 0) ? ($keyFilter[$field][$value] ?? []) : array_intersect($kfData, $keyFilter[$field][$value]);
                            if (count($kfData) == 0) return; # not in key filter
                        }
                        $this->loadItemFromStorageData($row, $replaceExistingItems, $replaceDetach);
                    }
                );
            }
        }

        return true; # inform caller task we are done
    }

    public function asyncStorageCreateTask(/** @var \ATL\Task */ $taskObject, $items, $replace)
    {
        if (!$this->driver::asynchronousQuerySupported) {
            # no support for asynchronous queries by RDBMS driver, perform query synchronously using storageCreate
            $this->storageCreate($items, $replace);
            return true;
        }

        if (count($items) == 0) return true; # nothing to create

        $wantsTransaction = false;
        $queries = $this->storageCreateGetQueries($items, $replace, $wantsTransaction);
        $transactionManager = $wantsTransaction ? $this->storageCRUDAttemptInternalTransaction() : null;

        # execute queries, we need a try-catch block because we may have our own internal transaction we need to rollback in case of errors
        try {
            foreach ($queries as $query) {
                if (!is_array($query)) {
                    # normal query
                    yield new \ATL\Task([$this->driver, 'asyncQueryTask'], $query);
                } else {
                    # we need to fill auto-increment field back
                    yield new \ATL\Task([$this->driver, 'asyncQueryTask'], $query[0]);
                    if (($id = $this->driver->getInsertID()) === false) throw new \ATL\ObjectStoreException("Expected autoincrement ID from storage driver, but got none");
                    $autoincrementField = $query[2];
                    $query[1]->$autoincrementField = $id;
                }
            }
        } catch (\Exception $e) {
            try {
                if ($transactionManager) $transactionManager->rollbackTransaction(); # roll our own transaction back if we opened it earlier
            } catch (\Exception $e) { } # ignore rollback exceptions
            throw $e;
        }
        if ($transactionManager) $transactionManager->commitTransaction(); # commit our own transaction if we opened it earlier

        return true; # inform caller task we are done
    }

    public function asyncStorageUpdateTask(/** @var \ATL\Task */ $taskObject, $items, $keys, $changes)
    {
        if (!$this->driver::asynchronousQuerySupported) {
            # no support for asynchronous queries by RDBMS driver, perform query synchronously using storageUpdate
            $this->storageUpdate($items, $keys, $changes);
            return true;
        }

        if ($this->emptyKeyMap) throw new \ATL\ObjectStoreException("Cannot perform UPDATE operation on ObjectStore with no keys defined, use direct query operations to handle such sources");
        if (count($items) == 0) return true; # nothing to update

        $wantsTransaction = false;
        $queries = $this->storageUpdateGetQueries($items, $keys, $changes, $wantsTransaction);
        $transactionManager = $wantsTransaction ? $this->storageCRUDAttemptInternalTransaction() : null;

        # execute queries, we need a try-catch block because we may have our own internal transaction we need to rollback in case of errors
        try {
            foreach ($queries as $query)
                yield new \ATL\Task([$this->driver, 'asyncQueryTask'], $query);
        } catch (\Exception $e) {
            try {
                if ($transactionManager) $transactionManager->rollbackTransaction(); # roll our own transaction back if we opened it earlier
            } catch (\Exception $e) { } # ignore rollback exceptions
            throw $e;
        }
        if ($transactionManager) $transactionManager->commitTransaction(); # commit our own transaction if we opened it earlier

        return true; # inform caller task we are done
    }

    public function asyncStorageDeleteTask(/** @var \ATL\Task */ $taskObject, $items, $keys)
    {
        if (!$this->driver::asynchronousQuerySupported) {
            # no support for asynchronous queries by RDBMS driver, perform query synchronously using storageDelete
            $this->storageDelete($items, $keys);
            return true;
        }

        if ($this->emptyKeyMap) throw new \ATL\ObjectStoreException("Cannot perform DELETE operation on ObjectStore with no keys defined, use direct query operations to handle such sources");
        if (count($items) == 0) return true; # nothing to delete

        $wantsTransaction = false;
        $queries = $this->storageDeleteGetQueries($items, $keys, $wantsTransaction);
        $transactionManager = $wantsTransaction ? $this->storageCRUDAttemptInternalTransaction() : null;

        # execute queries, we need a try-catch block because we may have our own internal transaction we need to rollback in case of errors
        try {
            foreach ($queries as $query)
                yield new \ATL\Task([$this->driver, 'asyncQueryTask'], $query);
        } catch (\Exception $e) {
            try {
                if ($transactionManager) $transactionManager->rollbackTransaction(); # roll our own transaction back if we opened it earlier
            } catch (\Exception $e) { } # ignore rollback exceptions
            throw $e;
        }
        if ($transactionManager) $transactionManager->commitTransaction(); # commit our own transaction if we opened it earlier

        return true; # inform caller task we are done
    }

    ########
    # Internal RDBMS API

    # Escaping values

    protected function escapeStorageValue($value) { return $this->driver->escapeValue($value); }
    protected function escapeStorageString($value) { return $this->driver->escapeStringValue($value); }
    protected function escapeStorageBLOB($value) { return $this->driver->escapeBinaryValue($value); }

    # Condition building

    protected function buildSimpleCondition($escapedKeys)
    {
        # escape key data
        if (count($escapedKeys) == 1) return '('.$this->buildSingleKeyCondition(reset($escapedKeys)).')'; # just a single key so very very simple

        # multiple load optimization generator (take care this routine does not account for any optimization limits, just does it)
        if ($this->singleKeyMap && $this->driver::inSupported) {
            # single field key, so IN () optimization
            return '('.$this->driver->buildIN($this->escapedFieldNames[reset($this->keyMap)], array_map('reset', $escapedKeys)).')';
        } else {
            # multiple field key or no IN optimization possible, OR bundle (we do not use this condition builder for specific cross-IN optimization)
            return '('.$this->driver->buildOR(array_map([$this, 'buildSingleKeyCondition'], $escapedKeys)).')';
        }
    }

    protected function buildSingleKeyCondition($escapedKey)
    {
        array_walk($escapedKey, function (&$value, $field) {
            $value = (!isset($this->fieldMap[$field][$this::OS_FIELD_STORAGE_CONDITION_TRANSFORM_METHOD])) ?
                $this->driver->buildEQ($this->escapedFieldNames[$field], $value)
                : $this->fieldMap[$field][$this::OS_FIELD_STORAGE_CONDITION_TRANSFORM_METHOD]($this, $value, $this->escapedFieldNames[$field], $field, $this->fieldMap[$field], ...($this->fieldMap[$field][$this::OS_FIELD_STORAGE_CONDITION_TRANSFORM_METHOD_ARGUMENTS] ?? []));
        });
        return (count($escapedKey) == 1) ? reset($escapedKey) : '('.$this->driver->buildAND($escapedKey).')';
    }

    # Query building

    protected function getCompactedQuery($cacheKey, $queryArray, $dynamicParameters, $staticParameters, $staticParametersBuilderCallback, $cache = true)
    {
        if (!$this->cacheQueries) $cache = false;
        if ($cache && isset($this->queryCache[$cacheKey])) return $this->queryCache[$cacheKey]; # cached
        $staticParameters = array_map(function ($ms) { return is_array($ms) ? implode(' ', array_map('trim', $ms)) : trim($ms); }, $staticParametersBuilderCallback($staticParameters));

        # compact/optimize query so it includes only dynamic fields we are going to use
        $compactedArray = [];
        $newElement = [];
        foreach ($queryArray as $element) {
            if (!is_string($element)) {
                if (isset($dynamicParameters[$element])) {
                    # encountered dynamic parameter element which must be left as is
                    if (count($newElement) > 0) {
                        $compactedArray[] = implode(' ', $newElement);
                        $newElement = [];
                    }
                    $compactedArray[] = $element;
                    continue;
                } elseif (isset($staticParameters[$element])) {
                    $element = $staticParameters[$element];
                } else {
                    continue;
                }
            }
            $newElement[] = $element;
        }
        if (count($newElement) > 0) $compactedArray[] = implode(' ', array_filter(array_map('trim', $newElement), function ($v) { return $v !== ''; }));

        if ($cache) $this->queryCache[$cacheKey] = $compactedArray; # store to cache
        return $compactedArray;
    }

    protected function getCompactedSelectQuery($forUpdate = false)
    {
        if ($forUpdate && !$this->driver::selectForUpdateSupported) throw new \ATL\ObjectStoreException("Cannot perform transactional SELECT FOR UPDATE operation on ObjectStore with driver not supporting transactional SELECT FOR UPDATE");
        return $this->getCompactedQuery(
            'SELECT'.($forUpdate ? ' FOR UPDATE' : ''),
            $this::selectQuery ?? $this->driver::selectQuery,
            [$this->driver::RDBMS_WHERE_CONDITION => true],
            [],
            function ($parameters) use ($forUpdate) {
                $this->injectMapParameters($parameters, $this::OS_FIELD_STORAGE_GENERAL_PARAMETERS);
                $this->injectMapParameters($parameters, $this::OS_FIELD_STORAGE_SELECT_PARAMETERS);
                $this->injectBaseParameters($parameters);
                if ($forUpdate) $this->driver->mixinSelectForUpdateParameters($parameters);
                return $parameters;
            }
        );
    }

    protected function getCompactedInsertQuery()
    {
        return $this->getCompactedQuery(
            'INSERT',
            $this::insertQuery ?? $this->driver::insertQuery,
            [$this->driver::RDBMS_FIELD_VALUES => true, $this->driver::RDBMS_FIELD_CHANGES => true],
            [],
            function ($parameters) {
                $this->injectMapParameters($parameters, $this::OS_FIELD_STORAGE_GENERAL_PARAMETERS);
                $this->injectMapParameters($parameters, $this::OS_FIELD_STORAGE_CREATE_PARAMETERS);
                $this->injectBaseParameters($parameters);
                return $parameters;
            }
        );
    }

    protected function getCompactedReplaceQuery()
    {
        return $this->getCompactedQuery(
            'REPLACE',
            $this::replaceQuery ?? $this->driver::replaceQuery,
            [$this->driver::RDBMS_FIELD_VALUES => true, $this->driver::RDBMS_FIELD_CHANGES => true],
            [],
            function ($parameters) {
                $this->injectMapParameters($parameters, $this::OS_FIELD_STORAGE_GENERAL_PARAMETERS);
                $this->injectMapParameters($parameters, $this::OS_FIELD_STORAGE_CREATE_PARAMETERS);
                $this->injectBaseParameters($parameters);
                return $parameters;
            }
        );
    }

    protected function getCompactedMultiInsertQuery()
    {
        return $this->getCompactedQuery(
            'INSERT:MULTI',
            $this::multiInsertQuery ?? $this->driver::multiInsertQuery,
            [$this->driver::RDBMS_MULTI_VALUESET => true],
            [],
            function ($parameters) {
                $this->injectMapParameters($parameters, $this::OS_FIELD_STORAGE_GENERAL_PARAMETERS);
                $this->injectMapParameters($parameters, $this::OS_FIELD_STORAGE_CREATE_PARAMETERS);
                $this->injectBaseParameters($parameters);
                return $parameters;
            }
        );
    }

    protected function getCompactedMultiReplaceQuery()
    {
        return $this->getCompactedQuery(
            'REPLACE:MULTI',
            $this::multiReplaceQuery ?? $this->driver::multiReplaceQuery,
            [$this->driver::RDBMS_MULTI_VALUESET => true],
            [],
            function ($parameters) {
                $this->injectMapParameters($parameters, $this::OS_FIELD_STORAGE_GENERAL_PARAMETERS);
                $this->injectMapParameters($parameters, $this::OS_FIELD_STORAGE_CREATE_PARAMETERS);
                $this->injectBaseParameters($parameters);
                return $parameters;
            }
        );
    }

    protected function getCompactedMultiInsertValueset()
    {
        return $this->getCompactedQuery(
            'INSERT:MULTI:VALUESET',
            $this::insertQuery ?? $this->driver::insertQuery,
            [$this->driver::RDBMS_FIELD_VALUES => true, $this->driver::RDBMS_FIELD_CHANGES => true],
            [],
            function ($parameters) {
                $this->injectMapParameters($parameters, $this::OS_FIELD_STORAGE_GENERAL_PARAMETERS);
                $this->injectMapParameters($parameters, $this::OS_FIELD_STORAGE_CREATE_PARAMETERS);
                $this->injectBaseParameters($parameters);
                return $parameters;
            }
        );
    }

    protected function getCompactedMultiReplaceValueset()
    {
        return $this->getCompactedQuery(
            'REPLACE:MULTI:VALUESET',
            $this::replaceQuery ?? $this->driver::replaceQuery,
            [$this->driver::RDBMS_FIELD_VALUES => true, $this->driver::RDBMS_FIELD_CHANGES => true],
            [],
            function ($parameters) {
                $this->injectMapParameters($parameters, $this::OS_FIELD_STORAGE_GENERAL_PARAMETERS);
                $this->injectMapParameters($parameters, $this::OS_FIELD_STORAGE_CREATE_PARAMETERS);
                $this->injectBaseParameters($parameters);
                return $parameters;
            }
        );
    }

    protected function getCompactedUpdateQuery($changes, $commonParameters = [], $injectBaseParameters = true, $cache = true)
    {
        return $this->getCompactedQuery(
            'UPDATE:'.serialize(array_keys($changes)),
            $this::updateQuery ?? $this->driver::updateQuery,
            [$this->driver::RDBMS_FIELD_VALUES => true, $this->driver::RDBMS_FIELD_CHANGES => true, $this->driver::RDBMS_WHERE_CONDITION => true],
            $commonParameters,
            function ($parameters) use ($changes, $injectBaseParameters) {
                if ($injectBaseParameters) {
                    # inject base parameters
                    $this->injectMapParameters($parameters, $this::OS_FIELD_STORAGE_GENERAL_PARAMETERS);
                    $this->injectMapParameters($parameters, $this::OS_FIELD_STORAGE_UPDATE_PARAMETERS);
                }

                # inject conditional parameters
                foreach ($changes as $field => $oldValue) {
                    if (isset($this->fieldMap[$field][$this::OS_FIELD_STORAGE_UPDATE_CHANGED_PARAMETERS])) {
                        foreach ($this->fieldMap[$field][OS_FIELD_STORAGE_UPDATE_CHANGED_PARAMETERS] as $id => $mod) {
                            if (!isset($parameters[$id])) $parameters[$id] = [];
                            $parameters[$id][] = $mod;
                        }
                    }
                }

                # inject base parameters
                if ($injectBaseParameters) $this->injectBaseParameters($parameters);

                # inject actual field list
                $parameters[$this->driver::RDBMS_FIELD_NAMES] = array_map(function ($field) { return $this->escapedFieldNames[$field]; }, array_keys($changes));

                return $parameters;
            },
            $cache
        );
    }

    protected function getCompactedDeleteQuery()
    {
        return $this->getCompactedQuery(
            'DELETE',
            $this::deleteQuery ?? $this->driver::deleteQuery,
            [$this->driver::RDBMS_WHERE_CONDITION => true],
            [],
            function ($parameters) {
                $this->injectMapParameters($parameters, $this::OS_FIELD_STORAGE_GENERAL_PARAMETERS);
                $this->injectMapParameters($parameters, $this::OS_FIELD_STORAGE_DELETE_PARAMETERS);
                $this->injectBaseParameters($parameters);
                return $parameters;
            }
        );
    }

    protected function injectBaseParameters(&$parameters)
    {
        $parameters[$this->driver::RDBMS_FIELD_NAMES] = $this->escapedFieldList;
        $parameters[$this->driver::RDBMS_FIELD_EXPRESSIONS] = $this->escapedFieldExpressionsList;
        $parameters[$this->driver::RDBMS_TABLE_NAME] = $this->escapedTableName;
    }

    protected function injectMapParameters(&$parameters, $parameterTypeID)
    {
        foreach ($this->fieldMap as $field => $info) {
            if (isset($info[$parameterTypeID])) {
                foreach ($info[$parameterTypeID] as $id => $mod) {
                    if (!isset($parameters[$id])) $parameters[$id] = [];
                    $parameters[$id][] = $mod;
                }
            }
        }
    }
}

class RDBMS extends \ATL\ObjectStore implements \ATL\ObjectStore\IRDBMS { use \ATL\ObjectStore\TRDBMS; }
