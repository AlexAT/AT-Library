<?php

namespace ATL;

########
# this file is a collection of minor traits usable as bits in another components and your own code
# while it is common nowadays to put each minor class/trait in a separate file, ATL takes an approach reducing load/autoload overhead and number of files overall
# this is rock-solid ATLibrary policy and so subsystem traits are combined to their files and each minor usable common trait should be placed there

# As PHP does not allow us to override what comes from traits, use these traits and interfaces via extending your classes to override elements, i.e.
# class MyClassPrototype implements IEventHandlers { use TEventHandlers; } # add more interfaces/traits as desired
# class MyClass extends MyClassPrototype { } # place all your overrides there

# Event Handlers trait and interface
# allows to easily add user-manageable callback sets (event handlers) to classes, provides handlers set/remove/get methods and method to invoke specific handlers
# also provides an optimization path where you can convert handler IDs to integers on user calls and use these integers for invocation internally
# user handler can return false to stop event propagation (only explicit false value matters, returning null or true or any other value will be considered as 'allow propagation')
interface IEventHandlers
{
    public function addEventHandler($owner, $handler, $callback, $runHandler = true, $addLast = false);
    public function addEventHandlers($owner, $handlers, $runHandlers = true, $addLast = false);
    public function removeEventHandlers($owner, $handlerNames = null);
    public function getEventHandlers($owner = null, $handlerNames = null);
}

trait TEventHandlers
{
    protected $ehEventHandlers = [];

    protected $ehEventHandlerUseClosure = []; # place event handler closure optimization adjustment there: key is user event handler name
                                              #   true = convert callback to closure (default for all handlers)
                                              #   false = do not (use for rarely invoked handlers to spend less time adding them)

    # adds single event handler for invoker, possibly replacing already set handler, passing null as callback effectively removes handler
    # owner should be an object (object ID will be used for owner association) or worst case arbitrary text string (take care to not pass integers or spl_get_object_hash() like values)
    # normally, handler is added first to the invocation sequence (and can return false to stop propagation), but specifying addLast = true can be used to add handler last into sequence
    # normally, handlers are invoked on adding them if condition for their invocation matches, specify runHandler = false to prevent this behavior
    public function addEventHandler($owner, $handler, $callback, $runHandler = true, $addLast = false)
    {
        if (is_object($owner)) $owner = spl_object_id($owner);
        unset($this->ehEventHandlers[$handler][$owner]); # remove existing handler
        if ($callback !== null) {
            # add handler first
            if ($this->ehEventHandlerUseClosure[$handler] ?? true)
                $callback = \ATL\Routines::callableToClosure($callback, true);
            if (!isset($this->ehEventHandlers[$handler]) || $addLast) {
                # first time addition or adding last is optimized, so in case there is only one handler, it is all easy
                $this->ehEventHandlers[$handler][$owner] = $callback;
            } else {
                # handler list exists, and we need to add first, alas, PHP only gets us out with very slow method of addition
                # we should not have big callback sequences though, so this is tolerable for now I think
                $currentHandlers = $this->ehEventHandlers[$handler];
                $this->ehEventHandlers[$handler] = [$owner => $callback];
                $target = &$this->ehEventHandlers[$handler];
                foreach ($currentHandlers as $hOwner => $hCallback)
                    $target[$hOwner] = $hCallback;
            }
            $this->ehOnEventHandlerAdd($owner, $handler, $callback, $runHandler); # notify about new handler and possibly invoke handler for the first time
        }
    }

    # adds multiple event handlers per owner, possibly replacing already set handlers for the owner
    # handlers is an array of handler name => callback, passing null as callback effectively removes handler
    # otherwise similar to addEventHandler, see addEventHandler for other parameters meaning
    # take care handlers will be added/called in the order passed in the handlers array
    public function addEventHandlers($owner, $handlers, $runHandlers = true, $addLast = false)
    {
        if (is_object($owner)) $owner = spl_object_id($owner);
        foreach ($handlers as $handler => $callback)
            $this->addEventHandler($owner, $handler, $callback, $runHandlers, $addLast);
    }

    # removes event handlers for specific owner
    # normally it is all handlers but optionally can be used with list of handler names
    public function removeEventHandlers($owner, $handlerNames = null)
    {
        if (is_object($owner)) $owner = spl_object_id($owner);
        if ($handlerNames === null) {
            foreach ($this->ehEventHandlers as $handler => &$callbacks) {
                unset($callbacks[$owner]);
            } unset($callbacks);
        } else {
            $handlerNames = is_array($handlerNames) ? $handlerNames : [$handlerNames];
            foreach ($handlerNames as $handler)
                unset($this->ehEventHandlers[$handler][$owner]);
        }
    }

    # purely supplementary and has no good usage
    # by default, returns whole handler array (handler => invoker => callback), if handlerNames are specified, additionally filters resulting list by handler names
    # if owner is specified, returns handler => callback list for a specific owner, if handlerNames are specified, additionally filters resulting list by handler names and fills non-existent handler callbacks with null
    public function getEventHandlers($owner = null, $handlerNames = null)
    {
        if (is_object($owner)) $owner = spl_object_id($owner);
        if ($owner === null) {
            if ($handlerNames === null) {
                return $this->ehEventHandlers;
            } else {
                $handlerNames = is_array($handlerNames) ? $handlerNames : [$handlerNames];
                if (empty($handlerNames)) return [];
                return array_intersect_key($this->ehEventHandlers, array_combine($handlerNames, $handlerNames));
            }
        } else {
            $result = [];
            if ($handlerNames === null) {
                foreach ($this->ehEventHandlers as $handler => $callbacks)
                    if (isset($callbacks[$owner])) $result[$handler] = $callbacks[$owner];
            } else {
                $handlerNames = is_array($handlerNames) ? $handlerNames : [$handlerNames];
                if (empty($handlerNames)) return [];
                foreach ($handlerNames as $handler)
                    $result[$handler] = $this->ehEventHandlers[$handler][$owner] ?? null;
            }
            return $result;
        }
    }

    protected function ehInvokeEventHandlers($handler, ...$args)
    {
        foreach ($this->ehEventHandlers[$handler] ?? [] as $handler)
            if (($handler)(...$args) === false) break;
    }

    # addEventHandlers internally calls ehOnEventHandlerAdd($owner, $handler, $callback, $runHandler) when handlers are added
    # This is to perform additional actions on adding even handler, then optionally invoke event handler for the first time if runHandler is true and handler invocation condition is satisfied
    # Override in your class, otherwise handlers will never be called when added and runHandlers is true
    # handler is the same final event handler ID as in invokeEventHandler there
    protected function ehOnEventHandlerAdd($owner, $handler, $callback, $runHandler)
    {
        # switch ($handler) {
        #   case 'someHandler':
        #   if ($runHandler)
        #       if ($someCondition) ($callback)($this);
        #   break;
        #
        #   case 'someOtherHandler':
        #   if ($runHandler)
        #       if ($someOtherCondition) ($callback)($this, $someArg);
        #   break;
        # }
    }
}
