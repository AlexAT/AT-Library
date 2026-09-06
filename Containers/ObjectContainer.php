<?php

namespace ATL;

########
# how to reuse ATL interface-traits-class triads:
# due to PHP not supporting multiple inheritance and heavily limiting overrides from interfaces and traits, we have to use inheritance workaround

# variant 1 (simple): you need only the single ATL interface-trait in your class
#   just extend your class with the interface-trait class with base trait based class, like \ATL\BindableObject
#     class MyClass extends \ATL\BindableObject

# variant 2 (complex): you need multiple ATL interfaces-traits in your class (complex)
#   imagine we want both BindableObject and ObjectContainer in a single class
#   create your class prototype class definition using both interfaces and both traits, this will implement both but will not allow you to override anything
#     class MyClassPrototype implements \ATL\IBindableObject, \ATL\IObjectContainer { use \ATL\TBindableObject, \ATL\TObjectContainer; }
#   inherit your class prototype class into your primary class, this workaround gets over PHP override limits and allows you to freely change anything you want
#     class MyClass extends MyClassPrototype { ...your class code... }

interface IObjectContainer
{
    const OC_OBJECT_CLASS = 0; # class name that is allowed to be stored for objectContainerAccess
    const OC_OBJECT_CALLBACK = 1; # for storeObject/removeObject, method of ($storeClass, $object, $store = true (store)/false (remove), $descriptor, $id), callbacks are called after actual store/removal

    const objectContainerAccess = []; # each descriptor is class => object class, array properties should be created per each object class, keys in properties are object IDs

    public function storeObject($object);
    public function removeObject($object);
    public function getObject($storeClass, $id, $throwIfNotExist = false);
}

trait TObjectContainer
{
    public function storeObject($object)
    {
        $class = $this->findObjectContainmentClass($object);
        if ($class === null) throw new \ATL\ObjectContainerStoreException("Object storage failure: `{$class}` objects are not allowed");
        $descriptor = static::objectContainerAccess[$class];
        $storeClass = $descriptor[static::OC_OBJECT_CLASS];
        if (!is_array($store = &$this->$storeClass)) throw new \ATL\ObjectContainerStoreException("Object storage failure: property for `{$class}` objects does not exist or is not an array");
        $id = method_exists($object, 'getObjectID') ? $object->getObjectID() : \ATL\Routines::getCacheableObjectID($object);
        if (isset($store[$id])) throw new \ATL\ObjectContainerStoreException("Object storage failure: `{$storeClass}` object ID `{$id}` is already stored");
        $store[$id] = $object;

        if (isset($descriptor[static::OC_OBJECT_CALLBACK])) {
            $method = $descriptor[static::OC_OBJECT_CALLBACK];
            $this->$method($storeClass, $object, true, $descriptor, $id);
        }
    }

    public function removeObject($object)
    {
        $class = $this->findObjectContainmentClass($object);
        if ($class === null) throw new \ATL\ObjectContainerStoreException("Object removal failure: `{$class}` objects are not allowed");
        $descriptor = static::objectContainerAccess[$class];
        $storeClass = $descriptor[static::OC_OBJECT_CLASS];
        if (!is_array($store = &$this->$storeClass)) throw new \ATL\ObjectContainerRemoveException("Object removal failure: property for `{$class}` objects does not exist or is not an array");
        $id = method_exists($object, 'getObjectID') ? $object->getObjectID() : \ATL\Routines::getCacheableObjectID($object);
        if (!isset($store[$id])) throw new \ATL\ObjectContainerRemoveException("Object removal failure: `{$storeClass}` object ID `{$id}` does not exist in store");
        unset($store[$id]);

        if (isset($descriptor[static::OC_OBJECT_CALLBACK])) {
            $method = $descriptor[static::OC_OBJECT_CALLBACK];
            $this->$method($storeClass, $object, false, $descriptor, $id);
        }
    }

    public function getObject($storeClass, $id, $throwIfNotExist = false)
    {
        if (!is_array($store = &$this->$storeClass)) throw new \ATL\ObjectContainerNotFoundException("Object storage failure: property for `{$storeClass}` objects does not exist or is not an array");
        if ($throwIfNotExist && !isset($store[$id])) throw new \ATL\ObjectContainerNotFoundException("Object `{$id}` of class `{$storeClass}` does not exist");
        return $store[$id] ?? null;
    }

    protected function findObjectContainmentClass($object)
    {
        $class = get_class($object);
        if (isset(static::objectContainerAccess[$class])) return $class;

        # check parent classes
        $parentClasses = class_parents($object);
        $class = null;
        foreach ($parentClasses as $pClass) {
            if (isset(static::objectContainerAccess[$pClass])) {
                $class = $pClass;
                break;
            }
        }

        # also check interfaces if class is still not found
        if ($class === null) {
            $interfaces = class_implements($object);
            foreach ($interfaces as $interface) {
                if (isset(static::objectContainerAccess[$interface])) {
                    $class = $interface;
                    break;
                }
            }
        }

        return $class;
    }
}

class ObjectContainer implements \ATL\IObjectContainer { use \ATL\TObjectContainer; }

########
# this interesting static class allows objects to get unique always increasing indexes on creation
# if object ID is not passed to getIndex, it is calculated from the object itself (either getObjectID or SPL hash)
# not exactly suitable for highly dynamic objects because object removal / index reuse is not implemented, but can still be used if you know what are you doing

class ObjectAutoIndex
{
    const INDEX_BASE = 1; # indexes are 1-based because zero index can be used as special one

    static public $classIndexes = [];
    static public $registeredIndexes = [];

    static public function getObjectIndex($object, $objectID = null)
    {
        $class = get_class($object);
        if ($objectID === null) $objectID = $object->getObjectID();
        if (!isset(self::$classIndexes[$class])) {
            self::$classIndexes[$class] = self::INDEX_BASE;
            self::$registeredIndexes[$class] = [];
        }
        if (isset(self::$registeredIndexes[$class][$objectID])) return self::$registeredIndexes[$class][$objectID];
        self::$registeredIndexes[$class][$objectID] = ($index = self::$classIndexes[$class]++);
        return $index;
    }
}

# Exception classes

class ObjectContainerStoreException extends \Exception { }
class ObjectContainerRemoveException extends \Exception { }
class ObjectContainerNotFoundException extends \Exception { }
