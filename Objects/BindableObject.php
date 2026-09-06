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

interface IBindableObject
{
    const BO_BINDING_TYPE = 0; # binding type (see below)
    const BO_BINDING_TYPE_SINGLE = 0; # the binding is property linked to an object
    const BO_BINDING_TYPE_INDEXED = 1; # the binding is property with array of indexes(string) each linked to an object
    const BO_BINDING_CLASS = 1; # class name that is allowed to bind for bindingDescriptors
    const BO_BINDING_CALLBACK = 2; # for bindTo/unbindFrom, method of ($binding, $object, $bind = true (bind)/false (unbind), $descriptor, $index = null)
                                # for bindObject/unbindObject, method of ($class, $object, $bind = true (bind)/false (unbind), $descriptor, $id)
                                # callbacks are called after actual binding / unbinding
    const BO_BINDING_ALLOW_REBIND = 3; # true to allow rebinding, by default rebinding is disallowed
    const BO_BINDING_SKIP_BO_BINDING_TO_TARGET = 4; # true to skip binding to / unbinding from targets by default

    const bindingDescriptors = []; # each descriptor is binding => [BO_BINDING_TYPE, BO_BINDING_CLASS, ?BO_BINDING_CALLBACK, ?BO_BINDING_ALLOW_REBIND]
    const bindingAccess = []; # each descriptor is class => [BO_BINDING_CLASS, ?BO_BINDING_CALLBACK]
    # for each bound object class, you need to create an array property where objects go in form of class(string) => [object_id(string) => object]
    # object IDs (unique per binding class) are obtained from getObjectID() method, which is \ATL\Routines\getCacheableObjectID() by default
    # duplicate object IDs are never ever allowed and will throw an exception if encountered
    # if you use interfaces or major parent classes in your bindingAccess descriptors, binding/unbinding to your object may have more overhead due to need to search by instanceof
    # also, list your more major interfaces or parent classes last (or at least before more precise matches of a kind) because the first match will be used to place object bound

    public function bindTo($binding, $object, $index = null, $skipBindingToTarget = false);
    public function unbindFrom($binding, $index = null, $noExceptionIfNotBound = false, $skipUnbindingFromTarget = false);
    public function bindObject($object);
    public function unbindObject($object);
    public function getBoundObject($bindClass, $id, $throwIfNotExist = false);
    public function listBoundObjects($bindClass);
    public function unbindFromAll();
    public function prepareToDestruct();
    public function getObjectID();
}

trait TBindableObject
{
    protected $bindableObjectId;

    public function bindTo($binding, $object, $index = null, $skipBindingToTarget = false)
    {
        if (!isset(static::bindingDescriptors[$binding])) throw new \ATL\BindableObjectBindingException("Binding to `{$binding}` failure: descriptor does not exist");
        $descriptor = static::bindingDescriptors[$binding];
        if (!($object instanceof $descriptor[static::BO_BINDING_CLASS])) throw new \ATL\BindableObjectBindingException("Binding to `{$binding}` failure: tried to bind unsupported `{".get_class($object)."}` object to `{$descriptor[static::BO_BINDING_CLASS]}` binding type");
        if (isset($descriptor[static::BO_BINDING_SKIP_BO_BINDING_TO_TARGET])) $skipBindingToTarget = $skipBindingToTarget || $descriptor[static::BO_BINDING_SKIP_BO_BINDING_TO_TARGET];
        switch ($descriptor[static::BO_BINDING_TYPE]) {
            case static::BO_BINDING_TYPE_INDEXED:
            if ($index === null) throw new \ATL\BindableObjectBindingException("Binding to `{$binding}` failure: tried to bind property itself while binding is indexed");
            if (!is_array($target = &$this->$binding)) throw new \ATL\BindableObjectBindingException("Binding to `{$binding}` failure: property for binding indexed objects is not an array");
            if (isset($target[$index])) {
                if (!isset($descriptor[static::BO_BINDING_ALLOW_REBIND]) || !$descriptor[static::BO_BINDING_ALLOW_REBIND]) throw new \ATL\BindableObjectBindingException("Binding to `{$binding}` index `{$index}` failure: already bound and direct rebinding is not allowed");
                $this->unbindFrom($binding, $index, false, $skipBindingToTarget);
            }
            $target[$index] = $object;
            break;

            case static::BO_BINDING_TYPE_SINGLE:
            if ($index !== null) throw new \ATL\BindableObjectBindingException("Binding to `{$binding}` failure: tried to bind indexed object while binding is single");
            if (($target = &$this->$binding) !== null) {
                if (!isset($descriptor[static::BO_BINDING_ALLOW_REBIND]) || !$descriptor[static::BO_BINDING_ALLOW_REBIND]) throw new \ATL\BindableObjectBindingException("Binding to `{$binding}` failure: already bound and direct rebinding is not allowed");
                $this->unbindFrom($binding, null, false, $skipBindingToTarget);
            }
            $target = $object;
            break;
        }

        if (isset($descriptor[static::BO_BINDING_CALLBACK])) {
            $method = $descriptor[static::BO_BINDING_CALLBACK];
            $this->$method($binding, $object, true, $descriptor, $index);
        }

        if (!$skipBindingToTarget && ($object instanceof IBindableObject)) $object->bindObject($this);
    }

    public function unbindFrom($binding, $index = null, $noExceptionIfNotBound = false, $skipUnbindingFromTarget = false)
    {
        if (!isset(static::bindingDescriptors[$binding])) throw new \ATL\BindableObjectUnbindingException("Unbinding from `{$binding}` failure: descriptor does not exist");
        $descriptor = static::bindingDescriptors[$binding];
        if (isset($descriptor[static::BO_BINDING_SKIP_BO_BINDING_TO_TARGET])) $skipUnbindingFromTarget = $skipUnbindingFromTarget || $descriptor[static::BO_BINDING_SKIP_BO_BINDING_TO_TARGET];
        switch ($descriptor[static::BO_BINDING_TYPE]) {
            case static::BO_BINDING_TYPE_INDEXED:
            if ($index === null) throw new \ATL\BindableObjectUnbindingException("Unbinding from `{$binding}` failure: tried to unbind property itself while binding is indexed");
            if (!is_array($target = &$this->$binding)) throw new \ATL\BindableObjectUnbindingException("Unbinding from `{$binding}` failure: property for binding indexed objects is not an array");
            if (!isset($target[$index]) && !$noExceptionIfNotBound) throw new \ATL\BindableObjectUnbindingException("Unbinding from `{$binding}` index `{$index} failure: no object bound");
            $object = $target[$index];
            unset($target[$index]);
            break;

            case static::BO_BINDING_TYPE_SINGLE:
            if ($index !== null) throw new \ATL\BindableObjectUnbindingException("Unbinding from `{$binding}` failure: tried to unbind indexed object while binding is single");
            if ((($target = &$this->$binding) === null) && !$noExceptionIfNotBound) throw new \ATL\BindableObjectUnbindingException("Unbinding from `{$binding}` failure: no object bound");
            $object = $target;
            $target = null;
            break;
        }

        if (isset($descriptor[static::BO_BINDING_CALLBACK])) {
            $method = $descriptor[static::BO_BINDING_CALLBACK];
            $this->$method($binding, $object, false, $descriptor, $index);
        }

        if (!$skipUnbindingFromTarget && ($object instanceof IBindableObject)) $object->unbindObject($this);
    }

    public function bindObject($object)
    {
        if (!isset(static::bindingAccess[$class = get_class($object)])) {
            # attempt slower inheritance and interface based binding
            $class = null;
            foreach (static::bindingAccess as $bindingClass => $descriptor) {
                if ($object instanceof $descriptor[static::BO_BINDING_CLASS]) {
                    $class = $bindingClass;
                    break;
                }
            }
            if ($class === null) throw new \ATL\BindableObjectBindingException("Incoming binding failure: binding `".get_class($object)."` objects to `".get_class($this)."` is not allowed");
        }
        $descriptor = static::bindingAccess[$class];
        $bindClass = $descriptor[static::BO_BINDING_CLASS];
        if (!is_array($target = &$this->$bindClass)) throw new \ATL\BindableObjectBindingException("Incoming binding failure: property for binding class `{$class}` does not exist or is not an array");

        $id = $object->getObjectID();
        if (isset($target[$id])) throw new \ATL\BindableObjectBindingException("Incoming binding failure: `{$bindClass}` object ID `{$id}` is already bound");
        $target[$id] = $object;

        if (isset($descriptor[static::BO_BINDING_CALLBACK])) {
            $method = $descriptor[static::BO_BINDING_CALLBACK];
            $this->$method($bindClass, $object, true, $descriptor, $id);
        }
    }

    public function unbindObject($object)
    {
        if (!isset(static::bindingAccess[$class = get_class($object)])) {
            # attempt slower inheritance and interface based unbinding
            $class = null;
            foreach (static::bindingAccess as $bindingClass => $descriptor) {
                if ($object instanceof $descriptor[static::BO_BINDING_CLASS]) {
                    $class = $bindingClass;
                    break;
                }
            }
            if ($class === null) throw new \ATL\BindableObjectUnbindingException("Incoming unbinding failure: unbinding `".get_class($object)."` objects from `".get_class($this)."` is not allowed");
        }
        $descriptor = static::bindingAccess[$class];
        $bindClass = $descriptor[static::BO_BINDING_CLASS];
        if (!is_array($target = &$this->$bindClass)) throw new \ATL\BindableObjectUnbindingException("Incoming unbinding failure: property for binding class `{$class}` does not exist or is not an array");

        $id = $object->getObjectID();
        if (!isset($target[$id])) throw new \ATL\BindableObjectUnbindingException("Incoming unbinding failure: `{$bindClass}` object ID `{$id}` is not bound");
        unset($target[$id]);

        if (isset($descriptor[static::BO_BINDING_CALLBACK])) {
            $method = $descriptor[static::BO_BINDING_CALLBACK];
            $this->$method($bindClass, $object, false, $descriptor, $id);
        }
    }

    public function getBoundObject($bindClass, $id, $throwIfNotExist = false)
    {
        if ($throwIfNotExist && !isset(($target = &$this->$bindClass)[$id])) throw new \ATL\BindableObjectObjectNotFoundException("Object retrieval failure: bound object `{$id}` of binding class `{$bindClass}` does not exist");
        return $target[$id] ?? null;
    }

    public function listBoundObjects($bindClass)
    {
        if (!is_array($target = &$this->$bindClass)) throw new \ATL\BindableObjectObjectNotFoundException("Object listing failure: property for binding class `{$bindClass}` does not exist or is not an array");
        return $target;
    }

    public function unbindFromAll()
    {
        foreach (static::bindingDescriptors as $binding => $descriptor) {
            switch ($descriptor[static::BO_BINDING_TYPE]) {
                case static::BO_BINDING_TYPE_INDEXED:
                if (is_array($target = &$this->$binding))
                    foreach ($target as $index => $object)
                        $this->unbindFrom($binding, $index, true);
                break;

                case static::BO_BINDING_TYPE_SINGLE:
                $this->unbindFrom($binding, null, true);
                break;
            }
        }
    }

    # take care that at the time of calling prepareToDestruct() object must be removed from all binding based indexes because all bindings become null after this call
    public function prepareToDestruct()
    {
        foreach (static::bindingAccess as $class => $descriptor) {
            $binding = $descriptor[static::BO_BINDING_CLASS];
            if (count($this->$binding) > 0)
                throw new \ATL\BindableObjectUnbindingException("Cannot destruct object that has other objects bound, found objects of class `{$descriptor[static::BO_BINDING_CLASS]}`");
        }
        $this->unbindFromAll();
    }

    # override this function to use distinct object IDs instead of default IDs in bindings and storages, take care so your IDs do not clash if cached
    public function getObjectID()
    {
        return $bindableObjectId ?? ($bindableObjectId = \ATL\Routines::getCacheableObjectID($this)); # cache ID to avoid calls to it on next binding
    }
}

class BindableObject implements \ATL\IBindableObject { use \ATL\TBindableObject; }

# Exception classes

class BindableObjectBindingException extends \Exception { }
class BindableObjectUnbindingException extends \Exception { }
class BindableObjectObjectNotFoundException extends \Exception { }
