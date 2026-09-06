<?php

namespace ATL;

# General cooperative scheduling task loop (semi-asynchronous task loop that can be used as main timer loop, event loop, coroutine loop, to implement promises, whatever)
# Best served with Fiber of PHP 8.1 where you can relinquish control to other tasks from anywhere in your code and get control again there once scheduled (by event, by time, or just after other tasks had a chance to execute)
# In lack of Fiber in PHP 8.0 or in tight places where loop performance is critical, can be stuffed with Generator type tasks, they do not allow 'anywhere' as Fiber, but the main task function can relinquish control at will
# To ease usage of Generator type handlers, easy invocation of subtasks that execute and return is possible, with function-like argument passing and result retrieval, like $result = yield new \ATL\Task($handler, $arg1, ..., $argN);
# Supports Generator and Fiber (init/execute/return) type task implementations (handlers) with high invocation performance (Fiber is 30% slower, but it still allows millions of loops per second on single modern CPU core)
# For easier Task type objects creation, inherited Task objects can place default task implementation in either main() method that must be a Generator, or fiber() method that will be converted to Fiber, to be used automatically
# Object (start/run/stop methods) and Closure (single method for all) task handlers are supported via inheriting Task class and setting special constant to true, disabled otherwise to prevent possible misuse and common errors
# TaskLoop can be nested into other TaskLoop because it is Task based object itself, providing for nested task loops between subsystems, nested task loops will propagate their scheduling requirements to lower layers automatically

# Task options
#   'precise' - when set to true, task will always get precise passed interval since its own last run, also when running timed, if the task was ran with interval overshoot, the next interval will be adjusted down by the overshoot
#               when set to false, scheduling intervals are given by TaskLoop scheduler once per loop, so while intervals will be close to real task scheduling time if summed for the long run, they will not reflect precise rescheduling time

# Task scheduling
# Each task handler yields back (via yield for Generator or Fiber::suspend() for Fiber) its scheduling requirements when it relinquishes control
#   Yielding null or zero will continue task execution at the next possible scheduling interval, after all other tasks are scheduled and minimal sleeping interval expires, this is 'best effort' / 'polling' kind of cooperative scheduling
#   Yielding true will also reschedule task execution at the next possible scheduling interval and after all other tasks are scheduled, but will ensure no sleeping time for schedule, this is 'realtime' kind of cooperative scheduling
#   Yielding non-zero positive count of seconds (can be fractional) will continue task execution after at least given number of seconds passed (can be lower or higher, no precision guarantees), this is 'sleep' kind of cooperative scheduling
#   Yielding non-zero negative count of seconds (can be fractional) is the same as above, but will attempt to compensate for current cycle overshoot, reducing the passed value by time of overshoot from the previous interval
#   Yielding false will switch the task to manual scheduling, so task execution will not continue until taskSchedule() is invoked on the task, this is 'event triggered' / 'manually scheduled' kind of cooperative scheduling
# When task receives control back from scheduling type yield, it gets time interval in seconds passed in sleep as yield result (can be fractional), this interval can be used to decrease timeout counters, etc.
# This interval will be as close as possible to real time passed if summed down in a long run, so good for timeouts, but will not reflect exact rescheduling time and has no precision guarantees unless 'precise' task option is set to true

# Manually scheduling tasks
# taskSchedule() can be invoked on Task object at any moment to make task stop the waiting and be scheduled at the next task loop
# While mostly usable with manually scheduled (event triggered) task scheduling to trigger task by external (other task) events, taskSchedule() will also stop timed task sleeps and schedule task to be executed on the next loop
# Usage of taskSchedule() with timed waiting is not exactly recommended as it can cause obscure behavior, but can be used for i.e. cases where polling is combined with event triggering, i.e. to prevent infinite waits on event loss

# Nesting tasks
# Task handler can yield back a new Task or task handler callable instead of scheduling requirements to run a subtask
# In this case, a new Task / task handler yielded will start executing, and execution will only return to yelding task after yielded subtask terminates
# Yield result will be set to subtask execution result (see Returning results section below). Rescheduling interval, if needed for measurement, is available in taskObject taskLastInterval property
# Nested tasks can nest further subtasks of theirs, there is no limit on the nesting level as Task is intended to be used in a way similar to function call for asynchronous Task functions
# Do not overuse Task nesting though as it has a very high overhead, so is not good to be used in tight loops or for small code parts that do not take much time to execute, always prefer code copying over minor subtasks
# It is always better to use Fiber type handlers with suspending inside functions than to oversplit everything into Generators, but this way is of course only available with PHP 8.1 or higher

# Passing parameters (arguments) to tasks
# \ATL\Task constructor accepts any number of arguments after the mandatory task handler argument
# All extra arguments will be passed to constructed task handler Generator or Fiber function (and to other task handler types if applicable) after taskObject argument and will also be supplied to task onTerminate handlers when invoked
# This way task can be invoked like functions, and combined with easy result retrieval (see Returning results) below this makes Task objects totally usable for invoking asynchronously executed functions from your Task handler code
# Asynchronously executed in this context means subtask Task 'function' will remain synchronous for the invoking Task, but will execute asynchronously with other Tasks, being a Task by itself, and return to calling Task only after completion

# Returning results
# When task handler ends execution (be it Generator or Fiber), it can return a value. This value will be stored in Task object taskResult property, and will also be available to parent task (if any) via its subtask yield result
# So in general, you can invoke your subtask methods like functions. Example:
#   $result = yield new \ATL\Task([$this, 'mySubTaskMethod'], $arg1, $arg2);
# $this->mySubTaskMethod($taskObject, $arg1, $arg2) Generator type handler will be invoked as Task, and then when it finishes and returns some value, this value will be available as $result in your calling Task

# Task handlers
# Generator type Closure or Callable array towards Generator function
#   The Generator type function will be invoked at Task start with taskObject argument for Task object responsible and any other Task arguments passed afterwards
#   To relinquish control and pass down scheduling requirements and/or subtasks, yield statements are to be used
#   Yield will return control back to the Generator when the task is next scheduled for execution
#   Yield results will contain either scheduling interval or subtask result, depending on yield argument
#   Terminating the Generator with return statement terminates the task, places return value to $taskObject->taskResult property, and returns this value to parent task (if any) as its yield result
# Fiber (being PHP 8.1+ only and having a little performance drawback, it is the best task handler type to use in application code if PHP 8.1+ only requirement is tolerable)
#   Fiber will be started at Task start with taskObject argument for Task object responsible and any other Task arguments passed afterwards
#   To relinquish (yield) control and pass down scheduling requirements and/or subtasks, Fiber::suspend() method is to be used
#   Fiber::suspend() will return control back to the Fiber (resume Fiber) when the task is next scheduled for execution
#   Fiber::suspend() results will contain either scheduling interval or subtask result, depending on Fiber::suspend() argument
#   Terminating the Fiber with return statement terminates the task, places return value to $taskObject->taskResult property, and returns this value to parent task (if any) as its yield result

# Supplementary and not recommended task handler types, disabled by default, can only be enabled by inheriting Task class and overriding taskAllowClosureAndObjectHandlers constant to true
# Object
#   Provides a simple task object that will handle the task.
#   taskStart($taskObject, ...$parameters) method will be called when task object is added to the task loop, parameters are task parameters set on task add, see task return values for what to return
#   taskRun($interval) method will be called for each task loop run, interval is floating-point interval in seconds since the last task run (or task start), see task return values for what to return
#   taskFinish($parameters) method will be run when task object is removed from the task loop, parameters are task parameters set on task add, nothing is expected to be returned
#   To create oneshot task, just return 'terminate' at the end of your taskRun() execution
# Closure, Callable array (not Generator type)
#   Provides a simple task routine equivalent to taskRun() of task object that will be ran once at add with null as interval and then taskStart() parameters, then ran with proper intervals, see task return values for what to return
#   Callables will be internally converted to closures on addition, this does not affect task operation, just an implementation fact worth mentioning
#   If you pass a closure/callable that is a generator closure/callable and returns generator, this callable will be re-called with zero interval and all other taskStart() parameters when generator completes, keep this in mind
#   To create oneshot tasks, return 'terminate' at the end of your execution
# Generator object
#   Will be executed up to first yield on add using valid() and then only sent with proper intervals as value, relinquishing control with scheduling requirements and subtasks is done by yield statements
#   If the generator terminates, the task terminates (execution possibly continues with the parent task), the result returned by return statement will be stored in taskResult and passed as yield result to parent task if any
#   While this handler type is not explicitly disabled, it is strongly recommended to avoid using Generator object as task handlers directly and to always pass generator closure/callable that acts as generator as handler
#   This is because pure generators can not take any Task object parameters (all parameters are only passed on Generator creation) and are also not restartable / reusable

########
# Performance
#
# TaskLoop is mission-critical task loop enabling asynchronous programming model that was designed with performance and overhead in mind, and had a thorough profiler guided optimization pass to optimize it where necessary
#
# Some performance figures:
# On PHP 8.x CLI with 'tracing' JIT enabled, single core of AMD EPYC 7402P (2.8G) processor virtualized under Xen, with 5 simple mixed type realtime tasks just incrementing counter, checking it and returning,
#   each task taking 2000000 loops before termination, TaskLoop is able to perform 8000000-9000000 task invocations per second for Generator type handlers and 4000000-5000000 task invocations per second for Fiber type handlers
#
# This means: TaskLoop is hellishly effective with PHP 8 and JIT, providing for millions of task invocations per second on a typical server CPU core, also keeping in mind real tasks are never going to be as simple as test one
# To put it differently, it is only ~300-350 CPU cycles in PHP 8.1 for Generator context switching, and only ~550 CPU cycles for Fiber 'context' switching, it is easy to forget we are doing PHP and not C or pure assembly there
#
# If these numbers are compared with direct for() loop for 2000000 cycles invoking task object methods unconditionally, we get approximately 50000000 task invocations per second, so TaskLoop cycle may roughly be evaluated
#   to be equivalent of ONLY around 5-6 (Generator handler) or 9-12 (Fiber handler) direct object method invocations (so all the TaskLoop/Task scheduling overhead is roughly equivalent to just a few nested $object->method() calls)
#
# TaskLoop absolutely favors JIT. With PHP 7.x or PHP 8.x with no JIT, numbers are TIMES lower in the number of invocations BOTH for TaskLoop and direct method calls, totaling to around 3000000 invocations per second
# Strict types optimization? Absolutely not. The performance is terrible. An attempt to do strict types resulted in around 15% drop just with enabling one for tlTaskLoopCycle(), messing with strict types more just adds more overhead
# PHP versions? 8.2 is definitely the best, 8.1/8.0 are comparable, 7.4/7.3 lack JIT so are heavily down in the dumps, and 7.2/7.1/7.0 group was the slowest. Bottom line? Use latest PHP where possible
#
# All in all, as noticed in real tasks with asynchronous socket code and calculations, TaskLoop overhead is not going to be noticeable, and is totally comparable with other means of making stuff run semi-asynchronously
# Actually, TaskLoop simplified code AND yielded better performance (less CPU usage, faster reaction / less latency) compared to manual subroutine scheduling/timing and callback hell for all the applications I converted to it
# I.e. each callback method call is still a method call, and so one can only safely chain up to ~10 of these without losing to TaskLoop in terms of efficiency :) Callback chaining tends to exhaust CPU usage budget really fast
#
# Bottom line, use Generator type handlers for rapidly scheduled tasks whenever and wherever possible, Generator type handlers are the most and extremely performant, although remember that nesting subtasks adds its own noticeable overhead
# For complex and heavily branching code, if having PHP 8.1, go with Fibers, they make life easier and code cleaner at a very little cost, reducing number of subtasks invoked and their creation overhead can easily win Fiber costs back

interface ITaskLoop
{
    # Public task loop API

    public function loopOnce($interval = null);
    public function loop();
    public function loopSleep($seconds);
    public function endLoop();
    public static function runLoop(/** @var \ATL\Task[] */ ...$tasks);
    public static function runTask(/** @var \ATL\Task */ $task);
    public static function createInfiniteLoop(/** @var \ATL\Task[] */ ...$tasks);

    # Task exception handler, can be used to log task exceptions

    public function onTaskException($taskObject, $exception);

    # Public task manipulation API

    public function addTask($handler, ...$parameters);
    public function isTaskPresent(/** @var \ATL\Task */ $taskObject);
    public function isTaskScheduled(/** @var \ATL\Task */ $taskObject, $throwIfNotExists = false);
    public function isTaskActive(/** @var \ATL\Task */ $taskObject, $throwIfNotExists = false);
    public function isTaskWaiting(/** @var \ATL\Task */ $taskObject, $throwIfNotExists = false);
    public function descheduleTask(/** @var \ATL\Task */ $taskObject, $throwIfNotExists = false);
    public function scheduleTask(/** @var \ATL\Task */ $taskObject, $throwIfNotExists = false);
    public function setTaskOptions(/** @var \ATL\Task */ $taskObject, $options, $throwIfNotExists = false);
    public function setTaskExceptionMode(/** @var \ATL\Task */ $taskObject, $mode, $override = true, $throwIfNotExists = false);
    public function addTaskOnTerminateHandler(/** @var \ATL\Task */ $taskObject, $handler, $handlerId = null, $throwIfNotExists = false);
    public function removeTaskOnTerminateHandler(/** @var \ATL\Task */ $taskObject, $handlerId, $throwIfNotExists = false);
    public function terminateTask(/** @var \ATL\Task */ $taskObject, $throwIfNotExists = false, $forcibly = true);
    public function terminateTaskStack(/** @var \ATL\Task */ $taskObject, $throwIfNotExists = false, $forcibly = true, $notBelow = false);
    public function terminateAllTasks($forcibly = true);

    # Internal API, never ever use directly

    public function tlActivateTaskObject(/** @var \ATL\Task */ $taskObject, $schedule = true);
    public function tlDeactivateTaskObject(/** @var \ATL\Task */ $taskObject);
    public function tlRemoveTaskObject(/** @var \ATL\Task */ $taskObject);
}

trait TTaskLoop
{
    # all scheduling parameters are exposed to be adjusted before loop execution and theoretically can also be adjusted at runtime, but avoid changing them after task loop is ran unless you surely know why you are doing that
    public $taskLoopMinimumInterval = 0.000001; # minimum interval that will be passed down to tasks if real interval is lower than this value (real value calculated can be zero or negative sometimes due to i.e. system time shifts)
    public $taskLoopMinimumSleep = 0.000001; # minimum sleep time in seconds (if task returns lower sleep value, it will be clamped to this one) before attempting to re-run task loop
    public $taskLoopMaximumSleep = 60; # maximum sleep time in seconds (if task returns higher sleep value, it will be clamped to this one) before attempting to re-run task loop

    # this one is by all means THE MOST vital parameter of the task loop
    # if we are the main loop and overall sleep time is higher, we sleep between loops in intervals of at least this amount of seconds before checking for any active (scheduled) tasks to appear
    # if we are some nested loop, when tasks end in zero interval and parent loop default sleep interval is higher, we substitute zero with this much to return to the parent loop
    # main loop also sleeps in this intervals when there are no realtime or immediately active tasks, so this value determines the time for the whole loop to wake up from sleeping state
    # decreasing this value increases loop polling frequency, increasing CPU usage for polling and loop overhead
    # increasing this value increases loop reaction time for task polling and wakeup, but decreases CPU usage and loop overhead
    # of course if some tasks request lower activation interval at some point, the loop will honor them and will not oversleep, so default interval only acts when the loop is really sleeping
    # the default value is set up for PHP 8 with JIT reducing overhead, and provides maximum reaction time of 25ms for zero interval polling tasks or for manually scheduled (null interval) tasks wakeup
    # that should be enough for most networking and background tasks and produces negligible CPU usage when sleeping, reduce only if you surely need faster reaction times on rare wakeups
    # for PHP <8.0, it is recommended to increase this valu to 0.5 (50ms reaction time) because TaskLoop overhead is slightly higher under these PHP versions
    public $taskLoopDefaultSleep = 0.02;

    public $taskLoopTaskExceptionTerminateByDefault = false; # if set to true, sets any added task with exception mode set to not caring (null) to propagation mode (false) instead, but does not override other modes
                                                             # you most probably want this to be set to true in real world servers, to stop whole server aborting on non-caring task exceptions and log the exceptions instead
                                                             # by default, the task exception mode is null, and so exceptions get to the TaskLoop itself (it does not handle any), so task loop aborts and exception goes further down
    public $onTaskExceptionHandler; # if you do not want to create TaskLoop derivative to just log exceptions, you may set this handler to onTaskException like callback, it will be ran by default onTaskException handler
    public $taskWithException; # on unhandled (propagated) task exceptions, will contain task that got an exception, will be reset on loop restart, you can use it to handle task exceptions at top level before TaskLoop and continue loop

    /** @var \ATL\Task */ public $taskLoopActiveTaskObject; # is set to active task object during task execution, can be utilized for some good but obscure purposes externally :)
    public $taskLoopScheduled = false; # used to indicate we already scheduled ourselves and do not need to do it again until we get control, do not write, external loops can use it to check if it needs to execute
    public $taskLoopTerminateOnNoTasks = true; # can be set to false (or use TaskLoopInfinite alias) to prevent TaskLoop from terminating when it has no tasks anymore

    # these are still kept public so you can enumerate tasks and stuff, but the usage is highly discouraged because it exposes implementation details
    /** @var \ATL\Task[] */ public $taskLoopTasks = []; # all tasks added to the TaskLoop
    /** @var \ATL\Task[] */ public $taskLoopActiveTasks = []; # actively scheduled tasks that are running
    /** @var \ATL\Task[] */ public $taskLoopWaitingTasks = []; # descheduled tasks that are waiting to be scheduled manually

    protected $taskLoopOnceWakeup; # used only by loopOnce() to determine passed interval
    protected $taskLoopOnceHandler; # used only by loopOnce() to store current loop handler

    protected $taskLoopParentSleepIsHigher = false; # indicates parent task loop default sleep interval is higher than ours, selects between passing 0 or minimal sleep on tasks returning 0, affects precision

    # default constructor
    public function __construct(...$tasks)
    {
        if (hrtime(true) === false) throw new \ErrorException("TaskLoop cannot run on platforms with non-working hrtime()");
        foreach ($tasks as $task) $this->addTask($task);

        # construct parent Task object for TaskLoop nesting, it could be we never use it but anyways

        # for when TaskLoop itself is used as Task, we use optimized Generator loop as our tasks may be of Fiber type and nesting Fiber stacks is not supported
        parent::__construct([$this, 'tlLoopHandler']);
    }

    # TaskLoop general public API

    # this is the most performance ineffective call designed to run TaskLoop manually from some other loop
    # use loop() for main TaskLoop, add nested loops as tasks to it so they will use optimized Generator based loop
    # this loop uses Generator based task loop internally, and returns you result value, which is:
    #   null if the TaskLoop wants loopOnce() to be ran next only when scheduled (when taskLoopScheduled === true)
    #   negative value if the TaskLoop wants loopOnce() to be re-executed as soon as possible (realtime execution)
    #   zero value if the TaskLoop wants to relinquish control for small while and then be re-executed (default sleep)
    #   positive sleep value for desired minimum sleep in seconds (take care task TaskLoop may be scheduled externally, so check its taskLoopScheduled property during sleep)
    #   "terminate" string if TaskLoop has terminated due to no tasks (when taskLoopTerminateOnNoTasks is true)
    # if you do not pass time interval passed since last loopOnce invocation to loopOnce, loopOnce will calculate it itself from the current system time
    # all in all, this function is for very advanced use inside other task frameworks, not intended for general use in properly designed applications
    public function loopOnce($interval = null)
    {
        if (!$this->taskLoopOnceHandler) {
            # initialize loop handler
            $this->taskLoopOnceWakeup = hrtime(true);
            $this->taskLoopParentSleepIsHigher = false;
                $this->taskLoopOnceHandler = $this->tlGeneratorLoop($this);
            if (!$this->taskLoopOnceHandler->valid()) return 'terminate';
            return $this->taskLoopOnceHandler->current();
        }

        $result = $this->taskLoopOnceHandler->send($interval);
        if (!$this->taskLoopOnceHandler->valid()) return 'terminate';
        return $result;
    }

    # just an optimized infinite loop which calculates intervals itself, use this for the main loop and add your main task to it before calling
    public function loop()
    {
        # loop initialization
        $lastWakeUpTime = hrtime(true);
        $this->taskWithException = null;

        # loop
        while (!empty($this->taskLoopTasks) || !$this->taskLoopTerminateOnNoTasks) {
            while (!empty($this->taskLoopActiveTasks)) {
                $nextLoopIn = $this->taskLoopMaximumSleep;

                # calculate current loop interval
                # we do it once for all tasks in the main loop pass, hence the precise task feature which calculates individual intervals where needed
                # the reason is simple, the hrtime() call is totally not cheap, and the microtime() call is even more slow so we use hrtime(true)
                $interval = (($wakeUpTime = hrtime(true)) - $lastWakeUpTime) / 1000000000;
                if ($interval < $this->taskLoopMinimumInterval) $interval = $this->taskLoopMinimumInterval;
                $lastWakeUpTime = $wakeUpTime;

                # run over all active tasks
                foreach ($this->taskLoopActiveTasks as $taskId => /** @var \ATL\Task */ $taskObject) {
                    # task can be removed or deactivated while we was at it, so the check
                    if (isset($this->taskLoopActiveTasks[$taskId])) {
                        # run the task and check the result
                        $this->taskLoopActiveTaskObject = $taskObject;
                        $result = $taskObject->tlTaskLoopCycle($interval);
                        $this->taskLoopActiveTaskObject = null;
                        if (($result ?? $nextLoopIn) < $nextLoopIn) $nextLoopIn = $result;
                    }
                }

                # loop ended, we may have to sleep
                if (($nextLoopIn >= 0) && !$this->taskLoopScheduled) {
                    if ($nextLoopIn == 0) $nextLoopIn = $this->taskLoopDefaultSleep;
                    if ($nextLoopIn < $this->taskLoopMinimumSleep) $nextLoopIn = $this->taskLoopMinimumSleep;
                    if ($nextLoopIn > $this->taskLoopMaximumSleep) $nextLoopIn = $this->taskLoopMaximumSleep;
                    $this->loopSleep($nextLoopIn);
                }
                $this->taskLoopScheduled = false;
            }
            if (empty($this->taskLoopTasks) && $this->taskLoopTerminateOnNoTasks) break; # if we have no tasks at all and are to terminate under this condition, break out of the loop

            # no active tasks anymore so we have to wait until some specific PHP callback schedules some task in the background (or we would be stuck if nothing does)
            while (empty($this->taskLoopActiveTasks)) $this->loopSleep($this->taskLoopMaximumSleep);
            $this->taskLoopScheduled = false;
        }

        # finish loop and return (meaningless as we can only reach there when no tasks, but anyways, we may add some finalization at some point)
        $this->endLoop();
    }

    public function loopSleep($seconds)
    {
        # for time > default sleep, we have to sleep a while in default sleep intervals, checking for the possible scheduling
        if ($seconds > $this->taskLoopDefaultSleep) {
            # the nanosleep precision is very low, so we use hrtime() inside sleep cycle to check how long we slept
            $sleepTimeSec = floor($this->taskLoopDefaultSleep);
            $sleepTimeNano = floor(($this->taskLoopDefaultSleep - $sleepTimeSec) * 1000000000);
            $newTime = hrtime(true);
            while ($seconds > $this->taskLoopDefaultSleep) {
                $timeBefore = $newTime;
                time_nanosleep($sleepTimeSec, $sleepTimeNano);
                $seconds -= max($this->taskLoopMinimumSleep, (($newTime = hrtime(true)) - $timeBefore) / 1000000000);
            }
        }

        # sleep the rest, it is easy and we do not need it to be too precise
        if ($seconds < $this->taskLoopMinimumInterval) $seconds = $this->taskLoopMinimumInterval;
        $sleepTimeSec = floor($seconds);
        $sleepTimeNano = floor(($seconds - $sleepTimeSec) * 1000000000);
        time_nanosleep($sleepTimeSec, $sleepTimeNano);
    }

    public function endLoop()
    {
        # terminate and remove all remaining tasks on termination
        foreach ($this->taskLoopActiveTasks as /** @var \ATL\Task */ $taskObject) $this->terminateTaskStack($taskObject, false);
        foreach ($this->taskLoopWaitingTasks as /** @var \ATL\Task */ $taskObject) $this->terminateTaskStack($taskObject, false);

        # no task objects should remain
        if (!empty($this->taskLoopTasks)) throw new \ErrorException("TaskLoop termination could not terminate all of the remaining tasks somehow");

        # reset some properties
        $this->taskLoopActiveTaskObject = null;
        $this->taskLoopParentSleepIsHigher = false;
        $this->taskWithException = null;
    }

    # Optimized task nesting loops, loopOnce() actually relies on one of these to do the actual execution

    # Optimized generator loop (placed here for convenience, referenced from Task taskStart() method)
    public function tlLoopHandler($taskLoopTaskObject)
    {
        # initialize nested task loop
        $this->taskWithException = null;
        $this->taskLoopActiveTaskObject = null;
        $this->taskLoopParentSleepIsHigher = ($this->taskLoopDefaultSleep < $this->taskLoop->taskLoopDefaultSleep); # we need this to decide if to use 0 or our own sleep interval in returns, affects precision
        $interval = max($this->taskLoopMinimumInterval, yield true);

        # loop
        while (!empty($this->taskLoopTasks) || !$this->taskLoopTerminateOnNoTasks) {
            while (!empty($this->taskLoopActiveTasks)) {
                $nextLoopIn = $this->taskLoopMaximumSleep;

                # run over all active tasks
                foreach ($this->taskLoopActiveTasks as $taskId => /** @var \ATL\Task */ $taskObject) {
                    # task can be removed or deactivated while we was at it, so the check
                    if (isset($this->taskLoopActiveTasks[$taskId])) {
                        # run the task and check in the result
                        $this->taskLoopActiveTaskObject = $taskObject;
                        $result = $taskObject->tlTaskLoopCycle($interval);
                        $this->taskLoopActiveTaskObject = null;
                        if (($result ?? $nextLoopIn) < $nextLoopIn) $nextLoopIn = $result;
                    }
                }

                # loop ended, just return the loop timing result and get the new interval
                if (($nextLoopIn >= 0) && !$this->taskLoopScheduled) {
                    if ($nextLoopIn == 0) {
                        if (!$this->taskLoopParentSleepIsHigher) {
                            $interval = max($this->taskLoopMinimumInterval, yield 0);
                        } else {
                            $nextLoopIn = $this->taskLoopDefaultSleep;
                        }
                    } else {
                        if ($nextLoopIn < $this->taskLoopMinimumSleep) $nextLoopIn = $this->taskLoopMinimumSleep;
                        if ($nextLoopIn > $this->taskLoopMaximumSleep) $nextLoopIn = $this->taskLoopMaximumSleep;
                        $interval = max($this->taskLoopMinimumInterval, yield $nextLoopIn);
                    }
                } else {
                    $this->taskLoopScheduled = false;
                    $interval = max($this->taskLoopMinimumInterval, yield true);
                }
            }
            if (empty($this->taskLoopTasks) && $this->taskLoopTerminateOnNoTasks) break; # if we have no tasks at all and are to terminate under this condition, break out of the loop

            # no active tasks anymore, so we may switch to manually scheduled mode until some task is scheduled or manipulated otherwise
            $interval = max($this->taskLoopMinimumInterval, yield false);
            $this->taskLoopScheduled = false;
        }

        # if we reach there, we are to be terminated because of no tasks in existence
        # we do not do anything, generator termination automatically terminates the task
    }

    public function taskOnTerminate($taskLoopTaskObject)
    {
        $this->endLoop();
    }

    # TaskLoop task exception handler, override it to provide some logging in case you do not propagate exceptions

    public function onTaskException($taskObject, $exception)
    {
        if ($this->onTaskExceptionHandler !== null) ($this->onTaskExceptionHandler)($taskObject, $exception);
    }

    # TaskLoop task management API

    # returns task object
    # false in interval has a bit special meaning here: if the task is Task object, it retains the original Task interval, otherwise similar to null
    public function addTask($handler, ...$parameters)
    {
        if ($handler instanceof \ATL\ITask) {
            # we have our task object to boot already, but we may need to set interval/parameters/options to it
            if ($handler->taskId === null) $handler->tlConstructTask(); # late initialization to allow easy creation of main() and fiber() type tasks
            # yes, nulls have dual meaning in task parameters but it is very intentional nulls are ignored here for parameters
            if (!empty($parameters)) $handler->taskSetParameters(...$parameters);
        } else {
            # create the proper task object
            $handler = new \ATL\Task($handler, ...$parameters);
        }
        if (isset($this->taskLoopTasks[$handler->taskId])) throw new \ATL\TaskLoopException("Attempted to add task `{$handler->taskId}` which is already added");
        if ($handler->taskLoop !== null) throw new \ATL\TaskLoopException("Tried to add task to task loop, but this task is already added to some other task loop");

        # try to set added task exception mode to propagation if necessary (but do not overried any modes besided non-caring one)
        if ($this->taskLoopTaskExceptionTerminateByDefault) $handler->taskSetExceptionMode(false, false);

        # set initial task state
        $this->taskLoopTasks[$handler->taskId] = $handler;
        $this->taskLoopActiveTasks[$handler->taskId] = $handler;

        # schedule ourselves to execute if we have parent loop
        if (!$this->taskLoopScheduled) {
            if ($this->taskLoop !== null) $this->taskSchedule(); # if we have parent task loop, reschedule us for immediate execution
            $this->taskLoopScheduled = true;
        }

        # start the task and return the task object
        $result = $handler->tlTaskStart($this);

        # take care: task may become terminated or go inactive while initializing, hence the check if it is still there
        if (($result === null) && isset($this->taskLoopActiveTasks[$handler->taskId])) {
            # task wants to become scheduled
            $this->taskLoopWaitingTasks[$handler->taskId] = $handler;
            unset($this->taskLoopActiveTasks[$handler->taskId]);
        }

        # return task object
        return $handler;
    }

    # task is present when it is added to the TaskLoop
    public function isTaskPresent(/** @var \ATL\Task */ $taskObject)
    {
        if (!($taskObject instanceof ITask)) throw new \Exception("Task object passed to TaskLoop control methods must be an instance of ITask");
        if ($taskObject->taskId === null) $taskObject->tlConstructTask(); # late initialization to allow easy creation of main() and fiber() type tasks
        return isset($this->taskLoopTasks[$taskObject->taskId]);
    }

    # task is scheduled when it is either active or waiting (so not pushed down the stack)
    public function isTaskScheduled(/** @var \ATL\Task */ $taskObject, $throwIfNotExists = false)
    {
        if (!($taskObject instanceof ITask)) throw new \Exception("Task object passed to TaskLoop control methods must be an instance of ITask");
        if ($taskObject->taskId === null) $taskObject->tlConstructTask(); # late initialization to allow easy creation of main() and fiber() type tasks
        if (isset($this->taskLoopActiveTasks[$taskObject->taskId]) || isset($this->taskLoopWaitingTasks[$taskObject->taskId])) return true;
        if (!$throwIfNotExists || isset($this->taskLoopTasks[$taskObject->taskId])) return false;
        throw new \ATL\TaskLoopException("Tried to get scheduling status for task `{$taskObject->taskId}` which does not exist");
    }

    # task is active when is is actively scheduled for execution (not waiting to be scheduled)
    public function isTaskActive(/** @var \ATL\Task */ $taskObject, $throwIfNotExists = false)
    {
        if (!($taskObject instanceof ITask)) throw new \Exception("Task object passed to TaskLoop control methods must be an instance of ITask");
        if ($taskObject->taskId === null) $taskObject->tlConstructTask(); # late initialization to allow easy creation of main() and fiber() type tasks
        if (isset($this->taskLoopActiveTasks[$taskObject->taskId])) return true;
        if (!$throwIfNotExists || isset($this->taskLoopTasks[$taskObject->taskId])) return false;
        throw new \ATL\TaskLoopException("Tried to get active status for task `{$taskObject->taskId}` which does not exist");
    }

    # task is waiting when it is scheduled but is passively waiting to be scheduled OR when it is not scheduled (you need to also check isTaskScheduled() to determine the cause)
    public function isTaskWaiting(/** @var \ATL\Task */ $taskObject, $throwIfNotExists = false)
    {
        if (!($taskObject instanceof ITask)) throw new \Exception("Task object passed to TaskLoop control methods must be an instance of ITask");
        if ($taskObject->taskId === null) $taskObject->tlConstructTask(); # late initialization to allow easy creation of main() and fiber() type tasks
        if (!isset($this->taskLoopTasks[$taskObject->taskId])) {
            if ($throwIfNotExists) throw new \ATL\TaskLoopException("Tried to get waiting status for task `{$taskObject->taskId}` which does not exist");
            return false;
        }
        return !isset($this->taskLoopActiveTasks[$taskObject->taskId]);
    }

    # switches task to non-scheduled mode, task will not execute anymore until manually rescheduled after this
    public function descheduleTask(/** @var \ATL\Task */ $taskObject, $throwIfNotExists = false)
    {
        if (!($taskObject instanceof ITask)) throw new \Exception("Task object passed to TaskLoop control methods must be an instance of ITask");
        if ($taskObject->taskId === null) $taskObject->tlConstructTask(); # late initialization to allow easy creation of main() and fiber() type tasks
        if (!isset($this->taskLoopTasks[$taskObject->taskId])) {
            # no such task exists
            if ($throwIfNotExists) throw new TaskLoopException("Tried to deschedule task `{$taskObject->taskId}` which does not exist");
            return false;
        }

        if (isset($this->taskLoopActiveTasks[$taskObject->taskId])) {
            # really switch to descheduled
            $this->taskLoopWaitingTasks[$taskObject->taskId] = $taskObject;
            unset($this->taskLoopActiveTasks[$taskObject->taskId]);
            return true;
        }

        return false; # did not do the switch
    }

    # immediate rescheduling function, reschedules task to run immediately, disregarding active wait interval
    # use with utmost care and only if the underlying task supports it logically, i.e. with looped self-timed tasks like TaskLoop itself
    # TaskLoop uses it because it can be in conditioned long sleep and needs to exit this sleep and schedule itself
    public function scheduleTask(/** @var \ATL\Task */ $taskObject, $throwIfNotExists = false)
    {
        if (!($taskObject instanceof ITask)) throw new \Exception("Task object passed to TaskLoop control methods must be an instance of ITask");
        if ($taskObject->taskId === null) $taskObject->tlConstructTask(); # late initialization to allow easy creation of main() and fiber() type tasks
        if (!isset($this->taskLoopTasks[$taskObject->taskId])) {
            # no such task exists
            if ($throwIfNotExists) throw new \ATL\TaskLoopException("Tried to schedule task `{$taskObject->taskId}` which does not exist");
            return false;
        }

        # schedule task for next execution cycle
        $taskObject->tlTaskSchedule();

        # schedule ourselves to execute if we have parent loop
        if (!$this->taskLoopScheduled) {
            if ($this->taskLoop !== null) $this->taskSchedule(); # if we have parent task loop, reschedule us for immediate execution
            $this->taskLoopScheduled = true;
        }

        # if the task was descheduled and waiting to be scheduled, move to active tasks
        if (isset($this->taskLoopWaitingTasks[$taskObject->taskId])) {
            # move task from waiting tasks to active tasks
            $this->taskLoopActiveTasks[$taskObject->taskId] = $taskObject;
            unset($this->taskLoopWaitingTasks[$taskObject->taskId]);
        }
    }

    # changing task options, use with utmost care
    public function setTaskOptions(/** @var \ATL\Task */ $taskObject, $options, $throwIfNotExists = false)
    {
        if (!($taskObject instanceof ITask)) throw new \Exception("Task object passed to TaskLoop control methods must be an instance of ITask");
        if ($taskObject->taskId === null) $taskObject->tlConstructTask(); # late initialization to allow easy creation of main() and fiber() type tasks
        if (!isset($this->taskLoopTasks[$taskObject->taskId])) {
            # no such task exists
            if ($throwIfNotExists) throw new \ATL\TaskLoopException("Tried to set options for task `{$taskObject->taskId}` which does not exist");
            return false;
        }

        # set new task options
        $taskObject->tlTaskSetOptions($options);
    }

    public function setTaskExceptionMode(/** @var \ATL\Task */ $taskObject, $mode, $override = true, $throwIfNotExists = false)
    {
        if (!($taskObject instanceof ITask)) throw new \Exception("Task object passed to TaskLoop control methods must be an instance of ITask");
        if ($taskObject->taskId === null) $taskObject->tlConstructTask(); # late initialization to allow easy creation of main() and fiber() type tasks
        if (!isset($this->taskLoopTasks[$taskObject->taskId])) {
            # no such task exists
            if ($throwIfNotExists) throw new \ATL\TaskLoopException("Tried to set options for task `{$taskObject->taskId}` which does not exist");
            return false;
        }

        # set new task exception mode directly
        $taskObject->taskSetExceptionMode($mode, $override);
    }

    # add onTerminate handler to the task, returns handler ID (you can provide your own handler ID, duplicate handlers are not allowed)
    public function addTaskOnTerminateHandler(/** @var \ATL\Task */ $taskObject, $handler, $handlerId = null, $throwIfNotExists = false)
    {
        if (!($taskObject instanceof ITask)) throw new \Exception("Task object passed to TaskLoop control methods must be an instance of ITask");
        if ($taskObject->taskId === null) $taskObject->tlConstructTask(); # late initialization to allow easy creation of main() and fiber() type tasks
        if (!isset($this->taskLoopTasks[$taskObject->taskId])) {
            # no such task exists
            if ($throwIfNotExists) throw new \ATL\TaskLoopException("Tried to add onTerminate handler to task `{$taskObject->taskId}` which does not exist");
            return false;
        }

        # add terminate handler to task object
        return $taskObject->tlTaskAddOnTerminateHandler($handler, $handlerId);
    }

    # removes onTerminate handler from the task
    public function removeTaskOnTerminateHandler(/** @var \ATL\Task */ $taskObject, $handlerId, $throwIfNotExists = false)
    {
        if (!($taskObject instanceof ITask)) throw new \Exception("Task object passed to TaskLoop control methods must be an instance of ITask");
        if ($taskObject->taskId === null) $taskObject->tlConstructTask(); # late initialization to allow easy creation of main() and fiber() type tasks
        if (!isset($this->taskLoopTasks[$taskObject->taskId])) {
            # no such task exists
            if ($throwIfNotExists) throw new \ATL\TaskLoopException("Tried to remove onTerminate handler from task `{$taskObject->taskId}` which does not exist");
            return false;
        }

        # remote terminate handler from task object
        return $taskObject->tlTaskRemoveOnTerminateHandler($handlerId, $throwIfNotExists);
    }

    # terminates task and all its subtasks than may be pending or running up to the topmost one in stack
    # actually this is just alias to terminateTaskStack with notBelow set to true
    public function terminateTask(/** @var \ATL\Task */ $taskObject, $throwIfNotExists = false, $forcibly = true) { return $this->terminateTaskStack($taskObject, $throwIfNotExists, $forcibly, true); }

    # terminates whole task stack up to and potentially including the first task in stack
    # notBelow makes us only terminate task stack up to the task specified, not going below to the first task in stack
    # forcibly (the default) indicates we are forcibly terminating the task and no task result should be provided
    public function terminateTaskStack(/** @var \ATL\Task */ $taskObject, $throwIfNotExists = false, $forcibly = true, $notBelow = false)
    {
        if (!($taskObject instanceof ITask)) throw new \Exception("Task object passed to TaskLoop control methods must be an instance of ITask");
        if ($taskObject->taskId === null) $taskObject->tlConstructTask(); # late initialization to allow easy creation of main() and fiber() type tasks
        if (!isset($this->taskLoopTasks[$taskObject->taskId])) {
            # no such task exists
            if ($throwIfNotExists) throw new \ATL\TaskLoopException("Tried to terminate task stack for task `{$taskObject->taskId}` which does not exist");
            return false;
        }

        # schedule ourselves to execute if we have parent loop
        if (!$this->taskLoopScheduled) {
            if ($this->taskLoop !== null) $this->taskSchedule(); # if we have parent task loop, reschedule us for immediate execution
            $this->taskLoopScheduled = true;
        }

        # finish the task stack, remove it and schedule ourselves for closest loop execution
        $topmostTaskObject = $taskObject->tlTaskGetTopmostTaskInStack();
        $topmostTaskObject->tlTaskEndStack($forcibly, $notBelow ? $taskObject : null);
        return true;
    }

    # terminates all tasks in the TaskLoop, will effectively end the TaskLoop unless taskLoopTerminateOnNoTasks is not false
    # if taskLoopTerminateOnNoTasks is false, you must take extra care about not getting the empty TaskLoop running after this
    public function terminateAllTasks($forcibly = true)
    {
        # just do it, terminating all the active tasks, the trick is that waiting tasks become active after child tasks termination
        while (!empty($this->taskLoopActiveTasks))
            $this->terminateTaskStack(end($this->taskLoopActiveTasks), false, $forcibly);

        # we should have no tasks left there, but if user is doing some obscure task termination handling (i.e. placing other tasks to wait mode inside onTerminate handlers), it may still leave us with some, so finish the rest
        while (!empty($this->taskLoopTasks))
            $this->terminateTaskStack(end($this->taskLoopTasks), false, $forcibly);
    }

    # Internal TaskLoop API and routines

    public function tlActivateTaskObject(/** @var \ATL\Task */ $taskObject, $schedule = true)
    {
        if (!isset($this->taskLoopTasks[$taskObject->taskId])) throw new \ErrorException("Tried to activate task `{$taskObject->taskId}` which does not exist");
        if (isset($this->taskLoopActiveTasks[$taskObject->taskId]) || isset($this->taskLoopWaitingTasks[$taskObject->taskId]))
            throw new \ErrorException("Tried to activate task `{$taskObject->taskId}` which is already scheduled at the moment");
        if ($schedule) {
            $this->taskLoopActiveTasks[$taskObject->taskId] = $taskObject;
        } else {
            $this->taskLoopWaitingTasks[$taskObject->taskId] = $taskObject;
        }
    }

    public function tlDeactivateTaskObject(/** @var \ATL\Task */ $taskObject)
    {
        if (!isset($this->taskLoopTasks[$taskObject->taskId])) throw new \ErrorException("Tried to deactivate task `{$taskObject->taskId}` which does not exist");
        if (!isset($this->taskLoopActiveTasks[$taskObject->taskId]) && !isset($this->taskLoopWaitingTasks[$taskObject->taskId]))
            throw new \ErrorException("Tried to deactivate task `{$taskObject->taskId}` which is not scheduled at the moment");
        unset($this->taskLoopActiveTasks[$taskObject->taskId], $this->taskLoopWaitingTasks[$taskObject->taskId]);
    }

    public function tlRemoveTaskObject(/** @var \ATL\Task */ $taskObject)
    {
        if (!isset($this->taskLoopTasks[$taskObject->taskId])) throw new \ErrorException("Tried to remove task `{$taskObject->taskId}` which does not exist");
        if (isset($this->taskLoopActiveTasks[$taskObject->taskId]) || isset($this->taskLoopWaitingTasks[$taskObject->taskId]))
            throw new \ErrorException("Tried to remove task `{$taskObject->taskId}` which is scheduled at the moment");
        unset($this->taskLoopTasks[$taskObject->taskId]);
    }

    # fast and furious TaskLoop factory

    public static function runLoop(/** @var \ATL\Task[] */ ...$tasks)
    {
        $loop = new static(...$tasks);
        $loop->loop();
        return $loop;
    }

    # similar to runLoop but runs a single Task in own task loop and get its result, can be used to run specific Task from synchronous code as a sort of function call

    public static function runTask(/** @var \ATL\Task */ $task)
    {
        $loop = new static($task);
        $loop->loop();
        return $task->taskResult;
    }

    # infinite TaskLoop factory

    public static function createInfiniteLoop(/** @var \ATL\Task[] */ ...$tasks)
    {
        $loop = new static(...$tasks);
        $loop->taskLoopTerminateOnNoTasks = false;
        return $loop;
    }
}

class TaskLoop extends \ATL\Task implements \ATL\ITaskLoop { use \ATL\TTaskLoop; }

# Exception classes

class TaskLoopException extends \Exception { }
