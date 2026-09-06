<?php

namespace ATL;

########
# This (along with ObjectStore\Item) is performance-optimized configurable database model engine, providing common CRUD operations along with easiness of retrieval and search in a storage engine agnostic way
# Take care this object based model engine assumes your code and storage are trusted, there is no data validation except where it is needed for proper type conversions, the data is of course escaped before being stored though
# If you need to access data from untrusted storage, or if you pass untrusted user data to the storage, it is up to you to validate if all the data types match model and code type expectations before storing and after loading

# Store models without key are supported, but only ObjectStore\Item->getObjectStoreItemID() $items list will be available, allowing to process any data, array style access would use internal item IDs as item keys
# Store array access (per-item) is technically slow enough, you may be better off with loading in bulk and then store indexes and/or direct $key / $items for further access after you have loaded the dataset
# Two store cleanup function are available to manage current store view: ObjectStore->clearVisible() and ObjectStore->clearItems(), the difference is in retaining the internal objects cache for further accesses as follows:
#  clearVisible() only cleans up $items and $keyIndex, so items will not be visible anymore, but does not detach items from $storedItems and checks $storedItems on loads so no real reloads may be done
#  clearItems() clears everything in contrary, detaching all the existing item objects from the store and recreating them anew, should they be reloaded

# Another level of dualism is present with remove(), detach() and delete() methods
# remove() acts as clearVisible(), but for a single object, removing item from ObjectStore visible list but keeping internal cache of the item in case it is needed again
# detach() clears every reference for the object, effectively detaching item from the store so on new access to the store by item key the item would be reloaded back from the storage
# delete() effectively and completely deletes the item both from the underlying storage and the store itself, acting as detach() in regards to ObjectStore in-memory object containment

# You may keep default ObjectStore\Item as itemClass, not creating and using its child classes if you only need the data or can do all the tuning via fieldMap and its callables, so do not extend ObjectStore\Item and use its descendants without clear need

# Non-storage based base ObjectStore class only allows you to distinctly put the items inside object store using ObjectStore->create() or ObjectStore->put(), ObjectStore->update() does nothing and ObjectStore->delete() just detaches the item
# ObjectStore->load() does nothing in the generic ObjectStore class so you will get NULL on non-existent items
# All of the above indirectly affects ObjectStore\Item->put(), ObjectStore\Item->create(), ObjectStore\Item->update(), ObjectStore\Item->delete() which call corresponding store methods

# Take care key and index fields are expected to be scalar, non-scalar values encountered will be serialized, potentially causing index key clashes between possible scalar and serialized values
# Virtual fields can be used as both keys and indexes, but take care each virtual field participating in key or index will be always calculated on item puts and updates
# If key and/or index fields change in the item, indexes will only be updated on ObjectStore\Item->update() or corresponding ObjectStore->update() calls
# Key fields are expected to have unique value combinations (non-unique key will cause exceptions), index fields are not mandatory to be all unique, indexes contain array of elements corresponding to each index

# multiple objectStoreItem* field names in items are reserved for ObjectStore internal properties, do not use field names starting with objectStoreItem, some specific method names are also reserved by public and internal API, take care

interface IObjectStore extends \ArrayAccess, \IteratorAggregate, \Countable, \Serializable
{
    ########
    # Map entry structure

    const OS_FIELD_FLAGS = 0; # contains OS_FFLAG_* bitmask, defaults to zero (normal field)
    const OS_FIELD_FORMAT = 1; # contains OS_FORMAT_* value, defaults to zero (raw data)
    const OS_FIELD_VIRTUAL_READ_METHOD = 2; # valid only with OS_FFLAG_VIRTUAL, provides virtual field read ObjectStore\Item method name (field is considered directly readable if not set)
    const OS_FIELD_VIRTUAL_WRITE_METHOD = 3; # valid only with OS_FFLAG_VIRTUAL, provides virtual field write ObjectStore\Item method name (field is considered directly writable if not set)
    const OS_FIELD_VIRTUAL_READ_METHOD_ARGUMENTS = 4; # valid only with OS_FFLAG_VIRTUAL, provides array of additional arguments that will be supplied to virtual read method, or a single argument if scalar
    const OS_FIELD_VIRTUAL_WRITE_METHOD_ARGUMENTS = 5; # valid only with OS_FFLAG_VIRTUAL, provides array of additional arguments that will be supplied to virtual write method, or a single argument if scalar
    const OS_FIELD_DEFAULT_VALUE = 6; # default value the field is set to on object creation
    const OS_FIELD_AS_ARRAY_NAME = 7; # field name as it will appear in ObjectStore\Item->asArray() call output, setting to empty string omits the field from ObjectStore\Item->asArray() output
    const OS_FIELD_CUSTOM_DELIMITER = 8; # for OS_FFORMAT_LIST_CUSTOM_DELIMITED and OS_FFORMAT_KV_CUSTOM_DELIMITED, specifies inter value or inter key-value pairs delimiter, defaults to "\n"
    const OS_FIELD_CUSTOM_KV_DELIMITER = 9; # for OS_FFORMAT_KV_CUSTOM_DELIMITED, specifies delimiter between key and value in the key-value pair, defaults to '='
    const OS_FIELD_INDEXES = 10; # specifies array of indexes this field takes part in, key is index property name (property would be named <name>Index), value is field order inside index (order can be non-consecutive, greater orders come last)
    const OS_FIELD_STORAGE_KEY = 11; # specifies storage-specific key for the field (i.e. database field name) override, if not specified, just the field name from the map is used
    const OS_FIELD_CUSTOM_CONVERT_STORE_METHOD = 12; # specifies custom storage store conversion ObjectStore\Item method name for OS_FFORMAT_CUSTOM_CONVERT, no conversion is done if not specified, return value is expected to be escaped for storage
    const OS_FIELD_CUSTOM_CONVERT_LOAD_METHOD = 13; # specifies custom storage load conversion ObjectStore\Item method name for OS_FFORMAT_CUSTOM_CONVERT, no conversion is done if not specified
    const OS_FIELD_CUSTOM_CONVERT_STORE_METHOD_ARGUMENTS = 14; # specifies additional arguments to pass to custom conversion store method for OS_FFORMAT_CUSTOM_CONVERT
    const OS_FIELD_CUSTOM_CONVERT_LOAD_METHOD_ARGUMENTS = 15; # specifies additional arguments to pass to custom conversion load method for OS_FFORMAT_CUSTOM_CONVERT
    const OS_FIELD_LOAD_CONVERSION_FAILURE_VALUE = 16; # specifies value to set field to in case of storage conversion failure on load, can be carefully used to avoid exceptions on load conversions from foreign or lax storages

    # take note OS_FIELD_VIRTUAL_*_METHOD definitions can actually be any callback or a closure, but giving a plain string value defaults to [$this, <string>] call being issued inside ObjectStore\Item
    # similarly, OS_FIELD_CUSTOM_CONVERT_*_METHOD definitions can actually be any callback or a closure, but giving a plain string value defaults to [$this, <string>] call being issued inside ObjectStore
    # internal (string defining internal method) callbacks execute in contexts of ObjectStore\Item or ObjectStore objects, you can freely access $this properties, methods and other elements
    # external callbacks execute in their own context specified by the callback, all callbacks (internal and external) get ObjectStore\Item/ObjectStore object as their first argument
    # the second argument for read method is field key, the third argument is field map entry, some good result value is expected on return (will be cached unless OS_FFLAG_VIRTUAL_NO_CACHE is specified)
    # the second argument for write method is the value to write, the third argument is field key, the fourth argument is field map entry, some good processed value to be stored for cached fields is expected on return
    # additional arguments from OS_FIELD_VIRTUAL_*_ARGUMENTS come right after normal arguments

    ########
    # Field flags

    const OS_FFLAG_NORMAL                                       = 0; # not actually a flag, the default flags bitmask value
    const OS_FFLAG_KEY                                          = 0x00000001; # key field (the composite key will be constructed in order of key fields listed), key fields cannot be virtual and must be storage-backed
    const OS_FFLAG_VIRTUAL                                      = 0x00000002; # this field is virtual and is not present in the storage, set up OS_FIELD_VIRTUAL_* parameters to designate handling
    const OS_FFLAG_VIRTUAL_STORED                               = 0x00000004; # despite having virtualized handling, this field is considered to be actually present in the storage, so its own data will be actually loaded and stored
    const OS_FFLAG_VIRTUAL_NO_READ                              = 0x00000008; # attempting to read this virtual field will throw an exception
    const OS_FFLAG_VIRTUAL_NO_WRITE                             = 0x00000010; # attempting to write this virtual field will throw an exception
    const OS_FFLAG_VIRTUAL_NO_CACHE                             = 0x00000020; # do not cache value returned by the field read handler, always generate the field value anew on read
    const OS_FFLAG_NOT_NULL                                     = 0x00000040; # this field must be initialized (not NULL) to create or update storage part, and cannot contain NULLs when loaded from storage
    const OS_FFLAG_MANDATORY                                    = 0x00000080; # when calling ObjectStore->create(), this field must absolutely be present in the source data
    const OS_FFLAG_NO_CREATE                                    = 0x00000100; # when calling ObjectStore->create(), this field must absolutely NOT be present in the source data
    const OS_FFLAG_NO_LOG_OR_PRINT                              = 0x00000200; # this field will not take part in debug printing or any logging performed by the store
    const OS_FFLAG_DELIMITED_ALLOW_SCALARS                      = 0x00000400; # allow scalar values to be stored with OS_FFORMAT_LIST_* formats, scalar values will be converted to arrays before storing, but will still read as scalar if not reloaded
    const OS_FFLAG_DATEOBJECT_MICROTIME                         = 0x00000800; # honor microseconds in OS_FFORMAT_DATEOBJECT, storage format changes to 'Y-m-d H:i:s.mmmmmm' or other storage-specific timestamp with microseconds
    const OS_FFLAG_DELIMITED_TRIM_KEY                           = 0x00001000; # for OS_FFORMAT_KV_*, trim() keys when loading or storing
    const OS_FFLAG_DELIMITED_TRIM_VALUE                         = 0x00002000; # for OS_FFORMAT_KV_* and OS_FFORMAT_LIST_*, trim() values when loading or storing
    const OS_FFLAG_KEY_STORAGE_AUTOINCREMENT                    = 0x00004000; # this flag can be used to mark ONE key field as autoincrement, such field will fill itself back from autoincrement ID on creation if NULL and update actual item with its new value
    const OS_FFLAG_NOT_UPDATEABLE                               = 0x00008000; # attempting to update this field in underlying storage will throw an exception

    ########
    # Field formats

    const OS_FFORMAT_RAW                    =  0; # raw string, the default
    const OS_FFORMAT_STRING                 =  0; # alias to OS_FFORMAT_RAW to make it possible to explicitly indicate string
    const OS_FFORMAT_DATE                   =  1; # date (UNIX timestamp with zero time part in PHP, 'Y-m-d' or other storage specific date in storage)
    const OS_FFORMAT_TIME                   =  2; # time (integer in PHP), 'H:i:s' or other storage specific time in storage)
    const OS_FFORMAT_DATETIME               =  3; # datetime (UNIX timestamp in PHP, 'Y-m-d H:i:s' or other storage specific timestamp in storage)
    const OS_FFORMAT_DATEOBJECT             =  4; # datetime (Date object of default timezone in PHP, 'Y-m-d H:i:s' or other storage specific timestamp in storage), also see OS_FFLAG_DATEOBJECT_MICROTIME for microtime extension
    const OS_FFORMAT_IPV4_INT               =  5; # IPv4 address (integer IPv4 address in PHP, integer or other storage specific IPv4 address in storage)
    const OS_FFORMAT_IPV4_INT_TEXT          =  6; # IPv4 address (integer IPv4 address in PHP, forced dotted text representation of IPv4 address in storage)
    const OS_FFORMAT_IPV4_INT_BIN           =  7; # IPv4 address (integer IPv4 address in PHP, forced 4 byte blob representation of IPv4 address in storage)
    const OS_FFORMAT_IPV4_TEXT              =  8; # IPv4 address (dotted text IPv4 address in PHP, integer or other storage specific IPv4 address in storage)
    const OS_FFORMAT_IPV4_TEXT_INT          =  9; # IPv4 address (dotted text IPv4 address in PHP, forced integer representation of IPv4 address in storage)
    const OS_FFORMAT_IPV4_TEXT_BIN          = 10; # IPv4 address (dotted text IPv4 address in PHP, forced 4 byte blob representation of IPv4 address in storage)
    const OS_FFORMAT_IPV4_BIN               = 11; # IPv4 address (4 byte binary IPv4 address in PHP, 4 byte blob or other storage specific IPv4 address in storage)
    const OS_FFORMAT_IPV4_BIN_INT           = 12; # IPv4 address (4 byte binary IPv4 address in PHP, forced integer representation of IPv4 address in storage)
    const OS_FFORMAT_IPV4_BIN_TEXT          = 13; # IPv4 address (4 byte binary IPv4 address in PHP, forced dotted text representation of IPv4 address in storage)
    const OS_FFORMAT_IPV6_TEXT              = 14; # IPv6 address (possibly companded textual IPv6 address in PHP, possibly companded text representation or other storage specific IPv6 address in storage)
    const OS_FFORMAT_IPV6_TEXT_BIN          = 15; # IPv6 address (possibly companded textual IPv6 address in PHP, forced possibly companded text representation representation of IPv6 address in storage)
    const OS_FFORMAT_IPV6_BIN               = 16; # IPv6 address (16 byte binary IPv6 address in PHP, 16 byte blob or other storage specific IPv6 address in storage)
    const OS_FFORMAT_IPV6_BIN_TEXT          = 17; # IPv6 address (16 byte binary IPv6 address in PHP, forced possibly companded text representation of IPv6 address in storage)
    const OS_FFORMAT_NUMERIC                = 18; # number (integer or float or something else in PHP, integer or float or other numeric value in storage, optimization wise instructs to escape but not quote value when i.e. passing to database storage)
    const OS_FFORMAT_KV_CUSTOM_DELIMITED    = 19; # custom delimited key-value pairs (array of key => value in PHP, custom delimited string in storage, see OS_FIELD_CUSTOM_DELIMITER and OS_FIELD_CUSTOM_KV_DELIMITER map parameters)
    const OS_FFORMAT_LIST_CUSTOM_DELIMITED  = 20; # custom delimited value list (array of values in PHP, custom delimited string in storage, see OS_FIELD_CUSTOM_DELIMITER map parameter)
    const OS_FFORMAT_KV_INI                 = 21; # INI-style key-value format (array of key => value in PHP, INI-style key=value lines in storage, no [section] or ;comment processing is done, all lines without = are ignored on load)
    const OS_FFORMAT_SERIALIZED             = 22; # PHP serialized format (anything in PHP, PHP-serialized string in storage)
    const OS_FFORMAT_JSON                   = 23; # JSON serialized format (anything in PHP, serialized JSON string in storage, take care: always decodes objects as associative arrays)
    const OS_FFORMAT_JSON_PRETTY            = 24; # JSON serialized format (anything in PHP, serialized pretty-printed JSON string in storage, take care: always decodes objects as associative arrays)
    const OS_FFORMAT_LIST_COMMA_DELIMITED   = 25; # comma-delimited list format (array of values in PHP, comma-delimited list of values in storage, values cannot contain commas)
    const OS_FFORMAT_LIST_SPACE_DELIMITED   = 26; # space-delimited list format (array of values in PHP, space-delimited list of values in storage, values cannot contain spaces)
    const OS_FFORMAT_LIST_EOL_DELIMITED     = 27; # end of line delimited list format (array of values in PHP, EOL delimited list of values in storage, values cannot contain line endings, any consecutive combination of "\r" and "\n" is considered line ending)
    const OS_FFORMAT_BLOB                   = 28; # binary data, avoids any format conversions, uses BLOB escaping function by default, use if you store binary data in the field
    const OS_FFORMAT_CUSTOM_CONVERT         = 29; # custom storage format conversion (anything in PHP, custom conversion functions in OS_FIELD_CUSTOM_CONVERT_*_METHOD for storage, allowing to code virtually any format support)

    # field formats affect ObjectStore->convertItemDataToStorageData() and ObjectStore->convertStorageDataToItemData(), ObjectStore\Item->getObjectStorageItemData() and ObjectStore\Item->loadObjectStorageItemData() indirectly
    # base affected routines are ObjectStore->convertItemFieldToStorageData() and ObjectStore->convertStorageDataToItemField()
    # database variants of the ObjectStore may override some of the handling for ObjectStore->convertItemFieldToStorageData() and ObjectStore->convertStorageDataToItemField() to properly convert and/or escape specific datatypes
    # database variants of the ObjectStore may use this format data to perform in-query format conversions before storage data enters PHP processing
    # 'short' variants of IPV4/IPV6 formats (_INT, _BIN, _TEXT) keep the representation towards the storage, but database variants may optionally convert it to their own specific storage representation if storage supports it separately
    # 'long' variants of IPV4/IPV6 formats (_<A>_<B> ones) keep <A> format representation in PHP and mandatorily converts the storage variant to the <B> format, no specific storage variant allowed
    # there are no INT_INT or TEXT_TEXT of IPV4/IPV6 formats, just use normal RAW fields if you do not need any format conversion from/to storage
    # KV and LIST fields operate with associative arrays, some can operate with other data, scalar values cause an exception for KV variants, LIST variants may have OS_FFLAG_DELIMITED_ALLOW_SCALARS to prevent exceptions
    # OS_FIELD_LOAD_CONVERSION_FAILURE_VALUE can be used to avoid throwing exceptions on load if the source value cannot be converted
    # OS_FFLAG_NOT_NULL can be used to throw exceptions in case NULL values are encountered on load from storage

    const fieldMap = []; # default field map for the object store item (the model), ObjectStore->getFieldMap() is wrapped to return it and can be used to override specific map elements i.e. for flexibility per database or software versions
                         # if left empty (the base class is used as is), custom field map can also be provided to constructor and will then be used instead of the constant one, providing custom one when constant is defined is disallowed for clarity
    const itemClass = '\\ATL\\ObjectStore\\Item'; # store item class for the object store, you may actually go with ObjectStore\Item all the time if you only need the data and do not need any special extensions to the item class or can live with just callables in the map

    # public API
    public function setDebug($debug);
    public function getFieldMap($customFieldMap = null);
    public function forgeItem();
    public function clear();
    public function clearItems($quick = false);
    public function get($key);
    public function put(/** @var ObjectStore\Item */ $item, $noThrowOnSameItem = false);
    public function remove(/** @var ObjectStore\Item */ $item, $throwIfNotExists = false);
    public function detach(/** @var ObjectStore\Item */ $item, $noThrowIfNotStored = false);
    public function load($keys, $replaceExistingItems = false);
    public function create($data, $replace = false, $replaceDetach = false);
    public function createItem(/** @var ObjectStore\Item */ $item, $replace = false, $delete = false);
    public function update(/** @var ObjectStore\Item */ $item);
    public function delete(/** @var ObjectStore\Item */ $item);

    # internal API
    public function getKeyMap();
    public function getIndexMap($index);
    public function storeItem(/** @var ObjectStore\Item */ $item);
    public function detachItem(/** @var ObjectStore\Item */ $item);
    public function convertItemDataToStorageData($data);
    public function convertStorageDataToItemData($data);
    public function translateIndexKey($key);
}

trait TObjectStore
{
    protected $fieldMap; # cached final field map
    protected $emptyItem; # empty item for speedy creation
    /** @var ObjectStore\Item[] */ protected $storedItems = []; # this item list is hidden and is used to prevent reloading already stored items (or update them instead), indexed by ObjectStore\Item->getObjectStoreItemID()
    protected $storedItemsKey = []; # this is key-based stored items index, depth is defined by number of key fields, values are item objects
    protected $keyMap = []; # list of key fields
    protected $emptyKeyMap = false; # set if there is no key map (ObjectStore\Item->getObjectStoreItemID() key is used)
    protected $singleKeyMap = false; # set if there is only one key field (allows direct value array accesses)
    protected $indexMap = []; # list of index fields, per index

    /** @var ObjectStore\Item[][] */ protected $key = []; # this is key-based item index, depth is defined by number of key fields, values are item objects, can contain NULL values to prevent reloading items that were already attempted to be loaded
    /** @var ObjectStore\Item[] */ public $items = []; # this item list is used as primary item list, indexed by ObjectStore\Item->getObjectStoreItemID()

    # multiple $<name>Index properties may be dynamically constructed according to the field map, depth of index depends on number of index fields, values are arrays of items indexed by ObjectStore\Item->getObjectStoreItemID()

    ########
    # Public API

    public function __construct($customFieldMap = null)
    {
        $this->fieldMap = $this->getFieldMap($customFieldMap); # build and cache the final field map

        # set major key parameters to avoid count() in other places
        if (count($this->keyMap) == 0) $this->emptyKeyMap = true;
        if (count($this->keyMap) <= 1) $this->singleKeyMap = true;

        # sort and prepare index maps, also create index properties
        foreach ($this->indexMap as $index => &$map) {
            asort($map, SORT_NUMERIC);
            $map = array_keys($map);
        } unset($map);

        # clear item lists (this will also create index properties)
        $this->clearItems();

        # create internal empty item for cloning
        $this->createInternalEmptyItem();

        $this->onInitialize();
    }

    # you can override this if your item class needs more parameters to the constructor
    protected function createInternalEmptyItem($debug = false)
    {
        $itemClass = $this::itemClass;
        /** @var ObjectStore\Item */ $this->emptyItem = new $itemClass($this);
        $this->emptyItem->objectStoreItemDebug = $debug;
    }

    public function setDebug($debug)
    {
        $this->createInternalEmptyItem($debug);
        foreach ($this->storedItems as $item)
            $item->objectStoreItemDebug = $debug;
    }

    public function getFieldMap($customFieldMap = null)
    {
        if ($this->fieldMap !== null) return $this->fieldMap;

        # set the field map accordingly
        if ($customFieldMap !== null) {
            if (!empty($this::fieldMap)) throw new \ErrorException("Attempted to use custom field map while constant field map is not empty in object store class `".get_class($this)."`");
            $this->fieldMap = $customFieldMap;
        } else {
            # just use local constant field map
            $this->fieldMap = $this::fieldMap;
        }

        # perform final field map adjustments
        $this->fieldMap = $this->adjustFieldMap($this->fieldMap);

        # build key and index maps, create index properties, convert certain callables to closures, convert additional arguments from scalar to array if necessary
        foreach ($this->fieldMap as $field => &$info) {
            if (($info[$this::OS_FIELD_FLAGS] ?? 0) & $this::OS_FFLAG_KEY) {
                if (($info[$this::OS_FIELD_FLAGS] ?? 0) & $this::OS_FFLAG_VIRTUAL) throw new \ErrorException("Key field `{$field}` cannot be defined as virtual field in object store class `".get_class($this)."`");
                $this->keyMap[] = $field;
            }
            if (isset($info[$this::OS_FIELD_INDEXES])) {
                if (!is_array($info[$this::OS_FIELD_INDEXES])) throw new \ErrorException("OS_FIELD_INDEXES must be an array in object store class `".get_class($this)."`");
                foreach ($info[$this::OS_FIELD_INDEXES] as $index => $order) $this->indexMap[$index][$field] = $order;
            }

            foreach ([$this::OS_FIELD_CUSTOM_CONVERT_STORE_METHOD, $this::OS_FIELD_CUSTOM_CONVERT_LOAD_METHOD] as $midx)
                if (isset($info[$midx])) $info[$midx] = Routines::callableToClosure([$this, $info[$midx]], true);

            foreach ([$this::OS_FIELD_VIRTUAL_READ_METHOD_ARGUMENTS, $this::OS_FIELD_VIRTUAL_WRITE_METHOD_ARGUMENTS, $this::OS_FIELD_CUSTOM_CONVERT_STORE_METHOD_ARGUMENTS, $this::OS_FIELD_CUSTOM_CONVERT_LOAD_METHOD_ARGUMENTS] as $midx)
                if (array_key_exists($midx, $info) && !is_array($info[$midx]))
                    $info[$midx] = [$info[$midx]];
        } unset($info);

        return $this->fieldMap;
    }

    # override this to make dynamic alterations to the final field map
    # store will cache the resulting map on construction and return cached variant afterwards
    # why? because if you i.e. renamed or add some storage field between versions, you can adjust your ObjectStore(Item) field map to match the actual used version
    protected function adjustFieldMap($fieldMap)
    {
        return $fieldMap;
    }

    # creates a new detached empty item, override if your new items cannot be created by just cloning them
    public function forgeItem()
    {
        return clone $this->emptyItem;
    }

    # clears visible items list and their keys/indexes, but keep loaded items intact
    public function clear()
    {
        $this->onBeforeClear();

        $this->key = [];
        $this->items = [];
        foreach ($this->indexMap as $index => $map) {
            $indexProperty = $index.'Index';
            $this->$indexProperty = [];
        }

        $this->onAfterClear();
    }

    # clears everything from the store, detaching all the items, $quick set to true allows to not to go the full way of calling all store events for each detached item
    public function clearItems($quick = false)
    {
        $this->clear(); # clear visible items

        $this->onBeforeClearItems($quick);

        foreach ($this->storedItems as /** @var ObjectStore\Item */ $item)
            $item->detachObjectStoreItem(true, $quick);
        $this->storedItemsKey = [];
        $this->storedItems = [];

        $this->onAfterClearItems($quick);
    }

    # gets an item from the store based on specific key, attempts to load item by key if it does not exist, returns NULL if does not exist and not loaded
    public function get($key)
    {
        if ((/** @var ObjectStore\Item */ $item = $this->getIndexElement($this->key, $xKey = $this->translateUserSuppliedKey($key), false)) !== false) return $item;
        $this->load([$key]);
        return $this->getIndexElement($this->key, $xKey, null);
    }

    # places a detached item to the store, optionally not throwing exception if this item already exists and is the same item
    public function put(/** @var ObjectStore\Item */ $item, $noThrowOnSameItem = false)
    {
        if (isset($this->storedItems[$item->getObjectStoreItemID()])) {
            if (!$noThrowOnSameItem) throw new \ATL\ObjectStoreDuplicateException("The item to be added already exists in object store class `".get_class($this)."`");
            if (!isset($this->items[$item->getObjectStoreItemID()])) {
                # recreate visible item
                $this->items[$item->getObjectStoreItemID()] = $item;
                $this->putIndexElement($this->key, $item->getObjectStoreItemIndexData(), $item);
                $this->putItemIndexes($item);
            }
            return false; # nothing to do
        }

        if ($this->getIndexElement($this->key, $item->getObjectStoreItemIndexData()) !== null)
            throw new \ATL\ObjectStoreDuplicateException("The item to be added already exists in object store class `".get_class($this)."`");

        # store item (continues on storeItem call)
        $item->storeObjectStoreItem($this);
        return true;
    }

    # removes the item from the store (visible only), optionally throwing an exception if item is not in the visible items list
    public function remove(/** @var ObjectStore\Item */ $item, $throwIfNotExists = false)
    {
        if (!isset($this->items[$item->getObjectStoreItemID()])) {
            if ($throwIfNotExists) throw new \ATL\ObjectStoreException("The item to be removed does not exist in object store class `".get_class($this)."`");
            return false; # nothing to remove
        }
        unset($this->items[$item->getObjectStoreItemID()]);
        $this->removeIndexElement($this->key, $item->getObjectStoreItemIndexData());
        $this->removeItemIndexes($item);
        return true;
    }

    # fully removes the item from the store detaching it, optionally not throwing any exception if item is not stored
    public function detach(/** @var ObjectStore\Item */ $item, $noThrowIfNotStored = false)
    {
        if (!isset($this->storedItems[$item->getObjectStoreItemID()])) {
            if (!$noThrowIfNotStored) throw new \ATL\ObjectStoreException("The item to be detached is not stored to object store class `".get_class($this)."`");
            return false; # nothing to remove
        }
        $this->remove($item); # remove visible item
        $item->detachObjectStoreItem(); # detach item (continues on detachItem call)
        return true;
    }

    # loads multiple items by their item keys, override in storage-backed ObjectStore variants to provide key-based autoloading
    # do not forget to return loaded list to the user, only the items actually loaded or present in the store, without NULL fillers for non-loaded items
    # returned array of loaded items keys match source keys array keys
    public function load($keys, $replaceExistingItems = false, $replaceDetach = false, $forUpdate = false)
    {
        array_walk($keys, function (&$key) { $key = $this->translateUserSuppliedKey($key); });
        $this->storageLoad($keys, $replaceExistingItems, $replaceDetach, $forUpdate);
        return $this->fillItemsFromStoredItems($keys);
    }

    # creates and returns a single item from the data array supplied
    # alias to createMultiple with a single item to create, see createMulti for details
    public function create($data, $replace = false, $replaceDetach = false)
    {
        $result = $this->createMultiple([$data], $replace, $replaceDetach);
        return reset($result);
    }

    # creates multiple items from array of data arrays and returns them, optionally replacing existing items in the storage (replace = false means ObjectStoreDuplicateException or some other exception will be thrown if the item exists in store or storage)
    # if the item exists and is loaded, loaded item will be updated with the data supplied instead, including possible key change, so all references to this item get the update, and the existing item will be returned
    # this behavior can be overridden with setting replaceDetach to true, in that case existing item will be deleted + detached and then the replace operation will happen with new item returned
    # take care the implementation checks the item key, so if it exists storage will never be called if replace = false
    # if the storage generates some values for the item, create() will not load these values back from the storage until you reload the item in your storage implementation or calling code
    # the one possible exception to this is autoincrement key field, if exists, it MAY be filled back by the storage
    # returned array keys match source items data arrays array keys
    public function createMultiple($itemsData, $replace = false, $replaceDetach = false)
    {
        if (count($itemsData) == 0) return []; # skip the hard part

        $items = [];
        $newItems = [];
        $updatedItems = [];

        # prepare items data
        foreach ($itemsData as $dataId => $data) {
            $createdItems[$dataId] = null;

            # verify data fields and build non-existent fields if possible
            foreach ($data as $field => $value) {
                if (!isset($this->fieldMap[$field])) throw new \ATL\ObjectStoreException("Field `{$field}` not found, object store class `".get_class($this)."`");
                $mapEntry = $this->fieldMap[$field];
                if ((($mapEntry[$this::OS_FIELD_FLAGS] ?? 0) & $this::OS_FFLAG_VIRTUAL) && !(($mapEntry[$this::OS_FIELD_FLAGS] ?? 0) & $this::OS_FFLAG_VIRTUAL_STORED))
                    throw new \ATL\ObjectStoreException("Field `{$field}` is virtual and not storage backed, object store class `".get_class($this)."`");
                if (($mapEntry[$this::OS_FIELD_FLAGS] ?? 0) & $this::OS_FFLAG_NO_CREATE)
                    throw new \ATL\ObjectStoreException("Field `{$field}` is not allowed to be present, object store class `".get_class($this)."`");
                if ((($mapEntry[$this::OS_FIELD_FLAGS] ?? 0) & $this::OS_FFLAG_NOT_NULL) && ($value === null))
                    throw new \ATL\ObjectStoreException("Field `{$field}` is not allowed to be NULL, object store class `".get_class($this)."`");
            }

            # add all field defaults
            foreach ($this->fieldMap as $mapKey => $mapEntry) {
                if ((($mapEntry[$this::OS_FIELD_FLAGS] ?? 0) & $this::OS_FFLAG_VIRTUAL) && !(($mapEntry[$this::OS_FIELD_FLAGS] ?? 0) & $this::OS_FFLAG_VIRTUAL_STORED)) continue; # skip virtual fields
                if (($mapEntry[$this::OS_FIELD_FLAGS] ?? 0) & $this::OS_FFLAG_NO_CREATE) continue; # skip fields not allowed on create
                if (!array_key_exists($mapKey, $data)) {
                    if (($mapEntry[$this::OS_FIELD_FLAGS] ?? 0) & $this::OS_FFLAG_MANDATORY)
                        throw new \ATL\ObjectStoreException("Field `{$field}` is mandatory but is not present, object store class `".get_class($this)."`");
                    if (array_key_exists($this::OS_FIELD_DEFAULT_VALUE, $mapEntry)) {
                        $data[$mapKey] = $mapEntry[$this::OS_FIELD_DEFAULT_VALUE]; # set the default value for non-existing field
                    } else {
                        if ((($mapEntry[$this::OS_FIELD_FLAGS] ?? 0) & $this::OS_FFLAG_NOT_NULL))
                            throw new \ATL\ObjectStoreException("Field `{$field}` is required because it cannot be NULL, object store class `".get_class($this)."`");
                        $data[$mapKey] = null;
                    }
                }
            }

            # build key and check if the item with the same key exists (empty keymap always allows to create items)
            if (!$this->emptyKeyMap) {
                $key = [];
                foreach ($this->keyMap as $field) $key[] = $data[$field];
                if ((/** @var ObjectStore\Item */ $oldItem = $this->getIndexElement($this->key, $key)) !== null) {
                    # we have existing item, handle
                    if (!$replace) throw new \ATL\ObjectStoreDuplicateException("Attempted to create duplicate item in object store class `".get_class($this)."`");
                    if (!$replaceDetach) {
                        # we need to handle replacement by item update there
                        $oldItem->loadObjectStoreItemData($data);
                        $items[$dataId] = $updatedItems[$dataId] = $oldItem;
                        goto nextItem;
                    }
                }
            }

            # schedule item for creation
            $item = $this->forgeItem();
            $item->loadObjectStoreItemData($data);
            $items[$dataId] = $newItems[$dataId] = $item;

nextItem:
        }

        # actually create all the new items
        if (count($newItems) > 0) $this->createItems($newItems, $replace);

        # update all existing items
        if (count($updatedItems) > 0) $this->updateItems($updatedItems);

        # return newly created items list
        return $items;
    }

    # creates single stored item from detached item object
    # alias to createItems with a single item to create, see createItems for details
    # returns either detached/deleted item that was replaced by newly created items or null if no items were detached
    public function createItem(/** @var ObjectStore\Item */ $item, $replace = false, $delete = false)
    {
        $result = $this->createItems([$item], $replace, $delete);
        return (($result = reset($result)) !== false) ? $result : null;
    }

    # creates stored items from detached item objects list, optionally replacing existing items in the storage (replace = false means ObjectStoreDuplicateException or some other exception will be thrown if the item exists in store or storage)
    # replacing item actually detaches the existing item from the store without doing anything with storage and puts the new item to store, that is it, to force item deletion on replacement set delete to true
    # if the storage generates some values for the item, createItem() will not load these values back from the storage until you reload the item in your storage implementation or the calling code
    # the one possible exception to this is autoincrement key field, if exists, it MAY be filled back by the storage
    # returns list of items that were detached/deleted by the operation, returned array keys match source array keys for the items related
    public function createItems(/** @var ObjectStore\Item[] */ $items, $replace = false, $delete = false)
    {
        if (count($items) == 0) return []; # skip the hard part
        $oldItems = [];
        $this->createItemsStepBeforeCreate($items, $replace, $delete, $oldItems);
        $this->storageCreate($items, $replace); # call the storage handler to actually create items
        $this->createItemsStepAfterCreate($items, $replace);
        return $oldItems;
    }

    # this helper is reused in both synchronous and asynchronous routines, fills $oldItems (must be initialized to array)
    protected function createItemsStepBeforeCreate(/** @var ObjectStore\Item[] */ $items, $replace, $delete, &$oldItems)
    {
        # check and possibly remove old items
        foreach ($items as $itemId => $item) {
            if ((/** @var ObjectStore\Item */ $oldItem = $this->getIndexElement($this->key, $item->getObjectStoreItemIndexData())) !== null) {
                # we have existing item, handle
                if (!$replace) throw new \ATL\ObjectStoreDuplicateException("Attempted to create duplicate item in object store class `".get_class($this)."`");
                $oldItems[$itemId] = $oldItem;
                if (!$delete) {
                    $this->detach($oldItem);
                } else {
                    $this->delete($oldItem);
                }
            }
        }

        # run onBeforeCreateItem events and reset changes to item and its index set before creation
        foreach ($items as $item) {
            $this->onBeforeCreateItem($item, $replace);
            $item->resetObjectStoreItemIndexData();
            $item->resetObjectStoreItemChanges();
        }
    }

    # this helper is reused in both synchronous and asynchronous routines
    protected function createItemsStepAfterCreate(/** @var ObjectStore\Item[] */ $items, $replace = false)
    {
        # place all created items to the store and run onAfterCreateItem events
        foreach ($items as $item) {
            $this->put($item);
            $this->onAfterCreateItem($item, $replace);
        }
    }

    # updates single item data in the storage
    # alias to updateItems with a single item to update, see updateItems for details
    # returns the item itself if it was updated, null otherwise
    public function update(/** @var ObjectStore\Item */ $item)
    {
        $result = $this->updateItems([$item]);
        return (($result = reset($result)) !== false) ? $result : null;
    }

    # updates stored items data in the storage
    # take care storage implementation must use item index data and not the actual data (there can be some changes) to determine current item key
    public function updateItems(/** @var ObjectStore\Item[] */ $items)
    {
        if (count($items) == 0) return []; # skip the hard part

        $itemsToUpdate = [];
        $indexData = [];
        $changes = [];
        $this->updateItemsStepBeforeUpdate($items, $itemsToUpdate, $indexData, $changes);

        # tricky part, exceptions must cause unchanged item reattachment to keep valid store state, and be rethrown afterwards
        $hasException = null;
        try {
            # actually update items in storage
            $this->storageUpdate($itemsToUpdate, $indexData, $changes);
        } catch (\Exception $e) {
            $hasException = $e;
        }

        $this->updateItemsStepAfterUpdate($itemsToUpdate, $indexData, $changes, $hasException);
        return $itemsToUpdate;
    }

    # this helper is reused in both synchronous and asynchronous routines, fills in $itemsToUpdate, $indexData, $changes (all must be initialized to arrays)
    protected function updateItemsStepBeforeUpdate(/** @var ObjectStore\Item[] */ $items, &$itemsToUpdate, &$indexData, &$changes)
    {
        # check all items to exist in store
        foreach ($items as $item)
            if (!isset($this->items[$item->getObjectStoreItemID()]))
                throw new \ATL\ObjectStoreException("The item to be updated is not found in object store class `".get_class($this)."`");

        # request each item changes from item change tracking, then run onBeforeUpdateItem handlers and get index data for items that are really going to be updated
        foreach ($items as $itemId => $item) {
            $itemChanges = $item->getObjectStoreItemChanges();
            if (count($itemChanges) != 0) {
                $itemsToUpdate[$itemId] = $item;
                $changes[$itemId] = $itemChanges;
                $this->onBeforeUpdateItem($item, $itemChanges);
                $indexData[$itemId] = $item->getObjectStoreItemIndexData();
            }
        }
        if (count($itemsToUpdate) == 0) return []; # nothing to update

        # verify fields from each change for non-updateable fields
        $updatedFields = [];
        foreach ($changes as $itemId => $change)
            foreach ($change as $field => $value)
                if (!isset($updatedFields[$field]))
                    $updatedFields[$field] = $field;
        foreach ($updatedFields as $field)
            if ((($mapEntry[$this::OS_FIELD_FLAGS] ?? 0) & $this::OS_FFLAG_NOT_UPDATEABLE))
                throw new \ATL\ObjectStoreException("Field `{$field}` is attempted to be updated but marked as non-updateable, object store class `".get_class($this)."`");

        # remove each item from keys and all indexes and run onBeforeUpdateItemInStorage events for all items going to be updated
        foreach ($itemsToUpdate as $itemId => $item) {
            $this->removeIndexElement($this->key, $indexData);
            $this->removeIndexElement($this->storedItemsKey, $indexData);
            $this->removeItemIndexes($item);
            $this->onBeforeUpdateItemInStorage($item, $changes[$itemId]);
        }
    }

    # this helper is reused in both synchronous and asynchronous routines
    protected function updateItemsStepAfterUpdate(/** @var ObjectStore\Item[] */ $itemsToUpdate, $indexData, $changes, $hasException)
    {
        # reset items changes and index data to current values if no exception happened
        if ($hasException === null) {
            foreach ($itemsToUpdate as $itemId => $item) {
                $item->resetObjectStoreItemChanges();
                $item->resetObjectStoreItemIndexData();
            }
        }

        # place updated items back to keys and all indexes (updated index data takes effect)
        foreach ($itemsToUpdate as $itemId => $item) {
            $indexData = $item->getObjectStoreItemIndexData();
            $this->putIndexElement($this->storedItemsKey, $indexData, $item);
            $this->putIndexElement($this->key, $indexData, $item);
            $this->putItemIndexes($item);
        }

        # now the items are back, re-throw the exception if we fail
        if ($hasException) throw $hasException;

        # run onAfterUpdateItem events for all items updated and return updated items
        foreach ($itemsToUpdate as $itemId => $item) $this->onAfterUpdateItem($item, $changes[$itemId]);
    }

    # deletes single item data in the storage
    # alias to deleteItems with a single item to update, see deleteItems for details
    public function delete(/** @var ObjectStore\Item */ $item)
    {
        $this->deleteItems([$item]);
    }

    # deletes items data in the storage
    # take care storage implementation must use item index data and not the actual data (there can be some changes) to determine current item key
    public function deleteItems(/** @var ObjectStore\Item[] */ $items)
    {
        if (count($items) == 0) return; # skip the hard part
        $indexData = [];
        $this->deleteItemsStepBeforeDelete($items, $indexData);
        $this->storageDelete($items, $indexData); # actually delete items from storage
        $this->deleteItemsStepAfterDelete($items);
    }

    # this helper is reused in both synchronous and asynchronous routines, fills in $indexData (must be initialized to array)
    protected function deleteItemsStepBeforeDelete(/** @var ObjectStore\Item[] */ $items, &$indexData)
    {
        # check all items to exist in store
        foreach ($items as $item)
            if (!isset($this->items[$item->getObjectStoreItemID()]))
                throw new \ATL\ObjectStoreException("The item to be deleted is not found in object store class `".get_class($this)."`");

        # request each item index data, then run onBeforeDeleteItem handlers
        foreach ($items as $itemId => $item) {
            $indexData[$itemId] = $item->getObjectStoreItemIndexData();
            $this->onBeforeDeleteItem($item);
        }
    }

    # this helper is reused in both synchronous and asynchronous routines
    protected function deleteItemsStepAfterDelete(/** @var ObjectStore\Item[] */ $items)
    {
        # detach all items and run onAfterDeleteItem handlers
        foreach ($items as $item) {
            $this->detach($item);
            $this->onAfterDeleteItem($item);
        }
    }

    ########
    # Asynchronous API (Task handlers)
    # This API allows to use ObjectStore under TaskLoop asynchronous execution
    # Take care this API has extended overhead compared to synchronous API due to Task creation and invocation
    # Intended for slow and bulk operations under TaskLoop where waiting synchronously is undesirable, so only bulk operations are supported
    # Requires storage driver asynchronous API support, otherwise will just be emulated using synchronous routines

    # Task handler to asynchronously load items like load()
    # arguments and return value are all the same as load() except for the fact Task may return null if it terminates prematurely
    public function asyncLoad(/** @var \ATL\Task */ $taskObject, $keys, $replaceExistingItems = false, $replaceDetach = false, $forUpdate = false)
    {
        array_walk($keys, function (&$key) { $key = $this->translateUserSuppliedKey($key); });
        if (yield new \ATL\Task([$this, 'asyncStorageLoadTask'], $keys, $replaceExistingItems, $replaceDetach, $forUpdate))
            return $this->fillItemsFromStoredItems($keys);
    }

    # Task handler to asynchronously create items like createItems()
    # arguments and return value are all the same as createItems() except for the fact Task may return null if it terminates prematurely
    public function asyncCreateItems(/** @var \ATL\Task */ $taskObject, /** @var ObjectStore\Item[] */ $items, $replace = false, $delete = false)
    {
        if (count($items) == 0) return []; # skip the hard part
        $oldItems = [];
        $this->createItemsStepBeforeCreate($items, $replace, $delete, $oldItems);
        $success = yield new \ATL\Task([$this, 'asyncStorageCreateTask'], $items, $replace); # call the storage handler to actually create items
        $this->createItemsStepAfterCreate($items, $replace);
        if ($success) return $oldItems;
    }

    public function asyncUpdateItems(/** @var \ATL\Task */ $taskObject, /** @var ObjectStore\Item[] */ $items)
    {
        if (count($items) == 0) return []; # skip the hard part

        $itemsToUpdate = [];
        $indexData = [];
        $changes = [];
        $this->updateItemsStepBeforeUpdate($items, $itemsToUpdate, $indexData, $changes);

        # tricky part, exceptions must cause unchanged item reattachment to keep valid store state, and be rethrown afterwards
        $hasException = null;
        $success = false;
        try {
            # actually update items in storage
            $success = yield new \ATL\Task([$this, 'asyncStorageUpdateTask'], $itemsToUpdate, $indexData, $changes);
        } catch (\Exception $e) {
            $success = true;
            $hasException = $e;
        }

        $this->updateItemsStepAfterUpdate($itemsToUpdate, $indexData, $changes, $hasException);
        if ($success) return $itemsToUpdate;
    }

    public function asyncDeleteItems(/** @var \ATL\Task */ $taskObject, /** @var ObjectStore\Item[] */ $items)
    {
        if (count($items) == 0) return; # skip the hard part
        $indexData = [];
        $this->deleteItemsStepBeforeDelete($items, $indexData);
        yield new \ATL\Task([$this, 'asyncStorageDeleteTask'], $items, $indexData); # actually delete items from storage
        $this->deleteItemsStepAfterDelete($items);
    }

    ########
    # Events API
    # This internal API exists to simplify additional object handling during store manipulations in derivatives, while it adds some overhead it is definitely handy to do the magic necessary
    # Override event handlers in your derivatives to handle specific events, do not forget to call parent implementations where you need it though, the default event handlers are all empty
    # For specific per-item events, it is always better to use ObjectStore\Item events API

    protected function onInitialize() { }
    protected function onBeforeClear() { }
    protected function onAfterClear() { }
    protected function onBeforeClearItems($quick) { }
    protected function onAfterClearItems($quick) { }
    protected function onBeforeCreateItem($item, $replace) { }
    protected function onAfterCreateItem($item, $replace) { }
    protected function onBeforeUpdateItem($item, &$changes) { }
    protected function onBeforeUpdateItemInStorage($item, &$changes) { }
    protected function onAfterUpdateItem($item, $changes) { }
    protected function onBeforeDeleteItem($item) { }
    protected function onAfterDeleteItem($item) { }

    ########
    # Storage API
    # override this API in storage-specific daughter classes, exists for easier storage implementations
    # this is only the mandatory basic API required for distinct item operations and key-based loading
    # actual storage implementations are also expected to have their own optimized API for getting item keys by specific queries, loading batches of items by specific queries, etc.
    # for database-specific API reference, check ObjectStoreMySQL class and try to implement the very same database-specific API so ObjectStore usage can cross databases

    # this is called to load items with specific keys from the storage
    # you must use loadItemFromStorageData() for each item to place loaded items into the ObjectStore, possibly overriding existing stored items data (pass replaceExistingItems there)
    # you can optimize storage loaders by honoring replaceExistingItems and not attempting to load anything that is already present in the $items/$key if replaceExistingItems is false
    # keys are keys translated to their in-store variant, ordered and named
    protected function storageLoad($keys, $replaceExistingItems, $replaceDetach, $forUpdate) { }

    # this is called actually store data from certain items into the storage (do not forget to use original keys and honor replaces)
    # in case of errors with storage, throw an exception, otherwise the uncreated item will still be added to storage
    # if you want to load newly created data generated by the storage for the item newly created, do it there
    # if $replace is set to false, it is almost required to throw exception if the item duplicates something in the storage, although this is left to implementation discretion
    # $replace set to true means we do not expect any exceptions on duplicates, so use some REPLACE function to actually replace the item in storage, again, up to implementation
    protected function storageCreate($items, $replace) { }

    # this is called to actually update items in storage after items are reattached to indexing, $changes are ObjectStore\Item->getObjectStoreItemChanges() result
    # take care storage implementation must use item index data and not the actual data (there can be some changes) to determine current item keys, the current key is supplied
    protected function storageUpdate($items, $keys, $changes) { }

    # this is called to actually delete item in the storage before the item is removed from indexing,
    # take care storage implementation must use item index data and not the actual data (there can be some changes) to determine current item keys, the current key is supplied
    # we normally do not expect any exceptions to be thrown on item not really existing in storage but this is left up to implementation discretion
    protected function storageDelete($items, $keys) { }

    ########
    # Asynchronous storage API (Task handlers)

    # this Task handler is used to asynchronously load items with specific keys from the storage
    # arguments and all other handling is like storageLoad() except it must return true when succeeds to inform asyncLoadTask about successful completion
    protected function asyncStorageLoadTask(/** @var \ATL\Task */ $taskObject, $keys, $replaceExistingItems, $replaceDetach, $forUpdate)
    {
        $this->storageLoad($keys, $replaceExistingItems, $replaceDetach, $forUpdate); # just emulated using storageLoad() so is not really asynchronous by default, asynchronous loading support is to be done in ObjectStore drivers as necessary
        return true;
        yield true; # exists just to make this a generator Task handler but as we are not asynchronous by default, we return result just on initialization
    }

    # this Task handler is used to asynchronously create items in the storage
    # arguments and all other handling is like storageLoad() except it must return true when succeeds to inform asyncLoadTask about successful completion
    protected function asyncStorageCreateTask(/** @var \ATL\Task */ $taskObject, $items, $replace)
    {
        $this->storageCreate($items, $replace); # just emulated using storageCreate() so is not really asynchronous by default, asynchronous loading support is to be done in ObjectStore drivers as necessary
        return true;
        yield true; # exists just to make this a generator Task handler but as we are not asynchronous by default, we return result just on initialization
    }

    protected function asyncStorageUpdateTask(/** @var \ATL\Task */ $taskObject, $items, $keys, $changes)
    {
        $this->storageUpdate($items, $keys, $changes); # just emulated using storageUpdate() so is not really asynchronous by default, asynchronous loading support is to be done in ObjectStore drivers as necessary
        return true;
        yield true; # exists just to make this a generator Task handler but as we are not asynchronous by default, we return result just on initialization
    }

    protected function asyncStorageDeleteTask(/** @var \ATL\Task */ $taskObject, $items, $keys)
    {
        $this->storageDelete($items, $keys); # just emulated using storageDelete() so is not really asynchronous by default, asynchronous loading support is to be done in ObjectStore drivers as necessary
        return true;
        yield true; # exists just to make this a generator Task handler but as we are not asynchronous by default, we return result just on initialization
    }

    ########
    # Internal API

    # gets key fields map
    public function getKeyMap()
    {
        return $this->keyMap;
    }

    # gets index fields map for a specific index
    public function getIndexMap($index)
    {
        if (!isset($this->indexMap[$index])) throw new \ATL\ObjectStoreException("Index `{$index}` does not exist in object store class `".get_class($this)."`");
        return $this->indexMap[$index];
    }

    # this one gets called from item store handler when item is ready to actually be stored
    public function storeItem(/** @var ObjectStore\Item */ $item)
    {
        # reset change tracking and indexing data for the item
        $item->resetObjectStoreItemChanges();
        $item->resetObjectStoreItemIndexData();
        $indexData = $item->getObjectStoreItemIndexData();

        # actually store item
        $this->storedItems[$item->getObjectStoreItemID()] = $item;
        $this->putIndexElement($this->storedItemsKey, $indexData, $item);

        # place to visible items
        $this->items[$item->getObjectStoreItemID()] = $item;
        $this->putIndexElement($this->key, $indexData, $item);
        $this->putItemIndexes($item);
    }

    public function detachItem(/** @var ObjectStore\Item */ $item)
    {
        # actually remove item from the store
        $this->removeIndexElement($this->storedItemsKey, $item->getObjectStoreItemIndexData());
        unset($this->storedItems[$item->getObjectStoreItemID()]);
    }

    # fills visible items from stored items if visible items do not exist (and fills NULL where no stored item exists)
    # requires pre-translated item keys, returns all existing (including filled in) items, keys match source array keys
    protected function fillItemsFromStoredItems($keys)
    {
        $items = [];
        foreach ($keys as $kid => $key) {
            if ((/** @var ObjectStore\Item */ $item = $this->getIndexElement($this->key, $xKey = $this->translateIndexKey($key), false, true)) === false) {
                if ((/** @var ObjectStore\Item */ $item = $this->getIndexElement($this->storedItemsKey, $xKey, null, true)) !== null) {
                    # yes, we need to place in this item or null value
                    $this->putIndexElement($this->key, $xKey, $item, true);
                    if ($item !== null) {
                        # also place the real item into item list and indexes
                        $this->items[$item->getObjectStoreItemID()] = $item;
                        $this->putItemIndexes($item);
                    }
                }
            }
            if ($item !== null) $items[$kid] = $item;
        }
        return $items;
    }

    # fills in item into all item indexes
    protected function putItemIndexes(/** @var ObjectStore\Item */ $item)
    {
        foreach ($this->indexMap as $index => $map) {
            $key = $item->getObjectStoreItemIndexData($index);
            $key['objectStoreItemID'] = $item->getObjectStoreItemID(); # add last key index of item ID
            $indexProperty = $index.'Index';
            $this->putIndexElement($this->$indexProperty, $key, $item);
        }
    }

    # removes item from all item indexes
    protected function removeItemIndexes(/** @var ObjectStore\Item */ $item)
    {
        foreach ($this->indexMap as $index => $map) {
            $key = $item->getObjectStoreItemIndexData($index);
            $key['objectStoreItemID'] = $item->getObjectStoreItemID(); # add last key index of item ID
            $indexProperty = $index.'Index';
            $this->removeIndexElement($this->$indexProperty, $key);
        }
    }

    # this can be called to load a new item from a specific dataset to the object store
    # it is working almost the same as create(), but instead of actually working with storage it just manages internal item state
    # as this is internal function, it trusts the loaded dataset to match schema and be valid, no verifications are done anywhere
    # replaceExistingItem has a specific meaning: if it is true and item exists, item contents are reset and updated from the dataset (forced load)
    # by default, if the item exists, no load operation is performed and existing items contents are not altered
    # item data contents updates reset items change tracking state like update() does, but without performing any actual storage operations
    # returns either newly created or (possibly updated) existing item object, disregarding if it was updated or not
    protected function loadItemFromData($data, $replaceExistingItem = false, $replaceDetach = false)
    {
        # build key
        $key = [];
        foreach ($this->keyMap as $field) $key[] = $data[$field];

        # if we have the item with the same key stored but not visible, reinstate the item
        if ((/** @var ObjectStore\Item */ $oldItem = $this->getIndexElement($this->key, $key)) === null) {
            if ((/** @var ObjectStore\Item */ $oldItem = $this->getIndexElement($this->storedItemsKey, $key)) !== null) {
                $this->items[$oldItem->getObjectStoreItemID()] = $oldItem;
                $indexData = $oldItem->getObjectStoreItemIndexData();
                $this->putIndexElement($this->key, $indexData, $oldItem);
                $this->putItemIndexes($oldItem);
            }
        }

        # check if the item with the same key exists
        if ((/** @var ObjectStore\Item */ $oldItem = $this->getIndexElement($this->key, $key)) !== null) {
            # we have existing item present
            if ($replaceExistingItem) {
                if ($replaceDetach) {
                    $this->detach($oldItem);
                } else {
                    # remove item from keys and all indexes
                    $indexData = $oldItem->getObjectStoreItemIndexData();
                    $this->removeIndexElement($this->key, $indexData);
                    $this->removeIndexElement($this->storedItemsKey, $indexData);
                    $this->removeItemIndexes($oldItem);

                    # reset item contents to the new contents
                    $oldItem->resetObjectStoreItemData(true);
                    $oldItem->loadObjectStoreItemData($data);

                    # reset item changes and index data to current values
                    $oldItem->resetObjectStoreItemChanges();
                    $oldItem->resetObjectStoreItemIndexData();

                    # place item back to keys and all indexes
                    $indexData = $oldItem->getObjectStoreItemIndexData();
                    $this->putIndexElement($this->storedItemsKey, $indexData, $oldItem);
                    $this->putIndexElement($this->key, $indexData, $oldItem);
                    $this->putItemIndexes($oldItem);

                    # return the refreshed item
                    return $oldItem;
                }
            } else {
                # return existing item without updating it
                return $oldItem;
            }
        }

        # here we go, building the new item and putting it to store
        /** @var ObjectStore\Item */ $item = $this->forgeItem();
        $item->loadObjectStoreItemData($data);
        $this->put($item);
        return $item;
    }

    # this can be called to load new item from a specific storage dataset (storage based derivatives are expected to call that on loads)
    protected function loadItemFromStorageData($data, $replaceExistingItem = false, $replaceDetach = false)
    {
        return $this->loadItemFromData($this->convertStorageDataToItemData($data), $replaceExistingItem, $replaceDetach);
    }

    # escapes storage value, override in your storage based derivative with proper escaping code
    protected function escapeStorageValue($value)
    {
        return ($value !== null) ? addslashes($value) : 'NULL'; # we intentionally do not use ?? there because it is a sample for your code that may look like ($value !== null) ? $this->db->real_escape_string($value) : 'NULL'
    }

    # escapes storage string, override in your storage based derivative with proper escaping code if double quotes are not valid
    # if double quotes are valid, leave this as is and just override escapeStorageValue() to escape quoted contents
    protected function escapeStorageString($value)
    {
        return ($value !== null) ? '"'.$this->escapeStorageValue($value).'"' : 'NULL';
    }

    # escapes BLOB storage value, override in your storage based derivative with proper escaping code
    # if double quotes are valid, leave this as is and just override escapeStorageValue() to escape quoted contents
    protected function escapeStorageBLOB($value)
    {
        return ($value !== null) ? '"'.$this->escapeStorageValue($value).'"' : 'NULL';
    }

    # converts item field to storage representation, override this if you need to extend conversion with some storage-specific code
    # normally you just need to override escapeStorageValue() / escapeStorageBLOB() to do most of the job, everything else will be done by this routine
    # if overriding, for types you do not need to convert yourself, just call the base implementation and return its result
    # the base implementation does NO validation of the source value except for delimited fields to be arrays, if you want some, you need to override this
    protected function convertItemFieldToStorageData($value, $field)
    {
        if (!isset($this->fieldMap[$field])) throw new \ATL\ObjectStoreException("Field `{$field}` not found in object store class `".get_class($this)."`");
        $map = $this->fieldMap[$field];

        # NULL handling
        if ($value === null) {
            if (($map[$this::OS_FIELD_FLAGS] ?? 0) & $this::OS_FFLAG_NOT_NULL)
                throw new \ATL\ObjectStoreException("Field `{$field}` must not be NULL in object store class `".get_class($this)."`");
            return $this->escapeStorageValue(null);
        }

        # type conversion
        switch ($map[$this::OS_FIELD_FORMAT] ?? 0) {
            case $this::OS_FFORMAT_RAW:
            return $this->escapeStorageString($value);

            case $this::OS_FFORMAT_BLOB:
            return $this->escapeStorageBLOB($value);

            case $this::OS_FFORMAT_IPV4_TEXT:
            case $this::OS_FFORMAT_IPV6_TEXT:
            return $this->escapeStorageString($value);

            case $this::OS_FFORMAT_IPV4_BIN:
            case $this::OS_FFORMAT_IPV6_BIN:
            return $this->escapeStorageBLOB($value);

            case $this::OS_FFORMAT_DATE:
            return $this->escapeStorageString(date('Y-m-d', $value));

            case $this::OS_FFORMAT_TIME:
            $hh = intdiv($value, 3600);
            $value = $value - ($hh * 3600);
            $mm = intdiv($value, 60);
            $ss = $value - ($mm * 60);
            return $this->escapeStorageString($hh.':'.str_pad($mm, 2, '0', STR_PAD_LEFT).':'.str_pad($ss, 2, '0', STR_PAD_LEFT));

            case $this::OS_FFORMAT_DATETIME:
            return $this->escapeStorageString(date('Y-m-d H:i:s', $value));

            case $this::OS_FFORMAT_DATEOBJECT:
            return $this->escapeStorageString($value->format((($map[$this::OS_FIELD_FLAGS] ?? 0) & $this::OS_FFLAG_DATEOBJECT_MICROTIME) ? 'Y-m-d H:i:s.u' : 'Y-m-d H:i:s'));

            case $this::OS_FFORMAT_IPV4_INT:
            case $this::OS_FFORMAT_NUMERIC:
            return $this->escapeStorageValue($value);

            case $this::OS_FFORMAT_IPV4_INT_TEXT:
            return $this->escapeStorageString(long2ip($value));

            case $this::OS_FFORMAT_IPV4_INT_BIN:
            return $this->escapeStorageBLOB(pack('V', $value));

            case $this::OS_FFORMAT_IPV4_TEXT_INT:
            $value = ip2long($value);
            return $this->escapeStorageValue(($value >= 0) ? $value : 0x100000000 - $value);

            case $this::OS_FFORMAT_IPV4_TEXT_BIN:
            $value = ip2long($value);
            return $this->escapeStorageBLOB(pack('V', ($value >= 0) ? $value : 0x100000000 - $value));

            case $this::OS_FFORMAT_IPV4_BIN_INT:
            $value = unpack('V', $value);
            return $this->escapeStorageValue(reset($value));

            case $this::OS_FFORMAT_IPV4_BIN_TEXT:
            $value = unpack('V', $value);
            return $this->escapeStorageString(long2ip($value));

            case $this::OS_FFORMAT_IPV6_TEXT_BIN:
            return $this->escapeStorageBLOB(inet_ntop($value));

            case $this::OS_FFORMAT_IPV6_BIN_TEXT:
            return $this->escapeStorageString(inet_pton($value));

            case $this::OS_FFORMAT_KV_CUSTOM_DELIMITED:
            if (!is_array($value)) {
                if (!(($map[$this::OS_FIELD_FLAGS] ?? 0) & $this::OS_FFLAG_DELIMITED_ALLOW_SCALARS))
                    throw new \ATL\ObjectStoreException("Scalar value encountered on delimited field `{$field}` to storage conversion while scalars are not allowed in object store class `".get_class($this)."`");
                $value = [$value];
            }
            $kvDelimiter = $map[$this::OS_FIELD_CUSTOM_KV_DELIMITER] ?? "=";
            return $this->escapeStorageString(implode($map[$this::OS_FIELD_CUSTOM_DELIMITER] ?? "\n", array_map(function ($k, $v) use ($kvDelimiter) { return $k.$kvDelimiter.$v; }, array_keys($value), $value)));

            case $this::OS_FFORMAT_LIST_CUSTOM_DELIMITED:
            if (!is_array($value)) {
                if (!(($map[$this::OS_FIELD_FLAGS] ?? 0) & $this::OS_FFLAG_DELIMITED_ALLOW_SCALARS))
                    throw new \ATL\ObjectStoreException("Scalar value encountered on delimited field `{$field}` to storage conversion while scalars are not allowed in object store class `".get_class($this)."`");
                return $this->escapeStorageString($value);
            }
            return $this->escapeStorageString(implode($map[$this::OS_FIELD_CUSTOM_DELIMITER] ?? "\n", $value));

            case $this::OS_FFORMAT_KV_INI:
            if (!is_array($value)) {
                if (!(($map[$this::OS_FIELD_FLAGS] ?? 0) & $this::OS_FFLAG_DELIMITED_ALLOW_SCALARS))
                    throw new \ATL\ObjectStoreException("Scalar value encountered on delimited field `{$field}` to storage conversion while scalars are not allowed in object store class `".get_class($this)."`");
                $value = [$value];
            }
            return $this->escapeStorageString(implode("\n", array_map(function ($k, $v) use ($kvDelimiter) { return $k.'='.$v; }, array_keys($value), $value)));

            case $this::OS_FFORMAT_SERIALIZED:
            return $this->escapeStorageString(serialize($value));

            case $this::OS_FFORMAT_JSON:
            return $this->escapeStorageString(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_BIGINT_AS_STRING, 0x1000));

            case $this::OS_FFORMAT_JSON_PRETTY:
            return $this->escapeStorageString(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_BIGINT_AS_STRING | JSON_PRETTY_PRINT, 0x1000));

            case $this::OS_FFORMAT_LIST_COMMA_DELIMITED:
            if (!is_array($value)) {
                if (!(($map[$this::OS_FIELD_FLAGS] ?? 0) & $this::OS_FFLAG_DELIMITED_ALLOW_SCALARS))
                    throw new \ATL\ObjectStoreException("Scalar value encountered on delimited field `{$field}` to storage conversion while scalars are not allowed in object store class `".get_class($this)."`");
                return $this->escapeStorageString($value);
            }
            return $this->escapeStorageString(implode(",", $value));

            case $this::OS_FFORMAT_LIST_SPACE_DELIMITED:
            if (!is_array($value)) {
                if (!(($map[$this::OS_FIELD_FLAGS] ?? 0) & $this::OS_FFLAG_DELIMITED_ALLOW_SCALARS))
                    throw new \ATL\ObjectStoreException("Scalar value encountered on delimited field `{$field}` to storage conversion while scalars are not allowed in object store class `".get_class($this)."`");
                return $this->escapeStorageString($value);
            }
            return $this->escapeStorageString(implode(" ", $value));

            case $this::OS_FFORMAT_LIST_EOL_DELIMITED:
            if (!is_array($value)) {
                if (!(($map[$this::OS_FIELD_FLAGS] ?? 0) & $this::OS_FFLAG_DELIMITED_ALLOW_SCALARS))
                    throw new \ATL\ObjectStoreException("Scalar value encountered on delimited field `{$field}` to storage conversion while scalars are not allowed in object store class `".get_class($this)."`");
                return $this->escapeStorageString($value);
            }
            return $this->escapeStorageString(implode("\n", $value));

            case $this::OS_FFORMAT_CUSTOM_CONVERT:
            return isset($map[$this::OS_FIELD_CUSTOM_CONVERT_STORE_METHOD]) ?
                $map[$this::OS_FIELD_CUSTOM_CONVERT_STORE_METHOD]($this, $value, $field, $map, ...($map[$this::OS_FIELD_CUSTOM_CONVERT_STORE_METHOD_ARGUMENTS] ?? []))
                : $this->escapeStorageString($value);

            default: throw new \ATL\ObjectStoreException("Unknown format `{$type}` on field `{$field}` to storage conversion in object store class `".get_class($this)."`");
        }
    }

    # converts storage representation to item field, override if you need to make specific conversions not done (or not done properly) by the base implementation
    # the base implementation does simple fast and incomplete validation of the storage values on conversions (and only on conversions), if you want to extend or change such, you need to override this
    # again: take care, values not subject to any type conversion are NOT validated in any way
    protected function convertStorageDataToItemField($value, $field)
    {
        if (!isset($this->fieldMap[$field])) throw new \ATL\ObjectStoreException("Virtual field `{$field}` not found in object store class `".get_class($this)."`");
        $map = $this->fieldMap[$field];

        # NULL handling
        if ($value === null) {
            if (($map[$this::OS_FIELD_FLAGS] ?? 0) & $this::OS_FFLAG_NOT_NULL) {
                if (array_key_exists($this::OS_FIELD_LOAD_CONVERSION_FAILURE_VALUE, $map)) return $map[$this::OS_FIELD_LOAD_CONVERSION_FAILURE_VALUE]; # use failure value if supplied
                throw new \ATL\ObjectStoreException("Field `{$field}` must not be NULL in object store class `".get_class($this)."`");
            }
            return null;
        }

        # type conversion
        switch ($map[$this::OS_FIELD_FORMAT] ?? 0) {
            case $this::OS_FFORMAT_RAW:
            case $this::OS_FFORMAT_BLOB:
            case $this::OS_FFORMAT_IPV4_INT:
            case $this::OS_FFORMAT_IPV4_TEXT:
            case $this::OS_FFORMAT_IPV4_BIN:
            case $this::OS_FFORMAT_IPV6_TEXT:
            case $this::OS_FFORMAT_IPV6_BIN:
            case $this::OS_FFORMAT_NUMERIC:
            return $value;

            case $this::OS_FFORMAT_DATE:
            if (!preg_match('#^(\\d{4})-(\\d{2})-(\\d{2})(?:\\s+|$)#S', trim($value), $m)) goto loadConversionError;
            return mktime(0, 0, 0, $m[2], $m[3], $m[1]);

            case $this::OS_FFORMAT_TIME:
            if (!preg_match('#^(\\d+):(\\d{2}):(\\d{2})(?:\\.\\d+)?$#S', trim($value), $m)) goto loadConversionError;
            return $m[1] * 3600 + $m[2] * 60 + $m[3];

            case $this::OS_FFORMAT_DATETIME:
            if (!preg_match('#^(\\d{4})-(\\d{2})-(\\d{2})\\s+(\\d{1,2}):(\\d{2}):(\\d{2})(?:\\.\\d+)?#S', trim($value), $m)) goto loadConversionError;
            return mktime($m[4], $m[5], $m[6], $m[2], $m[3], $m[1]);

            case $this::OS_FFORMAT_DATEOBJECT:
            if (!preg_match('#^(\\d{4})-(\\d{2})-(\\d{2})\\s+(\\d{1,2}):(\\d{2}):(\\d{2})(?:\\.(\\d+))?#S', trim($value), $m)) goto loadConversionError;
            if (!(($map[$this::OS_FIELD_FLAGS] ?? 0) & $this::OS_FFLAG_DATEOBJECT_MICROTIME)) {
                $dt = new \DateTime();
                $dt = $dt->setTimestamp(mktime($m[4], $m[5], $m[6], $m[2], $m[3], $m[1]));
            } else {
                if (($m[7] ?? '') === '') $m[7] = '0';
                $dt = \DateTime::createFromFormat('U.u', mktime($m[4], $m[5], $m[6], $m[2], $m[3], $m[1]).'.'.$m[7]);
            }
            if ($dt === false) goto loadConversionError;
            return $dt;

            case $this::OS_FFORMAT_IPV4_INT_TEXT:
            $value = ip2long($value);
            if ($value === false) goto loadConversionError;
            return $value;

            case $this::OS_FFORMAT_IPV4_INT_BIN:
            $value = unpack('V', $value);
            return reset($value);

            case $this::OS_FFORMAT_IPV4_TEXT_INT:
            if (!preg_match('#^-?\\d+$#S', trim($value))) goto loadConversionError;
            return long2ip($value);

            case $this::OS_FFORMAT_IPV4_TEXT_BIN:
            $value = unpack('V', $value);
            return long2ip(reset($value));

            case $this::OS_FFORMAT_IPV4_BIN_INT:
            if (!preg_match('#^-?\\d+$#S', trim($value))) goto loadConversionError;
            return pack('V', $value);

            case $this::OS_FFORMAT_IPV4_BIN_TEXT:
            $value = ip2long($value);
            if ($value === false) goto loadConversionError;
            return pack('V', $value);

            case $this::OS_FFORMAT_IPV6_TEXT_BIN:
            $value = inet_ntop($value);
            if ($value === false) goto loadConversionError;
            return $value;

            case $this::OS_FFORMAT_IPV6_BIN_TEXT:
            $value = inet_pton($value);
            if ($value === false) goto loadConversionError;
            return $value;

            case $this::OS_FFORMAT_KV_CUSTOM_DELIMITED:
            $kvDelimiter = $map[$this::OS_FIELD_CUSTOM_KV_DELIMITER] ?? "=";
            $value = explode($map[$this::OS_FIELD_CUSTOM_DELIMITER] ?? "\n", $value);
            $data = [];
            foreach ($value as $kv) {
                if (trim($kv) === '') continue; # skip occasional empty elements if any
                $kv = explode($kvDelimiter, $kv, 2);
                if (count($kv) != 2) goto loadConversionError;
                $data[$kv[0]] = $kv[1];
            }
            return $data;

            case $this::OS_FFORMAT_LIST_CUSTOM_DELIMITED:
            return explode($map[$this::OS_FIELD_CUSTOM_DELIMITER] ?? "\n", $value);

            case $this::OS_FFORMAT_KV_INI:
            return @parse_ini_string($value."\n", false, INI_SCANNER_RAW);

            case $this::OS_FFORMAT_SERIALIZED:
            return unserialize($value, ['allowed_classes' => false]);

            case $this::OS_FFORMAT_JSON:
            case $this::OS_FFORMAT_JSON_PRETTY:
            return json_decode($value, true, 0x1000, JSON_BIGINT_AS_STRING);

            case $this::OS_FFORMAT_LIST_COMMA_DELIMITED:
            return explode(",", $value);

            case $this::OS_FFORMAT_LIST_SPACE_DELIMITED:
            return explode(" ", $value);

            case $this::OS_FFORMAT_LIST_EOL_DELIMITED:
            return explode("\n", $value);

            case $this::OS_FFORMAT_CUSTOM_CONVERT:
            return isset($map[$this::OS_FIELD_CUSTOM_CONVERT_LOAD_METHOD]) ?
                $map[$this::OS_FIELD_CUSTOM_CONVERT_LOAD_METHOD]($this, $value, $field, $map, ...($map[$this::OS_FIELD_CUSTOM_CONVERT_LOAD_METHOD_ARGUMENTS] ?? []))
                : $value;

            default: throw new \ATL\ObjectStoreException("Unknown format `{$type}` on field `{$field}` to storage conversion in object store class `".get_class($this)."`");
        }

        # we only reach there by goto if there is conversion error, this exists because we need to honor OS_FIELD_LOAD_CONVERSION_FAILURE_VALUE
loadConversionError:
        if (array_key_exists($this::OS_FIELD_LOAD_CONVERSION_FAILURE_VALUE, $map)) return $map[$this::OS_FIELD_LOAD_CONVERSION_FAILURE_VALUE];
        throw new \ATL\ObjectStoreException("Failed to convert storage value for field `{$field}` in object store class `".get_class($this)."`");
    }

    # bulk conversion routine: from item data to storage data
    public function convertItemDataToStorageData($data)
    {
        array_walk($data, function (&$value, $field) { $value = $this->convertItemFieldToStorageData($value, $field); });
        return $data;
    }

    # bulk conversion routine: from storage data to item data
    public function convertStorageDataToItemData($data)
    {
        array_walk($data, function (&$value, $field) { $value = $this->convertStorageDataToItemField($value, $field); });
        return $data;
    }

    # translates user-supplied key to ordered internal key
    protected function translateUserSuppliedKey($key)
    {
        if ($this->singleKeyMap) return [$this->keyMap[0] => $key];

        # composite key
        if (!is_array($key) || (count($key) != count($this->keyMap))) throw new \ATL\ObjectStoreException("Key-based access requires array of ".count($this->keyMap)." elements in object store class `".get_class($this)."`");

        # tricky stuff: we can have numbered or associative key array, so we start with indexed and try to fail back to named
        $keyData = [];
        foreach ($this->keyMap as $index => $field) {
            if (array_key_exists($field, $key)) {
                $keyData[$field] = $key[$field];
            } elseif (array_key_exists($index, $key)) {
                $keyData[$field] = $key[$index];
            } else {
                throw new \ATL\ObjectStoreException("Invalid key supplied for key-based access of object store class `".get_class($this)."`");
            }
        }
        return $keyData; # associative key is OK
    }

    # translates key elements to scalar values
    public function translateIndexKey($key)
    {
        return array_map(function ($v) { return (is_scalar($v) && !is_bool($v)) ? (string) $v : serialize($v); }, $key);
    }

    # gets specific multidimension array element by linear key (returning $notFoundReturns value if not found, NULL by default)
    # take care it returns a reference
    protected function getIndexElement($index, $key, $notFoundReturns = null, $translatedKey = false)
    {
        $current = $index;
        foreach ($translatedKey ? $key : $this->translateIndexKey($key) as $value) {
            if (!array_key_exists($value, $current)) return $notFoundReturns;
            $current = $current[$value];
        }
        return $current;
    }

    # places specific element into multidimensional array by linear key
    protected function putIndexElement(&$index, $key, $element, $translatedKey = false)
    {
        $current = &$index;
        if (!$translatedKey) $key = $this->translateIndexKey($key);
        $last = array_pop($key);
        foreach ($key as $value) {
            if (!array_key_exists($value, $current)) $current[$value] = [];
            $current = &$current[$value];
        }
        $current[$last] = $element;
    }

    # removes specific element from multidimensional array by linear key
    # takes care to remove empty elements on the way if anything was removed
    protected function removeIndexElement(&$index, $key, $translatedKey = false)
    {
        $current = &$index;
        $stack = [];
        foreach ($translatedKey ? $key : $this->translateIndexKey($key) as $value) {
            if (!array_key_exists($value, $current)) return false; # nothing to remove
            $stack[] = [&$current, $value];
            $current = &$current[$value];
        }
        foreach (array_reverse($stack) as $cv) {
            unset($cv[0][$cv[1]]);
            if (count($cv[0]) > 0) break;
        }
        return true;
    }

    ########
    # Bulk API

    public function getAllItems()
    {
        return $this->items;
    }

    public function getItemsKey()
    {
        return $this->key;
    }

    public function isEmpty()
    {
        return empty($this->items);
    }

    public function isKeyEmpty()
    {
        return empty($this->key);
    }

    ########
    # ArrayAccess API
    # This is generic API to access single elements by their keys, index scans may be faster for multiple element access
    # The benefit of array access API is that it loads elements that do not actually exist (array get() is local get() alias)
    # Trying to set() anything will result in an exception because set operation is undefined for the store, use create() or put() instead
    # isset() checks if element is visible, if it is loaded but not visible, or if it was attempted to be loaded, but was not found in the storage, isset() will return false
    # unset() operation is silent detach operation, to delete element from storage use delete()
    # if item was attempted to be loaded but was not found in the storage, unset() destroys index part indicating non-loaded state so unset() is also handy to attempt reloads

    #[\ReturnTypeWillChange]
    public function offsetExists($k)
    {
        return ($this->getIndexElement($this->key, $this->translateUserSuppliedKey($k)) !== null);
    }

    #[\ReturnTypeWillChange]
    public function offsetGet($k)
    {
        return $this->get($k);
    }

    #[\ReturnTypeWillChange]
    public function offsetSet($k, $v)
    {
        throw new \ErrorException("Array set operation is not supported for object store class `".get_class($this)."`");
    }

    #[\ReturnTypeWillChange]
    public function offsetUnset($k)
    {
        if ((/** @var ObjectStore\Item */ $item = $this->getIndexElement($this->key, $xKey = $this->translateUserSuppliedKey($k), false)) !== false) {
            if ($item !== null) {
                $this->detach($item, true);
            } else {
                $this->removeIndexElement($this->key, $xKey);
            }
        }
    }

    ########
    # IteratorAggregate API
    # IteratorAggregate API iterates over visible items only, take care not to alter store contents during iteration
    # As IteratorAggregate uses copy of local object list, nested iterations and modifying store contents during iteration is possible
    # Take care keys retrieval is slow enough, but it is necessary for the sake of proper indexing, also the keys returned by iterator may be non-scalar, take care

    #[\ReturnTypeWillChange]
    public function getIterator()
    {
        foreach ($this->items as $item)
            yield $item->getKey() => $item;
    }

    ########
    # Countable API
    # This API just returns number of actually visible items, corresponds to count of iterations in iterator API

    #[\ReturnTypeWillChange]
    public function count()
    {
        return count($this->items);
    }

    ########
    # Serializable API
    # Serialization API really allows to serialize and unserialize store contents
    # It stores all the non-virtual item object properties into serialization array, and recovers objects on unserialization
    # For what is serialized, the ID-based stored object list is serialized, and the visible objects object ID list is serialized
    # This allows to recover all stored objects first, and then recover the visible list by using the visible ID list
    # Due to index elements and change tracking elements being stored separately, the serialized data amount can be rather high and can include redundant data elements which reflect internal state of the item object

    public function __serialize()
    {
        return [
            'stored' => array_map(function (/** @var ObjectStore\Item */ $item) { return $item->serializeObjectStoreItemData(); }, $this->storedItems),
            'visibleIDs' => array_keys($this->items),
        ];
    }

    public function __unserialize($data)
    {
        $this->__construct(); # initialize our instance
        $this->unserializeItems($data); # call real unserialize method
    }

    # this method should be called after object initialization
    # storage-specific derivatives may need to do more initialization between __construct() and unserializing items so it is handy to have a separate real routine for unserialization that can be reused in daughter classes
    protected function unserializeItems($data)
    {
        if (!is_array($data) || !isset($data['stored']) || !isset($data['visibleIDs']))
            throw new \ATL\ObjectStoreItemException("Wrong serialized dataset supplied to object store unserialize routine, object store class `".get_class($this)."`");

        # recover stored items
        foreach ($data['stored'] as $id => $itemData) {
            /** @var ObjectStore\Item */ $item = $this->forgeItem();
            $item->unserializeObjectStoreItemData($itemData);
            $this->storedItems[$item->getObjectStoreItemID()] = $item;
            $this->putIndexElement($this->storedItemsKey, $item->getObjectStoreItemIndexData(), $item);
        }

        # recover visible items list and indexes
        foreach ($data['visibleIDs'] as $id) {
            $item = $this->storedItems[$id];
            $this->items[$item->getObjectStoreItemID()] = $item;
            $this->putIndexElement($this->key, $item->getObjectStoreItemIndexData(), $item);
            $this->putItemIndexes($item);
        }

        # run onAfterUnserialize() handler on all objects
        foreach ($this->storedItems as $item) $item->afterUnserializeObjectStoreItems();
    }

    public function serialize() { return serialize($this->__serialize()); } # compatibility alias to __serialize()
    public function unserialize($string) { $this->unserialize(unserialize($string)); } # compatibility alias to __unserialize()
    public static function __set_state($data) { throw new \ATL\ObjectStoreException("ObjectStore contents cannot be imported directly, object store class `".get_class($this)."`"); }
}

class ObjectStore implements \ATL\IObjectStore { use \ATL\TObjectStore; }

# Exception classes

class ObjectStoreException extends \Exception { }
class ObjectStoreDuplicateException extends \ATL\ObjectStoreException { }
class ObjectStoreItemException extends \Exception { }
