<?php

namespace ATL;

# Task object

# This task object, despite being complex task loop handler, is designed to simplify any type task and subtask creation to one simple new object instantiation
# you can do new \ATL\Task([...closure,object,generator,fiber...], ...$parameters) for task or subtask creation
# just return created object if you want to provide your caller with a task to add to some task loop

# need a new quick Generator task? think about new \ATL\Task([$myClass, 'myGenerator'], ...$parameters) instead of extending object
# need a new quick Fiber task? think about new \ATL\Task(new \Fiber([$myClass, 'myFiber']), ...$parameters) instead of extending object
# need a new object that extends Task and can be run? think about main() Generator or fiber() fiber methods instead of doing weird things in constructor

# See TaskLoop for full task loop and objects implementation documentation

# Handy task pattern templates like Promise are also available in TaskClasses file

interface ITask
{
    # Some constants that may come handy as help, but you are discouraged to use non-text constants, use the boolean/numeric values directly, they are hardcoded for performance and are guaranteed not to change
    # For text constants, it is up to your preference, they are still hardcoded and guaranteed not to change, but you may prefer to use constant names instead
    const TASK_INTERVAL_MANUAL = false;
    const TASK_INTERVAL_REALTIME = -1;
    const TASK_INTERVAL_NEXTLOOP = 0;
    const TASK_INTERVAL_SECOND = 1;

    const TASK_YIELD_DEFAULT = null;
    const TASK_YIELD_MANUAL = false;
    const TASK_YIELD_REALTIME = true;
    const TASK_YIELD_SECOND = 1;
    const TASK_YIELD_SECOND_NOCOMP = -1;
    const TASK_YIELD_TERMINATE = 'terminate';
    const TASK_YIELD_TERMINATE_STACK = 'terminateStack';

    const TASK_PRECISE_TIMING_YES = 'yes';
    const TASK_PRECISE_TIMING_ONCE = 'once';
    const TASK_PRECISE_TIMING_NO = null;

    const TASK_EXCEPTION_DONTCARE = null; # means task does not handle exceptions, so if exception is not handled by some parent task, it will just be re-raised back to TaskLoop
    const TASK_EXCEPTION_ONCHILD_CONTINUE = true; # means task handles exceptions and also wants to continue if any child task got one propagated to it instead of re-raising exception
    const TASK_EXCEPTION_ONCHILD_TERMINATE = false; # means task handles exceptions and also wants to be terminated if any child task got one propagated to it instead of re-raising exception
    const TASK_EXCEPTION_TERMINATE_STACK = 'terminateStack'; # a special mode that request terminating whole task stack if this task gets an exception, same for any child task exception propagated
    const TASK_EXCEPTION_RAISE_DOWN = 'raise'; # means task does not handle exceptions, and wants any of its own or child task exception to be re-raised back to TaskLoop

    const taskAllowClosureAndObjectHandlers = false; # inherit and set to true to allow obscure Task variants like non-generator closures and objects with taskStart()/taskRun()/taskFinish() methods

    # Task control API

    public function taskAddTo(/** @var \ATL\TaskLoop */ $taskLoop, ...$parameters);
    /** @return \ATL\Task */ public function taskAddChildTask(/** @var \ATL\Task */ $task, ...$parameters);
    public function taskRunning();
    public function taskScheduled($throwIfNotExists = false);
    public function taskActive($throwIfNotExists = false);
    public function taskWaiting($throwIfNotExists = false);
    public function taskSchedule($throwIfNotExists = false);
    public function taskTerminate($throwIfNotExists = false);
    public function taskTerminateStack($throwIfNotExists = false);
    public function taskSetParameters(...$parameters);
    public function taskSetOptions($options);
    public function taskSetExceptionMode($mode, $override = true);
    public function taskAddOnTerminateHandler($handler, $handlerId = null, $throwIfNotExists = false);
    public function taskRemoveOnTerminateHandler($handlerId, $throwIfNotExists = false);
    public function taskBindTask(/** @var \ATL\Task */ $taskObject);
    public function taskBindTo(/** @var \ATL\Task */ $taskObject);
    public function taskUnbindTask(/** @var \ATL\Task */ $taskObject);

    # Default onStartup and onTerminate handlers
    # The main purpose of these are to provide your Task object with startup and termination handling even if the task handler is different from the object itself
    # Also, taskOnStartup is the only place where you can actually modify parameters on the fly before invoking task handler, possibly adding your own parameters (see Promise implementation for example)
    # onException is getting called under non-default exception handling modes, in case of 'terminateStack' you can return true from any tasks below one causing exception to stop terminating tasks and continue execution
    # exceptionTaskObject may be different from taskObject in onException, meaning some child task caused an exception

    public function taskOnStartup($taskObject, &$parameters);
    public function taskOnTerminate($taskObject); # ...$parameters
    public function taskOnException($taskObject, $exceptionTaskObject, $exception);

    # Self-handled Task API for when there is no handler (not allowed by default, set taskAllowClosureAndObjectHandlers to true to allow)

    public function taskStart($taskObject); # ...$parameters
    public function taskRun($interval);
    public function taskFinish($taskObject); # ...$parameters

    # Internal Task API
    # Never use this API directly in your code because this API depends on TaskLoop implementation and is subject to change anytime, using or overriding it will also break TaskLoop handling of the task

    public function tlConstructTask($handler = null, $parameters = null, $options = null);
    public function tlTaskSetOptions($options);
    public function tlTaskSchedule();
    public function tlTaskSetPrecise($precise);
    public function tlTaskAddOnTerminateHandler($handler, $handlerId = null);
    public function tlTaskRemoveOnTerminateHandler($handlerId, $throwIfNotExists = false);
    public function tlTaskAddToStack($previous);
    public function tlTaskStackNewTask(/** @var \ATL\Task */ $task);
    public function tlTaskPopFromStack();
    public function tlTaskGetPreviousStackedTask();
    public function tlTaskGetNextStackedTask();
    public function tlTaskGetTopmostTaskInStack();
    public function tlTaskGetBottommostTaskInStack();
    public function tlTaskStart($taskLoop);
    public function tlTaskStartHandler();
    public function tlTaskLoopCycle($interval);
    public function tlTaskEndStack($forcibly = true, $stopAtTask = null);
    public function tlTaskBoundTo(/** @var \ATL\Task */ $taskObject);
    public function tlTaskUnboundFrom(/** @var \ATL\Task */ $taskObject);
}

trait TTask
{
    public $taskId; # generated with \ATL\Routines\getUniqueObjectID() once on construction, used to uniquely identify the task object across all the application without repeating ID generation calls
    /** @var \ATL\TaskLoop */ public $taskLoop; # running task TaskLoop object reference
    /** @var \ATL\Task */ public $taskParameters; # running task parameters, can be changed during task execution
    public $taskResult; # task result returned by task termination, null if task does not support returning result or is terminated before it can return any result

    public $taskLastInterval; # last execution interval calculated, passed as yield/suspend result in case yield/suspend was scalar interval/mode value, we accumulate actual interval passing while task is waiting to be ran
    protected $tlTaskSubtaskResult; # last subtask execution taskResult, passed as yield/suspend result in case yield/suspend was a subtask, not intended to be used directly

    # some properties that do not explicitly require getters and setters are exposed (read-only) for performance reasons, DO NOT WRITE THEM DIRECTLY, writing them on the fly will produce undefined behavior
    # you may say exposing these violates a good principle of black box objects, BUT the overhead of method calls in PHP is TREMENDOUS, and TaskLoop is one place that is very overhead sensitive, so live with it
    public $tlTaskHandler; # original task handler passed on task creation
    public $tlTaskParameters = []; # initial task parameters passed to constructor are exposed, but do not use them if you do not know what are you doing
    public $tlTaskPreciseMode; # indicates the task is precise task (TASK_PRECISE_TIMING_YES) or it wants precise interval once (TASK_PRECISE_TIMING_ONCE), null otherwise
    public $tlTaskExceptionMode; # indicates task exception handling mode
                                 # null - propagate down the task stack if possible or re-raise down to TaskLoop if unhandled
                                 #   for Generator and Fiber parent task handlers this results in child task exceptions being injected into parent task last yield/suspend point in hope it will be handled
                                 # true - continue on child exceptions, false - terminate on child exceptions, not injecting exception into the Task and stopping exception propagation
                                 # 'terminateStack' - terminate whole stack, 'raise' - explicitly re-raise exception down to TaskLoop
    public $tlTaskInjectException; # exception to throw into next task execution loop, valid only for Generator and Fiber type tasks, part of exception propagation in 'null' mode

    # these properties cannot be exposed because they are not needed externally or volatile, and/or may require special handling
    protected $tlNextTaskRunInterval; # interval remainder for next task run, zero means run immediately
    protected $tlLastTaskLoopTime; # last run time for precise interval calculation, not kept for timed tasks
    protected $tlActiveTaskHandler; # active task handler than is actually getting called
    public $tlActiveTaskHandlerSpecial; # set to non-null (TASK_SPECIAL_HANDLER_*) on Generator and Fiber type handlers to indicate special handling, we need this public for exception re-throwing mechanism checks
    protected $tlActiveTaskFinishHandler; # set only for Object type tasks, contains task finish method
    protected $tlTaskOnTerminateHandlers = []; # list of handlers that would be called on task termination, as passed by options to the task constructor
    protected $tlActiveTaskOnTerminateHandlers = []; # active list of handlers that would be called on task termination, can be changed during task execution
    /** @var \ATL\Task */ protected $tlPreviousStackedTask; # previous task in stack if we have a task stack
    /** @var \ATL\Task */ protected $tlNextStackedTask; # next task in stack if we have a task stack
    /** @var \ATL\Task[] */ protected $tlBoundTasks = []; # bound tasks to be terminated along with us if we do
    /** @var \ATL\Task[] */ protected $tlBoundTo = []; # tasks this task is bound to (when we terminate, we unbind us from tasks we are bound to)

    # Task object construction

    # default constructor
    public function __construct($handler = null, ...$parameters)
    {
        $this->tlConstructTask($handler, $parameters); # call real hidden constructor
    }

    # this hidden constructor is used for task late initialization in places, so you may completely override task constructor if you need only default parameters and use main() or fiber()
    public function tlConstructTask($handler = null, $parameters = null, $options = null)
    {
        # initialize properties
        $this->taskId = \ATL\Routines::getUniqueObjectID($this);
        $this->taskParameters = $this->tlTaskParameters = $parameters ?? $this->tlTaskParameters;
        $this->tlTaskSetOptions($options);
        $this->taskResult = null;

        # convert handler from callable to closure if it is an array or string, and set it to current task handler
        if ($handler === $this) $handler = null; # prevent one mistake that can easily be made when creating self handled tasks
        if (is_array($handler) || is_string($handler)) $handler = \ATL\Routines::callableToClosure($handler, true);
        if ($handler === null) {
            # general easy way to create task objects is to just declare main() as Generator function or fiber() as Fiber function and it will be used as task handler
            if (method_exists($this, 'main')) {
                $handler = \ATL\Routines::callableToClosure([$this, 'main'], true);
                if (!(new \ReflectionFunction($handler))->isGenerator()) throw new \ATL\TaskException("Task main() routine must be a generator function");
            } elseif ((PHP_VERSION_ID >= 80100) && method_exists($this, 'fiber')) {
                $handler = new \Fiber([$this, 'fiber']);
            }
        }
        if (($handler !== null) && !is_object($handler) && !is_callable($handler)) throw new \ATL\TaskException("Attempted to create task with unsupported handler type");
        $this->tlTaskHandler = $handler;
    }

    # Default onTerminate handler

    public function taskOnStartup($taskObject, &$parameters) { } # this one does nothing by default but it is very handy to provide initialization when using separate handlers
    public function taskOnTerminate($taskObject) { } # ...$parameters, this one does nothing by default but it is very handy to provide finalization when using separate handlers

    # Default onException handler

    public function taskOnException($taskObject, $exceptionTaskObject, $exception) { return $this->tlTaskExceptionMode; } # returns default exception mode set as result for any exception

    # Self-handled task API for self-handled object-type task

    public function taskStart($taskObject) { } # ...$parameters, self-handled task start handler does nothing by default
    public function taskRun($interval) { throw new \ErrorException('Attempted to run Task without any defined handler'); } # self-handled task run handler throws exception by default to prevent coding errors
    public function taskFinish($taskObject) { } # ...$parameters, self-handled task finish handler does nothing by default

    # Task overrides that are handled by Task to provide per-task parameters for tasks and subtasks instead of user defined ones when replacing active task handler by ourselves
    # You can override these in your child Task objects or object-handled tasks to provide some defaults for your task, just the method presence incurs override

    # Task control API

    public function taskAddTo(/** @var \ATL\TaskLoop */ $taskLoop, ...$parameters)
    {
        return $taskLoop->addTask($this, ...$parameters);
    }

    /** @return \ATL\Task */ public function taskAddChildTask($task, ...$parameters)
    {
        if ($this->taskLoop === null) throw new \ATL\TaskLoopException("Tried to add child task while not being added to any task loop ourselves");
        /** @var \ATL\Task */ $task = $this->taskLoop->addTask($task, ...$parameters);
        $this->taskBindTask($task);
        return $task;
    }

    public function taskRunning()
    {
        return ($this->taskLoop !== null) ? $this->taskLoop->isTaskPresent($this) : false;
    }

    public function taskScheduled($throwIfNotExists = false)
    {
        if ($throwIfNotExists && ($this->taskLoop === null)) throw new \ATL\TaskLoopException("Tried to get scheduling status for task that is not added to any task loop");
        return ($this->taskLoop !== null) ? $this->taskLoop->isTaskScheduled($this, $throwIfNotExists) : false;
    }

    public function taskActive($throwIfNotExists = false)
    {
        if ($throwIfNotExists && ($this->taskLoop === null)) throw new \ATL\TaskLoopException("Tried to get active status for task that is not added to any task loop");
        return ($this->taskLoop !== null) ? $this->taskLoop->isTaskActive($this, $throwIfNotExists) : false;
    }

    public function taskWaiting($throwIfNotExists = false)
    {
        if ($throwIfNotExists && ($this->taskLoop === null)) throw new \ATL\TaskLoopException("Tried to get waiting status for task that is not added to any task loop");
        return ($this->taskLoop !== null) ? $this->taskLoop->isTaskWaiting($this, $throwIfNotExists) : false;
    }

    public function taskSchedule($throwIfNotExists = false)
    {
        if ($throwIfNotExists && ($this->taskLoop === null)) throw new \ATL\TaskLoopException("Tried to schedule task that is not added to any task loop");
        return ($this->taskLoop !== null) ? $this->taskLoop->scheduleTask($this, $throwIfNotExists) : false;
    }

    public function taskTerminate($throwIfNotExists = false)
    {
        if ($throwIfNotExists && ($this->taskLoop === null)) throw new \ATL\TaskLoopException("Tried to terminate task that is not added to any task loop");
        return ($this->taskLoop !== null) ? $this->taskLoop->terminateTask($this, $throwIfNotExists) : false;
    }

    public function taskTerminateStack($throwIfNotExists = false)
    {
        if ($throwIfNotExists && ($this->taskLoop === null)) throw new \ATL\TaskLoopException("Tried to terminate task stack for task that is not added to any task loop");
        return ($this->taskLoop !== null) ? $this->taskLoop->terminateTaskStack($this, $throwIfNotExists) : false;
    }

    public function taskSetParameters(...$parameters)
    {
        $this->taskParameters = $this->tlTaskParameters = $parameters;
    }

    public function taskSetOptions($options)
    {
        return ($this->taskLoop !== null) ? $this->taskLoop->setTaskOptions($this, $options, $throwIfNotExists) : $this->tlTaskSetOptions($options);
    }

    public function taskSetExceptionMode($mode, $override = true)
    {
        if ($override || ($this->tlTaskExceptionMode === null)) $this->tlTaskExceptionMode = $mode;
    }

    public function taskAddOnTerminateHandler($handler, $handlerId = null, $throwIfNotExists = false)
    {
        if ($throwIfNotExists && ($this->taskLoop === null)) throw new \ATL\TaskLoopException("Tried to add onTerminate handler to task that is not added to any task loop");
        return ($this->taskLoop !== null) ? $this->taskLoop->addTaskOnTerminateHandler($this, $handler, $handlerId, $throwIfNotExists) : $this->tlTaskAddOnTerminateHandler($handler, $handlerId);
    }

    public function taskRemoveOnTerminateHandler($handlerId, $throwIfNotExists = false)
    {
        if ($throwIfNotExists && ($this->taskLoop === null)) throw new \ATL\TaskLoopException("Tried to add onTerminate handler to task that is not added to any task loop");
        return ($this->taskLoop !== null) ? $this->taskLoop->removeTaskOnTerminateHandler($this, $handlerId, $throwIfNotExists) : $this->tlTaskRemoveOnTerminateHandler($handlerId, $throwIfNotExists);
    }

    public function taskBindTask(/** @var \ATL\Task */ $taskObject)
    {
        if ($taskObject->taskId === null) $taskObject->tlConstructTask(); # late initialization to allow easy creation of main() and fiber() type tasks
        if (isset($this->tlBoundTasks[$taskObject->taskId])) return false; # already bound
        $this->tlBoundTasks[$taskObject->taskId] = $taskObject;
        $taskObject->tlTaskBoundTo($this);
        return true;
    }

    public function taskBindTo(/** @var \ATL\Task */ $taskObject)
    {
        return $taskObject->taskBindTask($this);
    }

    public function taskUnbindTask(/** @var \ATL\Task */ $taskObject)
    {
        if ($taskObject->taskId === null) $taskObject->tlConstructTask(); # late initialization to allow easy creation of main() and fiber() type tasks
        if (!isset($this->tlBoundTasks[$taskObject->taskId])) return false; # not bound
        unset($this->tlBoundTasks[$taskObject->taskId]);
        $taskObject->tlTaskUnboundFrom($this);
        return true;
    }

    # Internal Task API
    # Never use this API directly in your code because this API depends on TaskLoop implementation and is subject to change anytime, using or overriding it will also break TaskLoop handling of the task

    public function tlTaskSetOptions($options)
    {
        if ($options === null) return;
        if (!is_array($options)) throw new \ATL\TaskException("Task options must be an array");
        if (isset($options['precise'])) $this->tlTaskSetPrecise($options['precise']);
        if (isset($options['onTerminate']) && is_array($options['onTerminate']))
            foreach ($options['onTerminate'] as $handlerId => $handler)
                $this->tlTaskAddOnTerminateHandler($handler, $handlerId);
        if (isset($options['onException'])) $this->tlTaskExceptionMode = $options['onException'];
    }

    # this still exists even while clearly asking to be optimized off as non-default Task implementations may do some extra operations here
    public function tlTaskSchedule()
    {
        $this->tlNextTaskRunInterval = -1;
    }

    # this one must still be used as setter in TaskLoop despite direct taskPreciseMode exposure because taskPreciseMode has multiple variants
    public function tlTaskSetPrecise($precise)
    {
        $this->tlTaskPreciseMode = $precise ? 'yes' : (($this->tlTaskPreciseMode === 'once') ? 'once' : null);
    }

    # adds new onTerminate handler, replacing existing handler if it exists
    public function tlTaskAddOnTerminateHandler($handler, $handlerId = null)
    {
        if ($handlerId === null) {
            # generate new internal handler ID
            if ($this->taskId === null) $this->tlConstructTask(); # late initialization to allow easy creation of main() and fiber() type tasks
            do { $handlerId = $this->taskId.':'.mt_rand(0, PHP_INT_MAX); } while (isset($this->tlTaskOnTerminateHandlers[$handlerId]) || isset($this->tlActiveTaskOnTerminateHandlers[$handlerId]));
        }
        if (($handlerId !== null) && (isset($this->tlTaskOnTerminateHandlers[$handlerId]) || isset($this->tlActiveTaskOnTerminateHandlers[$handlerId])))
            throw new \ATL\TaskException("Attempted to add onTerminate handler with duplicate ID of `{$handlerId}`");
        $this->tlTaskOnTerminateHandlers[$handlerId] = \ATL\Routines::callableToClosure($handler, true);
        $this->tlActiveTaskOnTerminateHandlers[$handlerId] = \ATL\Routines::callableToClosure($handler, true);
        return $handlerId;
    }

    # removes specific onTerminate handler, does nothing if it does not exist
    public function tlTaskRemoveOnTerminateHandler($handlerId, $throwIfNotExists = false)
    {
        if ($throwIfNotExists && !isset($this->tlTaskOnTerminateHandlers[$handlerId])) throw new \ATL\TaskException("Tried to remove onTerminate handler `{$handlerId}` that does not exist");
        unset($this->tlTaskOnTerminateHandlers[$handlerId], $this->tlActiveTaskOnTerminateHandlers[$handlerId]);
    }

    # task stacking operations
    public function tlTaskAddToStack($previous)
    {
        if ($this->tlPreviousStackedTask !== null) throw new \ErrorException("Attempted to stack us on top of task stack, but we are already stacked on some task");
        $this->tlPreviousStackedTask = $previous;
    }

    public function tlTaskStackNewTask(/** @var \ATL\Task */ $task)
    {
        if ($this->tlNextStackedTask !== null) throw new \ErrorException("Attempted to stack another task object on top of us, but we already have something stacked");
        $task->tlTaskAddToStack($this);
        $this->tlNextStackedTask = $task;
        $this->taskLoop->tlDeactivateTaskObject($this);
        if ($this->tlTaskExceptionMode !== null) $task->taskSetExceptionMode(false, false); # nested tasks get their default exception mode set to propagation if we are handling task exceptions ourselves, but not overridden
        $this->taskLoop->addTask($task);
        $this->tlLastTaskLoopTime = hrtime(true); # record our own last execution time to return correct interval when we pop back
        return -1; # as we are called from loop cycle, inform scheduler to reexecute next loop without delay (just added task may need to be executed)
    }

    public function tlTaskPopFromStack()
    {
        if ($this->tlNextStackedTask === null) throw new \ErrorException("Attempted to pop us to the top of the task stack, but we do not have any task on top of us");
        $this->tlTaskSubtaskResult = new \ATL\ValueObject($this->tlNextStackedTask->taskResult); # we use ValueObject here because we can have anything as result value so need to differentiate between value and no value
        $this->tlNextStackedTask = null; # as we are popping up, we have no next stacked task anymore
        if ($this->tlTaskPreciseMode === null) $this->tlTaskPreciseMode = 'once'; # inform runner we need to calculate actual passing interval for the handler
        $this->tlNextTaskRunInterval = -1; # schedule us for realtime reexecution, we were waiting for stacked task and need to run now
        $this->taskLoop->tlActivateTaskObject($this);
    }

    public function tlTaskGetPreviousStackedTask()
    {
        return $this->tlPreviousStackedTask;
    }

    public function tlTaskGetNextStackedTask()
    {
        return $this->tlNextStackedTask;
    }

    public function tlTaskGetTopmostTaskInStack()
    {
        return ($this->tlNextStackedTask === null) ? $this : $this->tlNextStackedTask->tlTaskGetTopmostTaskInStack();
    }

    public function tlTaskGetBottommostTaskInStack()
    {
        return ($this->tlPreviousStackedTask === null) ? $this : $this->tlPreviousStackedTask->tlTaskGetBottommostTaskInStack();
    }

    public function tlTaskStart($taskLoop)
    {
        # this internal assertion adds the tiniest bit of seemingly unnecessary overhead, but is there to catch up possible implementation bugs that may be extremely difficult to trace otherwise, so do not even try to remove it
        if ($this->taskLoop !== null) throw new \ATL\TaskLoopException("Attempted to assign single task object to more than one task loop or start the task object twice");
        if ($this->taskId === null) $this->tlConstructTask(); # late initialization to allow easy creation of main() and fiber() type tasks
        $this->taskParameters = $this->tlTaskParameters; # restore original task parameters if we are started more than once
        $this->tlActiveTaskOnTerminateHandlers = $this->tlTaskOnTerminateHandlers; # restore original task termination handlers if we are started more than once
        $this->taskResult = null; # no task result is present at the moment, task is just starting
        $this->tlTaskSubtaskResult = null; # no subtask was executing, so no result held
        $this->tlTaskInjectException = null; # no exception injected yet

        # set handler to task start handler and execute the loop calling it
        $this->taskLoop = $taskLoop; # set our task loop
        $this->tlActiveTaskHandler = [$this, 'tlTaskStartHandler']; # set our start handler as active handler, it is called once so we do not bother making it closure
        $this->tlActiveTaskHandlerSpecial = null; # indicate our start handler is actually generator type handler
        $this->tlActiveTaskFinishHandler = null; # reset current task finish handler to have none
        $this->tlNextTaskRunInterval = -1; # indicate we are going to do realtime run for the task start handler
        $taskPreciseMode = $this->tlTaskPreciseMode; # save task precision mode as we do not want task start to run in precise mode
        $this->tlTaskPreciseMode = null; # ensure we will not be running in precise mode for the start run
        $this->taskLastInterval = 0; # clear task accumulated interval to ensure start interval is correct
        $result = $this->tlTaskLoopCycle(0); # perform a single cycle that will execute our own task start handler

        # record the start time and request realtime calculation once (if not in realtime calculation mode already)
        # why? because TaskLoop execution may actually be delayed, like if your task is subscheduled by large synchronous task, you surely do not want to get all the preceding main task execution interval for the first time
        $this->tlLastTaskLoopTime = hrtime(true);
        $this->tlTaskPreciseMode = $taskPreciseMode ?? 'once'; # restore and set to TASK_PRECISE_TIMING_ONCE if null

        # done
        return $result;
    }

    # main routine to start the task execution
    public function tlTaskStartHandler()
    {
        # perform the initialization depending on task handler type
        if ($this->tlTaskHandler === null) {
            # object based handlers are prone to work wrong when used improperly, disallow them
            if (!$this::taskAllowClosureAndObjectHandlers) throw new \ATL\TaskLoopException("Self-handled object type tasks cannot be used anymore, use Generator main() or Fiber fiber() methods instead (or modify taskAllowClosureAndObjectHandlers if really necessary)");

            # self-handled Task
            $this->tlActiveTaskHandler = \ATL\Routines::callableToClosure([$this, 'taskRun'], true); # set active task handler to our own internal taskRun handler
            $this->tlActiveTaskHandlerSpecial = null; # this task handler has no special handling
            $this->tlActiveTaskFinishHandler = \ATL\Routines::callableToClosure([$this, 'taskFinish'], true); # set active task finish handler to our own internal finish handler
            $this->taskOnStartup($this, $this->taskParameters); # call our own taskOnStartup handler, this is the only chance to adjust parameters on the fly
            $this->taskStart($this, ...$this->taskParameters);
            return true; # object handled tasks do not process taskStart result and just start executing loop instead
        } elseif (($this->tlTaskHandler instanceof \Closure) || is_array($this->tlTaskHandler) || is_string($this->tlTaskHandler)) {
            # closures (and callables) may be generator closures, we need to verify this and handle generator type closures (callables) differently, using generator itself and not the closure (callable) as task
            $reflection = new \ReflectionFunction($this->tlTaskHandler);
            if ($reflection->isGenerator()) {
                # yes, initialize task by calling closure, set generator task handler and execute generator up to the first yield
                $this->taskOnStartup($this, $this->taskParameters); # call our own taskOnStartup handler, this is the only chance to adjust parameters on the fly
                $activeGenerator = $this->tlActiveTaskHandler = ($this->tlTaskHandler)($this, ...$this->taskParameters); # we need to hold current generator in the local variable because local properties one can change during run
                $this->tlActiveTaskHandlerSpecial = 'Generator'; # indicate we are to use special handler from there
                if (!($activeGenerator instanceof \Generator)) throw new \ErrorException("Expected Generator from generator type Closure, but received something else"); # internal assertion just in case we get something else (should not happen)
                if ($activeGenerator->valid()) return $activeGenerator->current(); # Generator started
                # we need to terminate the task being started if Generator is terminated just during start
                $this->taskResult = $activeGenerator->getReturn(); # retrieve result immediately if Generator terminated right on start
                return 'terminate';
            } else {
                # closure handlers are prone to code hanging when occasionally used instead of Generator, disallow them
                if (!$this::taskAllowClosureAndObjectHandlers) throw new \ATL\TaskLoopException("Simple closures cannot be used as task handlers anymore, use generator function instead (or modify taskAllowClosureAndObjectHandlers if really necessary)");

                # no, continue with closure handler, initialize by calling with null interval and additional parameters
                $this->tlActiveTaskHandler = $this->tlTaskHandler; # use closure as is
                $this->tlActiveTaskHandlerSpecial = null; # this task handler has no special handling
                $this->taskOnStartup($this, $this->taskParameters); # call our own taskOnStartup handler, this is the only chance to adjust parameters on the fly
                return ($this->tlActiveTaskHandler)(null, $this, ...$this->taskParameters);
            }
        } elseif ((PHP_VERSION_ID >= 80100) && ($this->tlTaskHandler instanceof \Fiber)) {
            # Fiber task handlers are initialized and the active handler is set to internal Fiber handler due to need of termination check
            $activeFiber = $this->tlActiveTaskHandler = $this->tlTaskHandler;
            $this->tlActiveTaskHandlerSpecial = 'Fiber'; # indicate we are to use special handler from there
            $this->taskOnStartup($this, $this->taskParameters); # call our own taskOnStartup handler, this is the only chance to adjust parameters on the fly
            $result = $activeFiber->isStarted() ? null : $activeFiber->start($this, ...$this->taskParameters);
            if (!$activeFiber->isTerminated()) return $result; # Fiber started
            # we need to terminate the task being started if Fiber is terminated just during start
            $this->taskResult = $activeFiber->getReturn(); # retrieve result immediately if Fiber terminated right on start
            return 'terminate';
        } elseif ($this->tlTaskHandler instanceof \Generator) {
            # pure generator handlers are executed up to first yield on startup (this is the worst task type to use, also a type that cannot be started again, but well, exists for completeness)
            $activeGenerator = $this->tlActiveTaskHandler = $this->tlTaskHandler; # we need to hold current generator in the local variable because local properties can change during run
            $this->tlActiveTaskHandlerSpecial = 'Generator'; # indicate we are to use special handler from there
            $this->taskOnStartup($this, $this->taskParameters); # call our own taskOnStartup handler, this is the only chance to adjust parameters on the fly
            if ($activeGenerator->valid()) return $activeGenerator->current(); # Generator started
            # we need to terminate the task being started if Generator is terminated just during start
            $this->taskResult = $activeGenerator->getReturn(); # retrieve result immediately if Generator terminated right on start
            return 'terminate';
        } elseif (is_object($this->tlTaskHandler)) {
            # object task handler must not be an ITask
            if ($this->tlTaskHandler instanceof ITask) throw new \ATL\TaskLoopException("Task objects cannot be used as task handlers, use self-handled Task objects instead");

            # object based handlers are prone to work wrong when used improperly, disallow them
            if (!$this::taskAllowClosureAndObjectHandlers) throw new \ATL\TaskLoopException("Method-handled object type tasks cannot be used anymore, use \\ATL\\Task objects with Generator main() or Fiber fiber() methods instead (or modify taskAllowClosureAndObjectHandlers if really necessary)");

            # object task handlers must have taskRun method
            if (!method_exists($this->tlTaskHandler, 'taskRun')) throw new \ATL\TaskLoopException("Attempted to create task from object without taskRun() method");
            $this->tlActiveTaskHandler = \ATL\Routines::callableToClosure([$this->tlTaskHandler, 'taskRun'], true); # set active handler to object taskRun method
            $this->tlActiveTaskHandlerSpecial = null; # this task handler has no special handling
            # set active task finish handler to object finish handler if it exists
            if (method_exists($this->tlTaskHandler, 'taskFinish')) $this->tlActiveTaskFinishHandler = \ATL\Routines::callableToClosure([$this->tlTaskHandler, 'taskFinish'], true);
            # call our own taskOnStartup handler, this is the only chance to adjust parameters on the fly
            $this->taskOnStartup($this, $this->taskParameters);
            # call taskStart method if it exists, immediately execute task run method on the next loop otherwise
            return method_exists($this->tlTaskHandler, 'taskStart') ? $this->tlTaskHandler->taskStart($this, ...$this->taskParameters) : true;
        } else {
            # unknown task handler type
            throw new \ErrorException("Attempted to start task with unsupported handler type");
        }
    }

    # main routine that runs in TaskLoop main loop and executes the task as scheduled
    public function tlTaskLoopCycle($interval)
    {
        # DEBUG: this internal assertion adds a bit of seemingly unnecessary overhead, so is commented but is there to catch up possible implementation bugs that may be extremely difficult to trace otherwise, uncomment if debugging
        #if ($this->taskLoop === null) throw new \ErrorException("Attempted to run task object not assigned to any task loop");

        # check if the task is manually scheduled
        if ($this->tlNextTaskRunInterval === null) return;

        # for precise tasks or when we need precise interval, calculate the real interval passed instead of using scheduler interval
        # take care: comparing with switch to string constants is much more effective in PHP than same switch to class constants
        if ($this->tlTaskPreciseMode !== null) {
            switch ($this->tlTaskPreciseMode) {
                case 'yes':
                $interval = max($this->taskLoop->taskLoopMinimumInterval, (($wakeupTime = hrtime(true)) - $this->tlLastTaskLoopTime) / 1000000000);
                $this->tlLastTaskLoopTime = $wakeupTime;
                break;

                case 'once':
                $interval = max($this->taskLoop->taskLoopMinimumInterval, (($wakeupTime = hrtime(true)) - $this->tlLastTaskLoopTime) / 1000000000);
                $this->tlLastTaskLoopTime = $wakeupTime;
                $this->tlTaskPreciseMode = null;
                break;
            }
        }

        # accumulate passed interval
        $this->taskLastInterval += $interval;

        # nextInterval can only be negative on entry if doing realtime or semi-realtime (minimum sleep) execution, we need to set nextInterval to zero if it is negative so compensation for precise tasks is not affected
        # if nextInterval is bigger than zero, just decrease by the interval and return if still so
        if ($this->tlNextTaskRunInterval < 0) {
            $this->tlNextTaskRunInterval = 0;
        } else {
            if (($this->tlNextTaskRunInterval = $this->tlNextTaskRunInterval - $interval) > 0)
                return $this->tlNextTaskRunInterval;
        }

        # execute active task handler
        try {
            # take care: comparing with switch to string constants is much more effective in PHP than same switch to class constants
            if ($this->tlActiveTaskHandlerSpecial !== null) {
                switch ($this->tlActiveTaskHandlerSpecial) {
                    case 'Generator':
                    # optimized special handler for Generator, we need to check for generator termination if the result is null
                    if ($this->tlTaskInjectException === null) {
                        if ((($result = $this->tlActiveTaskHandler->send(($this->tlTaskSubtaskResult === null) ? $this->taskLastInterval : $this->tlTaskSubtaskResult->value)) === null) && !$this->tlActiveTaskHandler->valid()) {
                            $this->taskResult = $this->tlActiveTaskHandler->getReturn(); # retrieve task result from Generator return
                            $result = 'terminate';
                        }
                    } else {
                        # inject exception, otherwise the same as normal send
                        if ((($result = $this->tlActiveTaskHandler->throw($this->tlTaskInjectException)) === null) && !$this->tlActiveTaskHandler->valid()) {
                            $this->taskResult = $this->tlActiveTaskHandler->getReturn(); # retrieve task result from Generator return
                            $result = 'terminate';
                        }
                        $this->tlTaskInjectException = null;
                    }
                    break;

                    case 'Fiber':
                    # optimized special handler for Fiber, we need to check for generator termination if the result is null
                    if ($this->tlTaskInjectException === null) {
                        if ((($result = $this->tlActiveTaskHandler->resume(($this->tlTaskSubtaskResult === null) ? $this->taskLastInterval : $this->tlTaskSubtaskResult->value)) === null) && $this->tlActiveTaskHandler->isTerminated()) {
                            $this->taskResult = $this->tlActiveTaskHandler->getReturn(); # retrieve task result from Fiber return
                            $result = 'terminate';
                        }
                    } else {
                        # inject exception, otherwise the same as normal send
                        if ((($result = $this->tlActiveTaskHandler->throw($this->tlTaskInjectException)) === null) && $this->tlActiveTaskHandler->isTerminated()) {
                            $this->taskResult = $this->tlActiveTaskHandler->getReturn(); # retrieve task result from Fiber return
                            $result = 'terminate';
                        }
                        $this->tlTaskInjectException = null;
                    }
                    break;
                }
            } else {
                $result = ($this->tlActiveTaskHandler)(($this->tlTaskSubtaskResult === null) ? $this->taskLastInterval : $this->tlTaskSubtaskResult->value);
            }
            $this->taskLastInterval = 0; # reset accumulated interval after task run
            $this->tlTaskSubtaskResult = null; # reset subtask result after task run
        } catch (\Exception $e) {
            # and here we go with task exception handling
            $this->taskLastInterval = 0; # reset accumulated interval after task run
            $this->tlTaskSubtaskResult = null; # reset subtask result after task run
            return $this->tlTaskLoopHandleException($e);
        }

        # handle the task execution result
        # the most typical results are null, then scalars, then different types of objects
        # for precise tasks, intervals are compensated by last run overshoot
        # for realtime tasks interval would always be negative, for manually scheduled tasks the interval is null

        # boolean true means realtime rescheduling and is most overhead prone, so we do it first
        if ($result === true) return $this->tlNextTaskRunInterval = -1;

        # nulls are most expected from timed and manually scheduled tasks, so handle them second
        if ($result === null) return $this->tlNextTaskRunInterval = 0; # your typical normal null result means we reschedule the task at its default interval

        # another very common case
        if ($result === 0) {
            if ($this->tlTaskPreciseMode !== 'yes') return $this->tlNextTaskRunInterval = (float) 0; # not precise task, just return the result
            return $this->tlNextTaskRunInterval = max(0, $this->tlNextTaskRunInterval); # otherwise, return the compensated result
        }

        # false means we are getting manually scheduled
        if ($result === false) return $this->tlTaskLoopBecomeManuallyScheduled();

        # any numeric result is direct interval setting
        if (is_numeric($result)) {
            # precise tasks may pass negative intervals indicating they want no compensation
            if ($result < 0) return $this->tlNextTaskRunInterval = -((float) $result); # negative intervals are okay for both types of tasks
            if ($this->tlTaskPreciseMode !== 'yes') return $this->tlNextTaskRunInterval = (float) $result; # not precise task, just return the result
            return $this->tlNextTaskRunInterval = max(0, $this->tlNextTaskRunInterval + (float) $result); # otherwise, return the compensated result
        }

        # handling of objects and arrays (we can also jump in there from above for special handling)
        if (is_object($result)) {
            # objects can be one of special result objects or new task handlers
            if ($result instanceof ITask) {
                # this is the new subtask object, insert it into the task stack and initialize it
                return $this->tlTaskStackNewTask($result);
            } else {
                # should be one of our typical handler objects
                $subTask = new $this($result, ...$this->taskParameters);
                $subTask->taskSetOptions(['precise' => ($this->tlTaskPreciseMode === 'yes')]);
                return $this->tlTaskStackNewTask($subTask);
            }
        } elseif (is_array($result)) {
            # should be a new handler callback
            $subTask = new $this($result, ...$this->taskParameters);
            $subTask->taskSetOptions(['precise' => ($this->tlTaskPreciseMode === 'yes')]);
            return $this->tlTaskStackNewTask($subTask);
        }

        # rare string-based manual scheduling and termination cases
        if (($result === 'wait') || ($result === 'manual')) return $this->tlTaskLoopBecomeManuallyScheduled();
        if (($result === 'terminate') || ($result === 'terminated')) return $this->tlTaskLoopTerminate();
        if ($result === 'terminateStack') {
            $this->taskLoop->terminateTaskStack($this, false, false); # forcibly is set to false to keep the task result
            return -1; # some tasks could be waiting for termination, so make scheduler do next loop immediately
        }

        # failure
        throw new \ATL\TaskLoopException("Task `{$this->taskId}` returned result of unexpected type");
    }

    protected function tlTaskLoopHandleException($exception)
    {
        # register task exception with the task loop so it can be logged or processed otherwise
        $this->taskLoop->onTaskException($this, $exception);

        # first, inform our own exception handler and handle few special occasions
        $mode = $this->taskOnException($this, $this, $exception);
        switch ($mode) {
            case 'terminateStack':
            $this->taskLoop->terminateTaskStack($this, false, true); # as this is exception handler, forcibly is set to true to discard the task result
            return -1; # scheduler needs kick to do the next loop now

            case 'raise':
            $this->taskLoop->taskWithException = $this; # set us as task with exception
            throw $exception;
        }

        # no special occasions, but having our own mode is good in case we cannot find any other task to handle exception
        $victimTask = $this;
        if ($mode !== null) $mode = false; # switch to termination mode by default for our own task (if not null)
        while (($victimTask = $victimTask->tlTaskGetPreviousStackedTask()) !== null) { # obtain previous task in stack
            $mode = $victimTask->taskOnException($victimTask, $this, $exception); # inform its exception handler and get victim task modus operandi
            if ($mode === null) {
                # victim Task dictates us to use normal exception handling path, check if it is handled by Generator or Fiber we can re-throw exception into
                switch ($victimTask->tlActiveTaskHandlerSpecial) {
                    case 'Generator':
                    case 'Fiber':
                    $this->taskLoop->terminateTaskStack($victimTask->tlTaskGetNextStackedTask(), false, true, true); # terminate victim child and everything above as we are going to re-throw the exception into victim task
                    $victimTask->tlTaskInjectException = $exception; # set exception to inject into task handler on next scheduling interval
                    return -1;
                }
            } elseif ($mode === true) {
                # we have found us a task willing to continue, terminate its children and inform scheduler we need further execution
                $this->taskLoop->terminateTaskStack($victimTask->tlTaskGetNextStackedTask(), false, true, true);
                return -1;
            } elseif ($mode === false) {
                # this task informs us it wants to terminate on exceptions ending exception propagation, terminate it with children and inform scheduler we need further execution
                $this->taskLoop->terminateTaskStack($victimTask, false, true, true);
                return -1;
            } elseif (is_string($mode)) {
                switch ($mode) {
                    case 'terminateStack':
                    $this->taskLoop->terminateTaskStack($this, false, true); # as this is exception handler, forcibly is set to true to discard the task result
                    return -1; # scheduler needs kick to do the next loop now

                    case 'raise':
                    $this->taskLoop->taskWithException = $this; # set us as task with exception
                    throw $exception;
                }
            }
        }

        # nobody cared about the exception in the end, so get it down to the TaskLoop
        $this->taskLoop->taskWithException = $this; # set us as task with exception
        throw $exception;
    }

    protected function tlTaskLoopBecomeManuallyScheduled()
    {
        if ($this->tlTaskPreciseMode === null) {
            # remember last loop time and request exact interval when task starts to wait for scheduling, but do not do it second time for precise tasks
            $this->tlLastTaskLoopTime = hrtime(true);
            $this->tlTaskPreciseMode = 'once';
        }
        $this->taskLoop->descheduleTask($this);
        return $this->tlNextTaskRunInterval = null;
    }

    protected function tlTaskLoopTerminate()
    {
        # we are requested to terminate the current task, but we may actually pop back the task stack
        if ($this->tlPreviousStackedTask === null) {
            $this->taskLoop->terminateTaskStack($this, false, false); # terminate our task stack via TaskLoop (we are the single task in the stack), forcibly is set to false to keep the task result
        } else {
            # finish this task, call its finisher, and pop back the previous task from the stack
            if ($this->taskLoop !== null) $this->tlTaskFinish(); # the check is necessary because calling termination handlers could have resulted in our task termination before we hit there
            if ($this->tlPreviousStackedTask !== null) { # this check is necessary because calling termination handlers could have resulted in our parent task termination
                $this->tlPreviousStackedTask->tlTaskPopFromStack();
                $this->tlPreviousStackedTask = null;
            }
        }
        return -1; # inform scheduler to reexecute next loop without delay (just popped task was waiting for us and needs to be executed)
    }

    # main routine that runs when task finishes execution (is terminated)
    protected function tlTaskFinish($forcibly = false)
    {
        if ($this->taskLoop === null) throw new \ATL\TaskLoopException("Attempted to finish task that does not belong to any task loop");

        # call finish handler (are possible only with object type handlers) if it exists
        if (!$forcibly) {
            # call the task finish handler if task is not terminated forcibly
            if ($this->tlActiveTaskFinishHandler !== null)
                $taskResult = ($this->tlActiveTaskFinishHandler)($this, ...$this->taskParameters); # also retrieve the task result from task finish handler
        } else {
            # if the task is terminated forcibly, destroy task result so we do not pass it back
            $taskResult = null;
        }

        # if we were bound to some other tasks, remove bindings so we are not caught in termination loop
        foreach ($this->tlBoundTo as $taskId => $taskObject)
            $taskObject->taskUnbindTask($this);

        # terminate all bound tasks if they are still running
        foreach ($this->tlBoundTasks as $taskId => $taskObject)
            $this->taskLoop->terminateTask($taskObject, false, $forcibly);

        # remove ourselves from the task loop, needs to be done before termination handlers because otherwise we may be double-terminated
        $this->taskLoop->tlDeactivateTaskObject($this);
        $this->taskLoop->tlRemoveTaskObject($this);

        # destroy task loop reference
        $this->taskLoop = null;

        # run onTerminate handlers (ourselves first, everyone else later)
        $this->taskOnTerminate($this, ...$this->taskParameters);
        foreach ($this->tlActiveTaskOnTerminateHandlers as $handler) $handler($this, ...$this->taskParameters);
    }

    # this one is called from TaskLoop when whole task stack needs to be finished
    # can stop at specific task, causing only task and all its subtasks termination
    public function tlTaskEndStack($forcibly = true, $stopAtTask = null)
    {
        if ($this->tlNextStackedTask !== null) throw new \ErrorException("Attempted to terminate task stack not from the topmost task in the stack");
        if ($this->taskLoop !== null) $this->tlTaskFinish($forcibly); # taskLoop presence check is necessary because calling termination handlers may result in more tasks termination
        if ($this->tlPreviousStackedTask !== null) {
            # pop previous task
            $this->tlPreviousStackedTask->tlTaskPopFromStack();
            if ($stopAtTask !== $this) $this->tlPreviousStackedTask->tlTaskEndStack($forcibly, $stopAtTask); # continue finishing
            $this->tlPreviousStackedTask = null;
        }
    }

    public function tlTaskBoundTo(/** @var \ATL\Task */ $taskObject)
    {
        $this->tlBoundTo[$taskObject->taskId] = $taskObject;
    }

    public function tlTaskUnboundFrom(/** @var \ATL\Task */ $taskObject)
    {
        unset($this->tlBoundTo[$taskObject->taskId]);
    }
}

class Task implements \ATL\ITask { use \ATL\TTask; }

# Simple task wrapper for tasks written using main() or fiber() that just passes all constructor arguments down to the main() or fiber() after $taskObject

class SimpleTask extends Task
{
    public function __construct(...$args)
    {
        parent::__construct(null, ...$args);
    }
}

# Exception classes

class TaskException extends \Exception { }
