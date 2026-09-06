<?php

namespace ATL\ObjectStore;

########
# for the ObjectStore dynamic schema store and related documentation, see ObjectStore.php and its storage-driven counter parts (i.e. ObjectStoreMySQL.php)
# extend this to create and manipulate schema-based ObjectStore item objects
# if you need to extend the constructor, do not forget to call the original constructor first
# ObjectStore\Item automatically creates all map-induced properties on startup, but if you want top grade creation performance, you must declare them explicitly
# you may override constructor to include anything specific you want to the initialization, but always call the parent constructor before doing anything
# take care that ObjectStore itself does not create new objects on ObjectStore->forgeItem() but uses clone operator to copy empty item objects for performance reasons
# while items implement Serializable interfaces and magics, these only throw exceptions because serialization heavily depends on joined store objects and maps
# to serialize/unserialize items, use internal getObjectStoreItemData() and loadObjectStoreItemData() calls that use ObjectStore map and operate with data arrays corresponding to the field map, virtual properties never get there

########
# why object stores / items are not implemented as bindable objects / containers? in short: they serve different purposes to bring different abstraction levels together
# the bindable objects graph binding and object containers are high-level schema that exists inside your application and dictates how objects interoperate
# object stores / items are in contrary low level schema objects that connects your high-level object interoperation schema to the database, defining how objects are loaded/stored
# these two are designed to coexist, providing object loading/saving via object stores and then object traversal/interconnection is provided via bindings and containers
# you can implement automatical object binding/unbinding and placing/removing to/from containers using event API in your objects, i.e. onAfterStore() and onAfterDetach()
# you can also track changes and implement rebinding / re-placement in i.e. onBeforeReset(), onBeforeResetChanges(), onAfterResetChanges()
# helper functions to reduce boilerplate for this and convert item event model to just autoBindMyself() and autoUnindMyself() API called in places necessary can be found in ObjectStore\Item\BindableObject class
# you can also use virtual properties to proactively load items you want to bind from other object stores and bind to them as necessary, also tracking changes to the properties, i.e. allowing to bind a different object

########
# how to reuse ATL interface-traits-class triads:
# due to PHP not supporting multiple inheritance and heavily limiting overrides from interfaces and traits, we have to use inheritance workaround

# variant 1 (simple): you need only the single ATL interface-trait in your class
#   just extend your class with the interface-trait class with base trait based class, like \ATL\ObjectStore\Item
#     class MyClass extends \ATL\ObjectStore\Item

# variant 2 (complex): you need multiple ATL interfaces-traits in your class (complex)
#   imagine we want both ObjectStore\Item and BindableObject in a single class
#   create your class prototype class definition using both interfaces and both traits, this will implement both but will not allow you to override anything
#     class MyClassPrototype implements \ATL\ObjectStore\IItem, \ATL\IBindableObject, { use \ATL\ObjectStore\TItem, \ATL\TBindableObject; }
#   inherit your class prototype class into your primary class, this workaround gets over PHP override limits and allows you to freely change anything you want
#     class MyClass extends MyClassPrototype { ...your class code... }

#[\AllowDynamicProperties]
interface IItem extends \ArrayAccess, \Serializable
{
    # public API
    public function asArray();
    public function getKey();
    public function getCreateData();

    # internal API
    public function getObjectStoreItemStore();
    public function storeObjectStoreItem(/** @var ObjectStore */ $objectStore);
    public function detachObjectStoreItem($throwIfNotStored = true, $quick = false);
    public function getObjectStoreItemData();
    public function loadObjectStoreItemData($data);
    public function getObjectStoreItemStorageData();
    public function loadObjectStoreItemStorageData($data);
    public function resetObjectStoreItemData();
    public function getObjectStoreItemChanges();
    public function resetObjectStoreItemChanges();
    public function resetObjectStoreItemIndexData();
    public function getObjectStoreItemIndexData($index = null);
    public function getObjectStoreItemID();

    # mandatory PHP magic API
    public function __get($k);
    public function __set($k, $v);
    public function __isset($k);
    public function __debugInfo();
}

#[AllowDynamicProperties]
trait TItem
{
    ########
    # Internal variables

    protected $objectStoreItemID; # cached internal item ID, must be unique for all items in the store, SPL object hash is normally used
    protected /** @var ObjectStore */ $objectStoreItemStore; # object store we currently belong to
    protected $objectStoreItemFieldMap; # instance of field map from the current object store, used for iteration
    protected $readClosureCache; # closure cache that allows us to quickly call virtual callables
    protected $writeClosureCache; # closure cache that allows us to quickly call virtual callables
    protected $objectStoreItemIsInitializing; # used in constructor to inform __set we are initializing, unset after construction
    protected /** @var ObjectStore */ $objectStoreItemIsStored; # used to indicate some instance of ObjectStore has stored us to itself
    protected $objectStoreItemChangeTracking = true; # set this to false in daughter class if you do not want to track changes: will cause storage updates to always include full dataset, using change tracking calculates all OS_FFLAG_VIRTUAL_STORED virtual fields on loads
    protected $objectStoreItemOriginalData; # original loaded data for change tracking on updates, trades off some extra memory for changed objects for less intrusive storage operations, includes all OS_FFLAG_VIRTUAL_STORED virtual fields on loads
    protected $objectStoreItemIndexData; # all key and index fields are stored on loads / updates so index manipulations are faster, this calculates all virtual fields participating in indexes
    protected $objectStoreItemVirtualFieldCache; # this caches down virtual properties contents, we cannot just cache them as property because reads and writes needs to be handled
    public $objectStoreItemDebug = false; # set this to true to enable full scale debug printing

    # public properties corresponding to fields are constructed dynamically

    ########
    # Public API

    # constructs an unstored instance of item, use store put() method to add it to the store
    public function __construct(/** @var \ATL\ObjectStore */ $objectStore)
    {
        $this->objectStoreItemStore = $objectStore;
        $this->objectStoreItemFieldMap = $this->objectStoreItemStore->getFieldMap();

        $this->onBeforeInitialize();

        # dynamically create all the properties necessary
        $this->objectStoreItemIsInitializing = true; # allow __set to act as property creation helper
        $this->resetObjectStoreItemData();
        $this->resetObjectStoreItemIndexData();
        $this->objectStoreItemIsInitializing = false;

        $this->onAfterInitialize();
    }

    # return item properties as array, honoring possible renaming
    public function asArray()
    {
        $data = [];
        foreach ($this->objectStoreItemFieldMap as $field => $mapEntry) {
            if ((($mapEntry[$this->objectStoreItemStore::OS_FIELD_FLAGS] ?? 0) & $this->objectStoreItemStore::OS_FFLAG_VIRTUAL) && (($mapEntry[$this->objectStoreItemStore::OS_FIELD_FLAGS] ?? 0) & $this->objectStoreItemStore::OS_FFLAG_VIRTUAL_NO_READ))
                continue; # skip virtual fields that do not allow reading
            $name = $mapEntry[$this->objectStoreItemStore::OS_FIELD_AS_ARRAY_NAME] ?? $field;
            if ($name === '') continue; # skip fields that are set explicitly to be skipped from asArray
            $data[$name] = $this->$field;
        }
        return $data;
    }

    # retrieves item key in form of associative array or scalar
    public function getKey()
    {
        $key = $this->objectStoreItemStore->translateIndexKey($this->getObjectStoreItemIndexData());
        return (count($key) == 1) ? reset($key) : $key;
    }

    # gets data required for item creation, different from asArray() in that it does not provide fields unnecessary for creation
    public function getCreateData()
    {
        return $this->getObjectStoreItemData(); # simply an alias now, but may be extended with conditions later
    }

    # places a detached item to the store, optionally not throwing exception if this item already exists and is the same item (equivalent of ObjectStore->put())
    public function putToStore(/** @var ObjectStore */ $store, $noThrowOnSameItem = false)
    {
        if ($this->objectStoreItemIsStored)
            throw new \ATL\ObjectStoreItemException("Tried to put item to object store without detaching from another object store first, object store item class `".get_class($this)."`");
        return $store->put($this, $noThrowOnSameItem = false);
    }

    # removes item from the currently attached store visibility, but does not detach the item
    public function removeFromStore()
    {
        if (!$this->objectStoreItemIsStored)
            throw new \ATL\ObjectStoreItemException("Tried to remove item from object store visibility without attaching it first, object store item class `".get_class($this)."`");
        $this->objectStoreItemStore->remove($this);
    }

    # detaches item from the currently attached store
    public function detachFromStore($throwOnDetached = true)
    {
        if (!$this->objectStoreItemIsStored && $throwOnDetached)
            throw new \ATL\ObjectStoreItemException("Tried to detach item from object store without attaching it first, object store item class `".get_class($this)."`");
        return $this->objectStoreItemStore->detach($this);
    }

    # creates stored item from detached item object, optionally replacing existing item in the storage (replace = false means ObjectStoreItemDuplicate or some other exception will be thrown if the item exists in store or storage)
    # replacing item actually detaches the existing item from the store without doing anything with storage and puts the new item to store, that is it, to force item deletion on replacement set delete to true
    # if the storage generates some values for the item, createItem() will not load these values back from the storage until you reload the item in your code
    public function createInStore(/** @var ObjectStore */ $store, $replace = false, $delete = false)
    {
        if ($this->objectStoreItemIsStored)
            throw new \ATL\ObjectStoreItemException("Tried to create item in object store without detaching from another object store first, object store item class `".get_class($this)."`");
        return $store->createItem($this, $replace, $delete);
    }

    public function updateInStore()
    {
        if (!$this->objectStoreItemIsStored && $throwOnDetached)
            throw new \ATL\ObjectStoreItemException("Tried to update item in object store without attaching it first, object store item class `".get_class($this)."`");
        return $this->objectStoreItemStore->update($this);
    }

    public function deleteFromStore()
    {
        if (!$this->objectStoreItemIsStored && $throwOnDetached)
            throw new \ATL\ObjectStoreItemException("Tried to delete item from object store without attaching it first, object store item class `".get_class($this)."`");
        return $this->objectStoreItemStore->delete($this);
    }

    ########
    # Events API
    # This internal API exists to simplify additional object handling during store manipulations in derivatives, while it adds some overhead it is definitely handy to do the magic necessary
    # Override event handlers in your derivatives to handle specific events, do not forget to call parent implementations where you need it though, the default event handlers are all empty
    # Note that high-level operations like storage creation/deletion/whatever are not known to the item, so if you need to react only on high level events then override corresponding ObjectStore methods

    protected function onBeforeInitialize() { } # called in constructor before field initialization begins
    protected function onAfterInitialize() { } # called in constructor after initialization ends
    protected function onBeforeStore($objectStore) { } # called in storeObjectStoreItem before store operation begins
    protected function onAfterStore($objectStore) { } # called in storeObjectStoreItem after store operation ends
    protected function onBeforeDetach() { } # called in detachObjectStoreItem before store operation begins
    protected function onAfterDetach() { } # called in detachObjectStoreItem after store operation ends
    protected function onBeforeLoad(&$data) { } # called in loadObjectStoreItemData before any verifications begin, can alter loaded data
    protected function onAfterLoad($data) { } # called in loadObjectStoreItemData after all operations end
    protected function onBeforeReset() { } # called at start of resetObjectStoreItemData
    protected function onAfterReset() { } # called at end of resetObjectStoreItemData
    protected function onBeforeResetChanges() { } # called at start of resetObjectStoreItemData, only if change tracking is enabled
    protected function onAfterResetChanges() { } # called at end of resetObjectStoreItemData, only if change tracking is enabled
    protected function onBeforeResetIndexData() { } # called at start of resetObjectStoreItemIndexData
    protected function onAfterResetIndexData() { } # called at end of resetObjectStoreItemIndexData
    protected function onDebugInfo(&$data) { } # called at end of __debugInfo before returning the data, can alter data to return
    protected function onSerializeData(&$data) { } # called at end of serializeObjectStoreItemData before returning the data, can alter data to return
    protected function onBeforeUnserializeData(&$data) { } # called in unserializeObjectStoreItemData before any validations, can alter supplied serialized data
    protected function onAfterUnserializeData($data) { } # called in unserializeObjectStoreItemData after all operations end
    protected function onAfterUnserializeAll() { } # called during massive ObjectStore unserialization after all store objects are unserialized

    ########
    # Internal ObjectStore API

    # used to check store the object is stored to
    public function getObjectStoreItemStore()
    {
        return $this->objectStoreItemIsStored ? $this->objectStoreItemStore : null;
    }

    # places item to specific object store, stored items are subject to be manipulated by the store and can inform store about events
    # can only be stored to exactly the same object store class it was created with, placing to daughter classes stores is disallowed explicitly to prevent issues (override if you need it, dangerous)
    public function storeObjectStoreItem(/** @var ObjectStore */ $objectStore)
    {
        if (!(($objectStore instanceof $this->objectStoreItemStore) && ($this->objectStoreItemStore instanceof $objectStore)))
            throw new \ATL\ObjectStoreItemException("Tried to store item from object store class `".get_class($this->objectStoreItemStore)."` to object store of class `".get_class($objectStore)."`");
        if ($this->objectStoreItemIsStored)
            throw new \ATL\ObjectStoreItemException("Tried to store item to object store without detaching first, object store item class `".get_class($this)."`");

        $this->onBeforeStore($objectStore);

        $this->objectStoreItemStore = $objectStore; # swap our primary object to new object store, this also ensures we do not hold GC from killing the original store
        $this->objectStoreItemFieldMap = $this->objectStoreItemStore->getFieldMap(); # update the map
        $this->objectStoreItemStore->storeItem($this); # request objectStore to actually store us
        $this->objectStoreItemIsStored = true;

        $this->onAfterStore($objectStore);
    }

    # detaches item from object store, optionally without throwing any exceptions if not stored, $quick set to true allows to skip actual store detach calls to speed up the store cleanup
    public function detachObjectStoreItem($throwIfNotStored = true, $quick = false)
    {
        if ($throwIfNotStored && !$this->objectStoreItemIsStored)
            throw new \ATL\ObjectStoreItemException("Tried to detach non-stored item from object store, object store item class `".get_class($this)."`");

        $this->onBeforeDetach();

        if (!$quick) $this->objectStoreItemStore->detachItem($this);
        $this->objectStoreItemIsStored = false;

        $this->onAfterDetach();
    }

    # gets all non-virtual and virtual but stored object properties in map-based array for printing or serialization
    # take care stored virtual properties are not actually read, but retrieved from cached value set instead
    public function getObjectStoreItemData()
    {
        $data = [];
        foreach ($this->objectStoreItemFieldMap as $mapKey => $mapEntry) {
            if (!(($mapEntry[$this->objectStoreItemStore::OS_FIELD_FLAGS] ?? 0) & $this->objectStoreItemStore::OS_FFLAG_VIRTUAL)) {
                $data[$mapKey] = $this->$mapKey; # just retrieve
            } elseif (($mapEntry[$this->objectStoreItemStore::OS_FIELD_FLAGS] ?? 0) & $this->objectStoreItemStore::OS_FFLAG_VIRTUAL_STORED) {
                $data[$mapKey] = $this->objectStoreItemVirtualFieldCache[$mapKey] ?? null; # use stored virtual fields cache
            }
        }
        return $data;
    }

    # sets all non-virtual and virtual but stored object properties from data array
    # take care stored virtual properties are not actually written but set into cached written value set
    public function loadObjectStoreItemData($data)
    {
        $this->onBeforeLoad($data);

        if (count($diff = array_diff_key($data, $this->objectStoreItemFieldMap)) != 0) {
            # tried to load data with fields not present in field map
            reset($diff); $key = key($diff);
            throw new \ATL\ObjectStoreItemException("Tried to load data with field `{$key}` not present in field map, object store item class `".get_class($this)."`");
        }

        foreach ($data as $field => $value) {
            $mapEntry = $this->objectStoreItemFieldMap[$field];
            if ((($mapEntry[$this->objectStoreItemStore::OS_FIELD_FLAGS] ?? 0) & $this->objectStoreItemStore::OS_FFLAG_VIRTUAL) && !(($mapEntry[$this->objectStoreItemStore::OS_FIELD_FLAGS] ?? 0) & $this->objectStoreItemStore::OS_FFLAG_VIRTUAL_STORED))
                throw new \ATL\ObjectStoreItemException("Tried to load data for non-storage backed virtual field `{$mapKey}`, object store item class `".get_class($this)."`");
        }

        foreach ($this->objectStoreItemFieldMap as $mapKey => $mapEntry) {
            if (!(($mapEntry[$this->objectStoreItemStore::OS_FIELD_FLAGS] ?? 0) & $this->objectStoreItemStore::OS_FFLAG_VIRTUAL)) {
                if (!array_key_exists($mapKey, $data)) throw new \ATL\ObjectStoreItemException("Tried to load data without required field `{$mapKey}`, object store item class `".get_class($this)."`");
                if ((($mapEntry[$this->objectStoreItemStore::OS_FIELD_FLAGS] ?? 0) & $this->objectStoreItemStore::OS_FFLAG_NOT_NULL) && ($data[$mapKey] === null))
                    throw new \ATL\ObjectStoreItemException("Tried to load NULL data into non-NULL field `{$mapKey}`, object store item class `".get_class($this)."`");

                $this->$mapKey = $data[$mapKey]; # just store
            } elseif (($mapEntry[$this->objectStoreItemStore::OS_FIELD_FLAGS] ?? 0) & $this->objectStoreItemStore::OS_FFLAG_VIRTUAL_STORED) {
                if (!array_key_exists($mapKey, $data)) throw new \ATL\ObjectStoreItemException("Tried to load data without required storage-backed virtual field `{$mapKey}`, object store item class `".get_class($this)."`");
                if ((($mapEntry[$this->objectStoreItemStore::OS_FIELD_FLAGS] ?? 0) & $this->objectStoreItemStore::OS_FFLAG_NOT_NULL) && ($data['mapKey'] === null))
                    throw new \ATL\ObjectStoreItemException("Tried to load NULL data into non-NULL storage-backed virtual field `{$mapKey}`, object store item class `".get_class($this)."`");
                if (!is_array($this->objectStoreItemVirtualFieldCache)) $this->objectStoreItemVirtualFieldCache = [];

                $this->objectStoreItemVirtualFieldCache[$mapKey] = $data[$mapKey];
            }
        }

        $this->onAfterLoad($data);
    }

    # gets all stored object properties in store format for storage
    public function getObjectStoreItemStorageData()
    {
        return $this->objectStoreItemStore->convertItemDataToStorageData($this->getObjectStoreItemData());
    }

    # sets all stored object properties from store format
    public function loadObjectStoreItemStorageData($data)
    {
        $this->loadObjectStoreItemData($this->objectStoreItemStore->convertStorageDataToItemData($data));
    }

    # resets all object properties to null and clears the virtual field cache, stored objects cannot be reset because they are intended to contain proper key
    # as you see, new object creation without predefined properties may be slow because of lots of __set() calls, and even with predefined properties the loop is heavy
    # to avoid calling this every single time, ObjectStore precreates empty item and uses clone operator on it in ObjectStore->forgeItem() so using ObjectStore->forgeItem() is recommended
    # the internallyDetached parameter is intended for internal calls from ObjectStore where reset is intended to happen internally on items undergoing modification with pseudo detachment
    public function resetObjectStoreItemData($internallyDetached = false)
    {
        if ($this->objectStoreItemIsStored && !$internallyDetached) throw new \ATL\ObjectStoreItemException("Tried to reset item stored to object store without detaching first, object store item class `".get_class($this)."`");

        $this->onBeforeReset();

        $this->objectStoreItemOriginalData = null;
        $this->objectStoreItemVirtualFieldCache = null;
        foreach ($this->objectStoreItemFieldMap as $mapKey => $mapEntry) {
            if (!(($mapEntry[$this->objectStoreItemStore::OS_FIELD_FLAGS] ?? 0) & $this->objectStoreItemStore::OS_FFLAG_VIRTUAL)) {
                # normal field, populate
                $this->$mapKey = $value = $mapEntry[$this->objectStoreItemStore::OS_FIELD_DEFAULT_VALUE] ?? null;

                # populate change tracking if desired
                if ($this->objectStoreItemChangeTracking && ($value !== null)) {
                    if (!is_array($this->objectStoreItemOriginalData)) $this->objectStoreItemOriginalData = [];
                    $this->objectStoreItemOriginalData[$mapKey] = $value;
                }
            } else {
                if (($mapEntry[$this->objectStoreItemStore::OS_FIELD_FLAGS] ?? 0) & $this->objectStoreItemStore::OS_FFLAG_VIRTUAL_STORED) {
                    # stored virtual entry, still populate the cache with default value if we have one
                    $value = $mapEntry[$this->objectStoreItemStore::OS_FIELD_DEFAULT_VALUE] ?? null;
                    if ($value !== null) {
                        if ($this->objectStoreItemVirtualFieldCache === null) $this->objectStoreItemVirtualFieldCache = [];
                        $this->objectStoreItemVirtualFieldCache[$mapKey] = $value;
                    }

                    # populate change tracking data if desired
                    if ($this->objectStoreItemChangeTracking && ($value !== null)) {
                        if (!is_array($this->objectStoreItemOriginalData)) $this->objectStoreItemOriginalData = [];
                        $this->objectStoreItemOriginalData[$mapKey] = $value;
                    }
                }
            }
        }

        $this->onAfterReset();
    }

    # retrieves list of keys and original values changed from the last loadItemData / resetItemData calls
    public function getObjectStoreItemChanges()
    {
        # everything needs to be updated if there is no change tracking, but we need to exclude virtual properties that are not stored
        if (!$this->objectStoreItemChangeTracking) {
            return array_keys(array_filter($this->objectStoreItemFieldMap,
                function ($mapEntry) {
                    return (
                        !(($mapEntry[$this->objectStoreItemStore::OS_FIELD_FLAGS] ?? 0) & $this->objectStoreItemStore::OS_FFLAG_VIRTUAL)
                        || (($mapEntry[$this->objectStoreItemStore::OS_FIELD_FLAGS] ?? 0) & $this->objectStoreItemStore::OS_FFLAG_VIRTUAL_STORED)
                    );
                }
            ));
        }

        # change tracking on, detect exact changes
        $change = [];
        if (is_array($this->objectStoreItemOriginalData)) {
            # loaded data present, detect changes
            foreach ($this->objectStoreItemFieldMap as $mapKey => $mapEntry) {
                if (!(($mapEntry[$this->objectStoreItemStore::OS_FIELD_FLAGS] ?? 0) & $this->objectStoreItemStore::OS_FFLAG_VIRTUAL)) {
                    if ($this->$mapKey !== ($this->objectStoreItemOriginalData[$mapKey] ?? null))
                        $change[$mapKey] = $this->objectStoreItemOriginalData[$mapKey];
                } elseif (($mapEntry[$this->objectStoreItemStore::OS_FIELD_FLAGS] ?? 0) & $this->objectStoreItemStore::OS_FFLAG_VIRTUAL_STORED) {
                    if ($this->$mapKey !== (($this->objectStoreItemVirtualFieldCache !== null) ? ($this->objectStoreItemVirtualFieldCache[$mapKey] ?? null) : null))
                        $change[$mapKey] = $this->objectStoreItemVirtualFieldCache[$mapKey];
                }
            }
        } else {
            # no loaded data present, everything that is not NULL is changed
            foreach ($this->objectStoreItemFieldMap as $mapKey => $mapEntry) {
                if (!(($mapEntry[$this->objectStoreItemStore::OS_FIELD_FLAGS] ?? 0) & $this->objectStoreItemStore::OS_FFLAG_VIRTUAL)) {
                    if ($this->$mapKey !== null)
                        $change[$mapKey] = null;
                } elseif (($mapEntry[$this->objectStoreItemStore::OS_FIELD_FLAGS] ?? 0) & $this->objectStoreItemStore::OS_FFLAG_VIRTUAL_STORED) {
                    if ((($this->objectStoreItemVirtualFieldCache !== null) ? ($this->objectStoreItemVirtualFieldCache[$mapKey] ?? null) : null) !== null)
                        $change[$mapKey] = null;
                }
            }
        }
        return $change;
    }

    # resets change tracking to the current data
    public function resetObjectStoreItemChanges()
    {
        if (!$this->objectStoreItemChangeTracking) return; # nothing to do

        $this->onBeforeResetChanges();

        $this->objectStoreItemOriginalData = [];
        foreach ($this->objectStoreItemFieldMap as $mapKey => $mapEntry) {
            if (!(($mapEntry[$this->objectStoreItemStore::OS_FIELD_FLAGS] ?? 0) & $this->objectStoreItemStore::OS_FFLAG_VIRTUAL)) {
                $this->objectStoreItemOriginalData[$mapKey] = $this->$mapKey;
            } elseif (($mapEntry[$this->objectStoreItemStore::OS_FIELD_FLAGS] ?? 0) & $this->objectStoreItemStore::OS_FFLAG_VIRTUAL_STORED) {
                $this->objectStoreItemOriginalData[$mapKey] = (($this->objectStoreItemVirtualFieldCache !== null) ? ($this->objectStoreItemVirtualFieldCache[$mapKey] ?? null) : null);
            }
        }

        $this->onAfterResetChanges();
    }

    # resets index data necessary for item manipulations
    public function resetObjectStoreItemIndexData()
    {
        $this->onBeforeResetIndexData();
        $this->objectStoreItemIndexData = null;
        $this->onAfterResetIndexData();
    }

    # retrieves stored index data necessary for item manipulations, null for the key
    # if index data for specific index is not yet created, it gets created
    public function getObjectStoreItemIndexData($index = null)
    {
        $sourceMap = ($index === null) ? $this->objectStoreItemStore->getKeyMap() : $this->objectStoreItemStore->getIndexMap($index);
        if (count($sourceMap) == 0) return ['objectStoreItemID' => $this->getObjectStoreItemID()]; # should happen only for key in case we have no key, we return our general ID key then

        $data = [];
        if (!is_array($this->objectStoreItemIndexData)) $this->objectStoreItemIndexData = [];
        foreach ($sourceMap as $order => $field) {
            if (!array_key_exists($field, $this->objectStoreItemIndexData))
                $this->objectStoreItemIndexData[$field] = $this->$field; # place field contents into index data
            $data[$field] = $this->objectStoreItemIndexData[$field];
        }
        return $data;
    }

    # internal object ID generator, must be unique for all objects in the store, SPL object hash is normally used, but you can override it (should not be needed to do this though)
    public function getObjectStoreItemID()
    {
        if ($this->objectStoreItemID !== null) return $this->objectStoreItemID;
        $this->objectStoreItemID = \ATL\Routines::getCacheableObjectID($this);
        return $this->objectStoreItemID;
    }

    ########
    # Virtual property handling

    # this one is called for virtual field reading
    public function __get($k)
    {
        if (!isset($this->objectStoreItemFieldMap[$k])) throw new \ATL\ObjectStoreItemException("Virtual field `{$k}` not found for object store item class `".get_class($this)."` on read");
        $map = $this->objectStoreItemFieldMap[$k];
        $flags = $map[$this->objectStoreItemStore::OS_FIELD_FLAGS] ?? 0;
        if (!($flags & $this->objectStoreItemStore::OS_FFLAG_VIRTUAL)) throw new \ErrorException("Internal error: tried to read non-virtual field `{$k}` as virtual for store item class `".get_class($this)."` on write");
        if ($flags & $this->objectStoreItemStore::OS_FFLAG_VIRTUAL_NO_READ) throw new \ATL\ObjectStoreItemException("Tried to read virtual field `{$k}` that denies reads for store item class `".get_class($this)."` on write");
        if (!($flags & $this->objectStoreItemStore::OS_FFLAG_VIRTUAL_NO_CACHE) && is_array($this->objectStoreItemVirtualFieldCache) && array_key_exists($k, $this->objectStoreItemVirtualFieldCache)) return $this->objectStoreItemVirtualFieldCache[$k]; # found cached value

        $v = $this->objectStoreItemVirtualFieldCache[$k] ?? null; # still get value from the cache if exists, because we may use direct reads of customly stored data without having any read method

        if (isset($map[$this->objectStoreItemStore::OS_FIELD_VIRTUAL_READ_METHOD])) {
            # we have a method to retrieve the value, use cached closure or cache it and use
            $method = $this->readClosureCache[$k] ?? ($this->readClosureCache[$k] = Routines::callableToClosure([$this, $map[$this->objectStoreItemStore::OS_FIELD_VIRTUAL_READ_METHOD]], true));
            $v = $method($this, $k, $map, ...($map[$this->objectStoreItemStore::OS_FIELD_VIRTUAL_READ_METHOD_ARGUMENTS] ?? []));

            if (!($flags & $this->objectStoreItemStore::OS_FFLAG_VIRTUAL_NO_CACHE)) {
                # we may cache this field
                if (!is_array($this->objectStoreItemVirtualFieldCache)) $this->objectStoreItemVirtualFieldCache = [];
                $this->objectStoreItemVirtualFieldCache[$k] = $v;
            }
        }

        return $v;
    }

    # this one is normally called for virtual field writing, but it has one extra special purpose
    # on object initialization, it dynamically creates assigned object properties for the first time
    public function __set($k, $v)
    {
        if (!isset($this->objectStoreItemFieldMap[$k])) throw new \ATL\ObjectStoreItemException("Virtual field `{$k}` not found for object store item class `".get_class($this)."` on write");
        $map = $this->objectStoreItemFieldMap[$k];
        $flags = $map[$this->objectStoreItemStore::OS_FIELD_FLAGS] ?? 0;

        if ($this->objectStoreItemIsInitializing) {
            # separate handler for initialization phase, here we only create the properties
            if (($flags & $this->objectStoreItemStore::OS_FFLAG_VIRTUAL)) throw new \ErrorException("Internal error: writing virtual field `{$k}` is not allowed during initialization for store item class `".get_class($this)."` on write");
            $this->$k = $v;
            return;
        }

        if (!($flags & $this->objectStoreItemStore::OS_FFLAG_VIRTUAL)) throw new \ErrorException("Internal error: tried to write non-virtual field `{$k}` as virtual for store item class `".get_class($this)."` on write");
        if ($flags & $this->objectStoreItemStore::OS_FFLAG_VIRTUAL_NO_WRITE) throw new \ATL\ObjectStoreItemException("Tried to write virtual field `{$k}` that denies writes for store item class `".get_class($this)."` on write");

        if (isset($map[$this->objectStoreItemStore::OS_FIELD_VIRTUAL_WRITE_METHOD])) {
            # we have a method to write the value, use cached closure or cache it and use
            $method = $this->writeClosureCache[$k] ?? ($this->writeClosureCache[$k] = Routines::callableToClosure([$this, $map[$this->objectStoreItemStore::OS_FIELD_VIRTUAL_WRITE_METHOD]], true));
            $v = $method($this, $v, $k, $map, ...($map[$this->objectStoreItemStore::OS_FIELD_VIRTUAL_WRITE_METHOD_ARGUMENTS] ?? []));
        }

        # store value to cache, even if it is uncached, we still may need the value for stored virtual fields
        if (!is_array($this->objectStoreItemVirtualFieldCache)) $this->objectStoreItemVirtualFieldCache = [];
        $this->objectStoreItemVirtualFieldCache[$k] = $v;

        return $v; # PHP does not require us to return values, but as we ourselves do require our setter functions to, we still do it
    }

    # this one is called for virtual field checkup
    public function __isset($k)
    {
        if (!isset($this->objectStoreItemFieldMap[$k])) return false; # no such field
        $map = $this->objectStoreItemFieldMap[$k];
        $flags = $map[$this->objectStoreItemStore::OS_FIELD_FLAGS];
        if (!($flags & $this->objectStoreItemStore::OS_FFLAG_VIRTUAL)) throw new \ErrorException("Internal error: tried to check non-virtual field `{$k}` as virtual for store item class `".get_class($this)."`");
        if ($flags & $this->objectStoreItemStore::OS_FFLAG_VIRTUAL_NO_READ) return false; # this field does not provide read capabilities
        return ($this->$k !== null); # isset() is expected to return false on NULLs, so we actually need to read the virtual field, sorry
    }

    ########
    # ArrayAccess API
    # This API is pretty much slow, try to avoid using it if possible, although array-type isset() may come handy, it allows to check if field exists, is readable and is not NULL

    #[\ReturnTypeWillChange]
    public function offsetExists($k)
    {
        return isset($this->objectStoreItemFieldMap[$k]) ? isset($this->$k) : false;
    }

    #[\ReturnTypeWillChange]
    public function offsetGet($k)
    {
        if (!isset($this->objectStoreItemFieldMap[$k])) throw new \ATL\ObjectStoreItemException("Field `{$k}` does not exist in store item class `".get_class($this)."` on array-based read");
        return $this->$k;
    }

    #[\ReturnTypeWillChange]
    public function offsetSet($k, $v)
    {
        if (!isset($this->objectStoreItemFieldMap[$k])) throw new \ATL\ObjectStoreItemException("Field `{$k}` does not exist in store item class `".get_class($this)."` on array-based write");
        $this->$k = $v;
    }

    # the only tricky one, we never unset non-virtual properties and unsetting virtual properties only destroys the virtual property cache, causing virtual property to be read anew on next read access
    # if property does not have an actual read cache, stored value will still be kept intact
    #[\ReturnTypeWillChange]
    public function offsetUnset($k)
    {
        if (!isset($this->objectStoreItemFieldMap[$k])) throw new \ATL\ObjectStoreItemException("Field `{$k}` does not exist in store item class `".get_class($this)."` on array-based unset");
        $map = $this->objectStoreItemFieldMap[$k];
        $flags = $map[$this->objectStoreItemStore::OS_FIELD_FLAGS];
        if (($flags & $this->objectStoreItemStore::OS_FFLAG_VIRTUAL) && !($flags & $this->objectStoreItemStore::OS_FFLAG_VIRTUAL_NO_READ) && isset($map[$this->objectStoreItemStore::OS_FIELD_VIRTUAL_READ_METHOD]))
            unset($this->objectStoreItemVirtualFieldCache[$k]);
    }

    ########
    # Debug printing API

    public function __debugInfo()
    {
        # for normal debug prints, we get real item data stored, virtual data and internal fields are not to be printed
        $data = $this->getObjectStoreItemData();

        # remove all fields explicitly marked as not for debug or logging from the result
        foreach ($this->objectStoreItemFieldMap as $mapKey => $mapEntry)
            if (($mapEntry[$this->objectStoreItemStore::OS_FIELD_FLAGS] ?? 0) & $this->objectStoreItemStore::OS_FFLAG_NO_LOG_OR_PRINT)
                unset($data[$mapKey]);

        if ($this->objectStoreItemDebug) {
            # full debug mode requested, add internals (this may uncover some non-loggable items if they are part of indexes or keys)
            $data['objectStoreItemID'] = $this->objectStoreItemID;
#            $data['objectStoreItemStore'] = \ATL\Routines::getCacheableObjectID($this->objectStoreItemStore);
#            $data['objectStoreItemFieldMap'] = $this->objectStoreItemFieldMap;
            $data['objectStoreItemIsStored'] = $this->objectStoreItemIsStored;
            $data['objectStoreItemChangeTracking'] = $this->objectStoreItemChangeTracking;
            $data['objectStoreItemOriginalData'] = $this->objectStoreItemOriginalData;
            $data['objectStoreItemIndexData'] = $this->objectStoreItemIndexData;
            $data['objectStoreItemVirtualFieldCache'] = $this->objectStoreItemVirtualFieldCache;
        }

        $this->onDebugInfo($data);
        return $data;
    }

    ########
    # Serializabe APIs (throwing exceptions)

    public function __serialize() { throw new \ErrorException("ObjectStore items cannot be serialized directly, use getObjectStoreItemData() to obtain data, object store item class `".get_class($this)."`"); }
    public function __unserialize($data) { throw new \ErrorException("ObjectStore items cannot be unserialized directly, use loadObjectStoreItemData() to load data back instead, object store item class `".get_class($this)."`"); }
    public function serialize() { throw new \ErrorException("ObjectStore items cannot be serialized directly, use getObjectStoreItemData() to obtain data, object store item class `".get_class($this)."`"); }
    public function unserialize($string) { throw new \ErrorException("ObjectStore items cannot be unserialized directly, use loadObjectStoreItemData() to load data back instead, object store item class `".get_class($this)."`"); }
    public static function __set_state($data) { throw new \ErrorException("ObjectStore items cannot be imported directly, use loadObjectStoreItemData() to load data back instead, object store item class `".get_class($this)."`"); }

    ########
    # Real serialization API for ObjectStore Serializable API
    # This internal API provides a way to store and recover internal object state for ObjectStore serialization
    # It can only be used with already created compatible item objects, and provides a reflection of internal object state, including data, change tracking, index data, etc.
    # Unserialize can only be called on clean objects, calling this on objects with any data set or on objects attached to stores will result in unpredictable behavior
    # Take care trying to cross-unserialize different item object types will result in unpredictable behavior, always make sure store objects are compatible before unserializing

    public function serializeObjectStoreItemData()
    {
        $data = [
            'id' => $this->objectStoreItemID,
            'nvFields' => [],
            'changeTracking' => $this->objectStoreItemChangeTracking,
            'originalData' => $this->objectStoreItemOriginalData,
            'indexData' => $this->objectStoreItemIndexData,
            'virtualFieldCache' => $this->objectStoreItemVirtualFieldCache,
        ];

        foreach ($this->objectStoreItemFieldMap as $mapKey => $mapEntry)
            if (!(($mapEntry[$this->objectStoreItemStore::OS_FIELD_FLAGS] ?? 0) & $this->objectStoreItemStore::OS_FFLAG_VIRTUAL))
                $data['nvFields'][$mapKey] = $this->$mapKey;

        $this->onSerializeData($data);

        return $data;
    }

    public function unserializeObjectStoreItemData($data)
    {
        $this->onBeforeUnserializeData($data);

        if (
            !is_array($data) || !array_key_exists('id', $data) || !array_key_exists('nvFields', $data) || !array_key_exists('changeTracking', $data)
            || !array_keys('originalData', $data) || !array_key_exists('indexData', $data) || !array_key_exists('virtualFieldCache', $data)
        ) {
            throw new \ATL\ObjectStoreItemException("Wrong serialized dataset supplied to unserializeObjectStoreItemData(), object store item class `".get_class($this)."`");
        }

        $this->objectStoreItemID = $data['id'];
        $this->objectStoreItemChangeTracking = $data['changeTracking'];
        $this->objectStoreItemOriginalData = $data['originalData'];
        $this->objectStoreItemIndexData = $data['indexData'];
        $this->objectStoreItemVirtualFieldCache = $data['virtualFieldCache'];

        foreach ($data['nvFields'] as $field => $value) {
            if (!isset($this->objectStoreItemFieldMap[$field]))
                throw new \ATL\ObjectStoreItemException("Wrong serialized dataset (unknown `{$field}` field) supplied to unserializeObjectStoreItemData(), object store item class `".get_class($this)."`");
            if (($this->objectStoreItemFieldMap[$field][$this->objectStoreItemStore::OS_FIELD_FLAGS] ?? 0) & $this->objectStoreItemStore::OS_FFLAG_VIRTUAL)
                throw new \ATL\ObjectStoreItemException("Wrong serialized dataset (serialized field `{$field}` is virtual) supplied to unserializeObjectStoreItemData(), object store item class `".get_class($this)."`");
            $this->$field = $data['nvFields'][$mapKey];
        }

        $this->onAfterUnserializeData($data);
    }

    # this is actually just event call (onAfterUnserializeAll) made by ObjectStore in unserialization after all object store items are unserialized
    public function afterUnserializeObjectStoreItems()
    {
        $this->onAfterUnserializeAll();
    }
}

#[AllowDynamicProperties]
class Item implements \ATL\ObjectStore\IItem { use \ATL\ObjectStore\TItem; }
