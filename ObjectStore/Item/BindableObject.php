<?php

########
# a combination of ObjectStoreItem and BindableObject, serving purpose of reducing event model boilerplate around it
# just extend the ObjectStoreItemBindableObject class when you need it, and override autoBindMyself() / autoUnbindMyself()

# this auto-binding model is intended to use in the following scenario: objects are loaded and attached to the their single store as normal
# on attachment to store, object finds other objects necessary and binds to them / loads other necessary store item objects that bind to the object
# when store item is unloaded (deleted or just removed from store), it unbinds itself from everything it was bound to / unbinds all bound objects
# that provides a consistent database-to-object-structure model that reflects your current loaded data state, preserving its binding integrity automatically

namespace ATL\ObjectStore\Item;

interface IBindableObject
{

}

trait TBindableObject
{
    ########
    # automatic binding/unbinding API, extend this to automatically bind and unbind your object when attached to store, changed or detached
    # on autoBindMyself() calls, you can safely rely on any current object properties
    # on autoUnbindMyself() calls, always use the current bindings you have and not the object properties, they may have been changed to this very moment

    protected function autoBindMyself() { }
    protected function autoUnbindMyself() { }

    ########
    # object store item events API support for automatic binding/unbinding

    protected function onAfterStore($objectStore) { $this->autoBindMyself(); }
    protected function onAfterDetach() { $this->autoUnbindMyself(); }
    protected function onBeforeReset() { $this->autoUnbindMyself(); }
    protected function onBeforeResetChanges() { $this->autoUnbindMyself(); }
    protected function onAfterResetChanges() { $this->autoBindMyself(); }
    protected function onAfterUnserializeAll() { $this->autoBindMyself(); }
}

class PBindableObject extends \ATL\ObjectStore\Item implements \ATL\IBindableObject { use \ATL\TBindableObject; }
class BindableObject extends \ATL\ObjectStore\Item\PBindableObject implements \ATL\ObjectStore\Item\IBindableObject { use \ATL\ObjectStore\Item\TBindableObject; }
