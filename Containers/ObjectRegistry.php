<?php

namespace ATL;

########
# this is powerful static class which provides object registry capable of instantiating your specific system wide named objects and keeping track of them
# to automatically instantiate object, you need to first register the instantiating class with the ObjectRegistry with the parameters desired for the constructor
# in example if you want a system wide lock manager, you just instantiate it with the object registry using some distinct name like "LockManager" and then reference it by name from anywhere
# in specific entry points of your code you may need different lock managers, you just register compatible object you need in the object registry with this name, and instantiation code will instantiate it by name
# registry also allows parts of code that need their own private object instances not shared with the rest of the code to have them or to clone base instance and use it, not affecting the rest of the code

# example:
#   MyRegistry::registerClass('LockManager', '\\ATL\\Locking\\Simple', '/my/locking/dir');
#   $lockManager = MyRegistry::getInstance('LockManager');
# you can also create clean instances of existing registered objects as private objects or clone existing registered objects as private objects
# you can also register arbitrary objects in the registry, but their duplication would be possible only using clonePrivateInstance, not getPrivateInstance
# getPrivateInstance also allows you to substitute a different set of arguments for the instantiated object constructor, but the use of this is discouraged because it expects all class constructors possible to be compatible

# normally you are better off instantiating your own registry instance in your namespace instead of using AT/Library global one for the sake of logical namespace separation
# due to PHP static properties inheritance quirk, DO NOT inherit the ObjectRegistry class itself, instantiate your own copy as follows:
# class MyRegistry implements \ATL\IObjectRegistry { use \ATL\TObjectRegistry; }
# if you do not do that, static properties will be shared with the base \ATL\ObjectRegistry class

# basically object registry is behaving like extension of singletons and static classes, merging their benefits and being better than both on a short notice:
# difference from singletons: the named objects are actually instantiated on demand only, can be cloned and re-instantiated as needed
# difference from static classes: possibility to use any normal instantiated object as named instance, not the class name itself, real objects that can be passed anywhere
# you can also quickly list all the named instances by their class name, remove and replace named instances as needed inside your code

# in example you may want your own easily found messaging class not shared with any other namespaces that can be doing the same
# you just use MyRegistry::registerInstance('Messaging', '\\ATL\\Messaging') and then whenever you need your messaging instance, you just do $messaging = MyRegistry::getInstance('Messaging') to retrieve it
# \ATL\Messaging will be instantiated only once in your registry, and each call to getInstance() would give you this "singleton" object instance in every place you use it, sharing message rooms with your code only
# or you can temporarily create a different "private" messaging instance for some subset of your code by using getPrivateInstance() call on your "Messaging" singleton
# should you later use some extended version of \ATL\Messaging class, you just replace the registration in your code initialization with your new class, and voila, everything uses your new class now everywhere the same way
# this completely gets you rid of using static classes for singletons, providing a sane way to use non-static classes as global single instances that are just shared throughout the rest of your code via registry

# instantiated objects magic methods:
#   registryAddedInstance($registryName, $objectName, ...$args) - called after adding object to registry
#   registryRemovedInstance($registryName, $objectName, ...$args) - called after removing object from registry
#   registryPrivateInstance($registryName, $objectName, $parentObject, ...$args) - called on created object after creating private instance, original object $parentObject may be null if it is not yet instantiated
#   registryClonedInstance($registryName, $objectName, $parentObject, ...$args) - called on created object after creating cloned instance
#   registryInstantiatedPrivate($registryName, $objectName, $newObject, ...$args) - called on original object after creating private instance, only if the original object was instantiated
#   registryInstantiatedClone($registryName, $objectName, $newObject, ...$args) - called on original object after creating cloned instance, only if the original object was instantiated

# while the registry implementation may seem a bit of overkill, if you start working with lots of global objects that can vary but are to be used similarly, you will understand how it optimizes the code
# i.e. global database abstraction object for stores may be instantiated and passed via registry like that:
#   MyRegistry::registerClass('MyStore_MyTable', '\\ATL\MyStoreDatabase_MyTable', MyRegistry::getInstance('MyDatabaseAbstraction'), 'MyTable');
# without need to think about storing database abstractions elsewhere and passing them down all the way
# or a model store object for specific table can be reinstantiated or cloned by using getPrivateInstance for private use somewhere where global object cached data should not be touched
# it also isolates object consumers from instantiation details, i.e. ones using MyStore_MyTable do not need to know about exact constructor call, even if they want private instance
# this way you can easily alter and extend named object instantiation and constructor API, while keeping object consumer API stable
# you can also substitute consumer API compatible classes and no consumer will notice the actual object class is different from what is initially expected
# i.e. you can have some MyStore_MyTable named object, and while working on i.e. backup or different tenant versions of data, you can substitute MyStore_MyTable named object:
#   tenant 1 initialization: MyRegistry::registerInstance('MyStore_MyTable', '\\ATL\MyStoreDatabase_MyTable', MyRegistry::getInstance('MyDatabaseAbstraction_Tenant1'), 'MyTable_Tenant1');
#   tenant 2 initialization: MyRegistry::registerInstance('MyStore_MyTable', '\\ATL\MyStoreDatabase_MyTable', MyRegistry::getInstance('MyDatabaseAbstraction_Tenant2'), 'MyTable_Tenant2');
# then any shared code using MyStore_MyTable will get the actual database instance to be used without need to know which tenant it belongs to, which kind of database and which table is accessed, etc.

# if you need to return a specific instance of object that is actually placed into registry instead of instantiating, use registerInstanceObject()

# if you want an object factory where instantiated class actually returns some other object to be placed into registry as instance, you can return this object from registryAddedInstance() call (returning NULL finishes instantiation)
# the same can be done from registryPrivateInstance() and even from registryClonedInstance() calls for completeness, although the latter is very obscure and should be avoided unless absolutely necessary
# the final object will get its registryAddedInstance() or registryPrivateInstance() / registryClonedInstance() call again (and can return another object again, but totally avoid the use of it)
# registryInstantiatedPrivate() / registryInstantiatedClone() will also be called on the final object returned after all the chain of registryAddedInstance() / registryPrivateInstance() / registryClonedInstance() completes
# this registry feature allows you to instantiate versions of classes by forking specific objects in a factory class depending on the arguments passed without overhead of encapsulating forked objects in factory objects

# also, registering a closure instead of class name allows you to create another type of late instantiatiation factory, the closure is expected to return final instantiated object
# a sample of registering ObjectStore_RDBMS with a dynamically loaded RDBMS driver that in turn dynamically connects to a registered MySQLi instance
# MyRegistry::registerInstance('MyDatabase', '\\MySQLi', 'localhost', 'user', 'password', 'db')
# MyRegistry::registerInstance('MyRDBMS_Driver', function (...$args) { $args[0] = MyRegistry::getInstance($args[0]); return new \ATL\ObjectStore_RDBMS_Driver_MySQLi(...$args); }, 'MyDatabase', 'utf8')
# MyRegistry::registerInstance('MyStore_MyTable', function (...$args) { $args[0] = MyRegistry::getInstance($args[0]); return new \ATL\ObjectStore_RDBMS(...$args); }, 'MyRDBMS_Driver', 'MyTable');
# if we unwrap this, MyStore_MyTable instantiation will first get an instance named MyRDBMS_Driver from MyRegistry, and then instantiate ObjectStore_RDBMS with it, MyRDBMS_Driver will do the same for MyDatabase and ObjectStore_RDBMS_Driver_MySQLi

# if you do not want registry to autoregister your requested class instances with classname equal to instance name and no constructor arguments, set autoregister to false in your own child registry instance, although this is handy function
# it makes global class instances creation easier (you just request Registry and it creates one) at the cost of losing track of class registration sequences and then losing full control over what is registered/overridden and what is not
# if you want to prevent registering named instances on obtaining private instances when autoregister is enabled, set autoregisterSkipAddOnPrivateInstance to true, this looks obscure but can help prevent registering instances too early

interface IObjectRegistry
{
    public static function registerInstance($objectName, $className, ...$args);
    public static function registerInstanceObject($objectName, $object, $errorIfExists = true);
    public static function getInstance($objectName);
    public static function getPrivateInstance($objectName, $newArguments = null);
    public static function clonePrivateInstance($objectName);
    public static function removeInstance($objectName, $errorIfNotExists = true);
    public static function clearInstance($objectName, $errorIfNotExists = true, $errorIfNoObject = false);
    public static function checkIfInstanceHasObject($objectName, $errorIfNotExists = true, $errorIfNoObject = false);
    public static function getObjectsOfClass($class);
}

trait TObjectRegistry
{
    protected static $objects = [];
    protected static $objectClasses = [];
    protected static $objectArgs = [];

    protected static $autoregister = true;
    protected static $autoregisterSkipAddOnPrivateInstance = false;

    ########
    # general API

    public static function registerInstance($objectName, $className, ...$args)
    {
        if (isset(static::$objectClasses[$objectName]))
            throw new \ErrorException("Object `{$objectName}` is already registered with the object registry `".static::class."`");
        static::$objectClasses[$objectName] = $className;
        static::$objectArgs[$objectName] = $args;
    }

    public static function registerInstanceObject($objectName, $object, $errorIfExists = true)
    {
        if ($errorIfExists && isset(static::$objects[$objectName]))
            throw new \ErrorException("Object `{$objectName}` is already registered with the object registry `".static::class."`");
        static::$objects[$objectName] = $object;
    }

    public static function getInstance($objectName)
    {
        if (!isset(static::$objects[$objectName])) {
            if (!isset(static::$objectClasses[$objectName])) {
                if (static::$autoregister) {
                    static::registerInstance($objectName, $objectName);
                } else {
                    throw new \ErrorException("Object `{$objectName}` is not registered with the object registry `".static::class."`");
                }
            }
            $instance = $newInstance = (!(static::$objectClasses[$objectName] instanceof \Closure)) ?
                new static::$objectClasses[$objectName](...static::$objectArgs[$objectName])
                : static::$objectClasses[$objectName](...static::$objectArgs[$objectName]);
            while (
                method_exists($newInstance, 'registryPrivateInstance') &&
                (($newInstance = $newInstance->registryPrivateInstance(static::class, $objectName, static::$objects[$objectName] ?? null, ...static::$objectArgs[$objectName])) !== null)
            ) {
                $instance = $newInstance;
            }
            static::$objects[$objectName] = $instance;
        }
        return static::$objects[$objectName];
    }

    public static function getPrivateInstance($objectName, $newArguments = null)
    {
        if (!isset(static::$objectClasses[$objectName])) {
            if (!isset(static::$objects[$objectName])) {
                if (static::$autoregister) {
                    if (!static::$autoregisterSkipAddOnPrivateInstance)
                        static::registerInstance($objectName, $objectName);
                    $instance = $newInstance = new $objectName();
                } else {
                    throw new \ErrorException("Object `{$objectName}` is not registered with the object registry `".static::class."`");
                }
            } else {
                throw new \ErrorException("Object `{$objectName}` is directly registered with the object registry `".static::class."` and cannot be duplicated, use clonePrivateInstance() for cloning");
            }
        } else {
            # create private instance
            $instance = $newInstance = (!(static::$objectClasses[$objectName] instanceof \Closure)) ?
                new static::$objectClasses[$objectName](...($newArguments ?? static::$objectArgs[$objectName]))
                : static::$objectClasses[$objectName](...($newArguments ?? static::$objectArgs[$objectName]));
        }
        while (
            method_exists($newInstance, 'registryPrivateInstance') &&
            (($newInstance = $newInstance->registryPrivateInstance(static::class, $objectName, static::$objects[$objectName] ?? null, ...($newArguments ?? static::$objectArgs[$objectName] ?? []))) !== null)
        ) {
            $instance = $newInstance;
        }
        if (isset(static::$objects[$objectName]) && method_exists(static::$objects[$objectName], 'registryInstantiatedPrivate'))
            (static::$objects[$objectName])->registryInstantiatedPrivate(static::class, $objectName, $instance, ...($newArguments ?? static::$objectArgs[$objectName] ?? []));
        return $instance;
    }

    public static function clonePrivateInstance($objectName)
    {
        $instance = $newInstance = clone $this->getInstance($objectName); # clone current instance, potentially creating the first object to be cloned
        while (
            method_exists($newInstance, 'registryClonedInstance') &&
            (($newInstance = $newInstance->registryClonedInstance(static::class, $objectName, static::$objects[$objectName], ...static::$objectArgs[$objectName])) !== null)
        ) {
            $instance = $newInstance;
        }
        if (isset(static::$objects[$objectName]) && method_exists(static::$objects[$objectName], 'registryInstantiatedClone'))
            (static::$objects[$objectName])->registryInstantiatedClone(static::class, $objectName, $instance, ...static::$objectArgs[$objectName]);
        return $instance;
    }

    public static function removeInstance($objectName, $errorIfNotExists = true)
    {
        if ($errorIfNotExists && !isset(static::$objects[$objectName]) && !isset(static::objectClasses[$objectName]))
            throw new \ErrorException("Object `{$objectName}` is not registered with the object registry `".static::class."`");
        if ((($instance = static::$objects[$objectName] ?? null) !== null) && method_exists($instance, 'registryRemovedInstance'))
                $instance->registryRemovedInstance(static::class, $objectName, ...static::$objectArgs[$objectName]);
        unset(static::$objects[$objectName], static::$objectClasses[$objectName], static::$objectArgs[$objectName]);
    }

    public static function clearInstance($objectName, $errorIfNotExists = true, $errorIfNoObject = false)
    {
        if ($errorIfNotExists && !isset(static::$objects[$objectName]) && !isset(static::objectClasses[$objectName]))
            throw new \ErrorException("Object `{$objectName}` is not registered with the object registry `".static::class."`");
        if ($errorIfNoObject && !isset(static::$objects[$objectName]))
            throw new \ErrorException("Object `{$objectName}` is not instantiated in the object registry `".static::class."`");
        if ((($instance = static::$objects[$objectName] ?? null) !== null) && method_exists($instance, 'registryRemovedInstance'))
            $instance->registryRemovedInstance(static::class, $objectName, ...static::$objectArgs[$objectName]);
        unset(static::$objects[$objectName]);
    }

    public static function checkIfInstanceHasObject($objectName, $errorIfNotExists = true, $errorIfNoObject = false)
    {
        if ($errorIfNotExists && !isset(static::$objects[$objectName]) && !isset(static::objectClasses[$objectName]))
            throw new \ErrorException("Object `{$objectName}` is not registered with the object registry `".static::class."`");
        if ($errorIfNoObject && !isset(static::$objects[$objectName]))
            throw new \ErrorException("Object `{$objectName}` is not instantiated in the object registry `".static::class."`");
        return isset(static::$objects[$objectName]);
    }

    # $class is generally checked using is_subclass_of, so you can pass parent classes, traits and interfaces as well
    public static function getObjectsOfClass($class)
    {
        return array_filter(static::$objects, function ($object) use ($class) {
            return is_subclass_of($object, $class, false);
        });
    }

    ########
    # internal and debug API: avoid using it in real code because it exposes the implementation

    public static function getAllObjects()
    {
        return static::$objects;
    }

    public static function getAllObjectsWithNotInstantiated()
    {
        $list = [];
        foreach (static::$objects as $objectName => $object)
            $list[$objectName] = $object;
        foreach (static::$objectClasses as $objectName => $class)
            $list[$objectName] = $list[$objectName] ?? null;
        return $list;
    }

    public static function getAllObjectsOfClass($class)
    {
        if ($class === null) return $this->getObjectsOfClass(null); # shortcut to the general version because directly registered objects cannot be non-instantiated
        return array_filter(static::getAllObjectsWithNotInstantiated(), function ($object) use ($class) {
            return (
                (($object !== null) && is_subclass_of($object, $class, false))
                || (($object === null) && is_subclass_of(static::$objectClasses[$objectName], $class, true))
            );
        });
    }

    public static function getAllDirectlyRegisteredObjects()
    {
        return array_diff_key(static::$objects, static::$objectClasses);
    }

    public static function getDirectlyRegisteredObjectsOfClass($class)
    {
        return array_filter(static::getAllDirectlyRegisteredObjects(), function ($object) use ($class) {
            return is_subclass_of($object, $class, false);
        });
    }

    public static function getAllObjectClasses()
    {
        return static::$objectClasses;
    }

    public static function getAllObjectArgs()
    {
        return static::$objectArgs;
    }
}

class ObjectRegistry implements \ATL\IObjectRegistry { use \ATL\TObjectRegistry; }
