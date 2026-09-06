<?php

namespace ATL\Task;

# Good manually scheduled task class that allows to wait on multiple tasks to terminate before proceeding (yield it from your task to wait)
# Optional timeout can be specified, this task is manually scheduled task and automatically reschedules itself to execute when all tasks terminate
# Very handy to use instead of nested TaskLoop when you need to wait on multiple Promise or other tasks to resolve, just add your tasks to your own task loop and WaitOn on them
# The tasks to monitor must have been added to TaskLoop prior to WaitOn, otherwise they will be considered terminated just on the very WaitOn start
# Check timedOut property to see if the wait timed out if you specified a timeout, or check taskResult, it will contain array of tasks left running
# If taskResult is empty array, means wait completed; if taskResult has some tasks in array, means we timed out; if taskResult is null, means we were forcibly terminated

interface IWaitOn
{
    public function setTimeout($seconds);
    public static function waitWithTimeout($timeout, ...$taskObjects);
}

trait TWaitOn
{
    public $timedOut;

    protected $waitMonitoredTasks;
    protected $waitTimeout;

    public function __construct(...$taskObjects)
    {
        $this->waitMonitoredTasks = [];
        $this->parseTaskObjects($taskObjects);
    }

    public function setTimeout($seconds)
    {
        $this->waitTimeout = $seconds;
    }

    public function main()
    {
        $this->timedOut = false;
        if (!$this->addMonitoredTasks()) return $this->waitMonitoredTasks; # termination condition reached even before starting
        yield $this->waitTimeout ?? false;
        if (count($this->waitMonitoredTasks) != 0) $this->timedOut = true;
        return $this->waitMonitoredTasks;
    }

    protected function parseTaskObjects($taskObjects)
    {
        foreach ($taskObjects as $taskObject) {
            if (is_object($taskObject)) {
                if (isset($taskObject->taskId))
                    $this->waitMonitoredTasks[$taskObject->taskId] = $taskObject;
            } elseif (is_array($taskObject)) {
                $this->parseTaskObjects($taskObject);
            }
        }
    }

    protected function addMonitoredTasks()
    {
        $this->waitMonitoredTasksMap = [];
        foreach ($this->waitMonitoredTasks as $taskId => $taskObject) {
            if ($taskObject->taskRunning()) {
                $taskObject->taskAddOnTerminateHandler([$this, 'terminateMonitor'], $this->taskId);
            } else {
                unset($this->waitMonitoredTasks[$taskId]);
            }
        }
        return !empty($this->waitMonitoredTasks); # if we have no tasks to monitor, terminate our wait task as well
    }

    public function terminateMonitor($taskObject)
    {
        if (!isset($this->waitMonitoredTasks[$taskObject->taskId])) throw new \ErrorException("Received onTerminate event from task `{$taskObject->taskId}` which is not in the monitor list");
        $taskObject->taskRemoveOnTerminateHandler($this->taskId);
        unset($this->waitMonitoredTasks[$taskObject->taskId]);
        if (count($this->waitMonitoredTasks) == 0) {
            # yes, all tasks terminated
            $this->taskSchedule(); # reschedule us for immediate execution
        }
    }

    public static function waitWithTimeout($timeout, ...$taskObjects)
    {
        $waitTask = new static(...$taskObjects);
        $waitTask->setTimeout($timeout);
        return $waitTask;
    }
}

class WaitOn extends \ATL\Task implements \ATL\Task\IWaitOn { use \ATL\Task\TWaitOn; }

# This extended WaitOn task class allows to wait until at least one of the tasks terminates before proceeding (yield it from your task to wait)
# Optional timeout can be specified, this task is manually scheduled task and automatically reschedules itself to execute on any task termination
# Handy to wait on any of multiple Promise or other tasks to resolve, task objects terminated are placed into terminatedTasks array with keys being taskIDs and values being task objects
# The tasks to monitor must have been added to TaskLoop prior to WaitOnAny, otherwise they will be considered terminated just on the very WaitOnAny start, defeating the purpose
# Check timedOut property to see if the wait timed out if you specified a timeout, or check taskResult it will contain array of tasks that were terminated during the wait
# If taskResult is empty array, this means we timed out or had no tasks; if taskResult has some tasks in array, means wait completed; if taskResult is null, means we were forcibly terminated
# You can restart WaitOnAny task as many times as you need to continue monitoring the remaining tasks, this will clear list of terminated tasks and try to wait on the rest

class WaitOnAny extends \ATL\Task\WaitOn
{
    public $terminatedTasks;
    public $remainingTasks;

    protected $isComplete; # indicates we rescheduled us already so to not do extra rescheduling calls

    public function main()
    {
        $this->timedOut = false;
        if (!$this->addMonitoredTasks()) return $this->terminatedTasks; # termination condition reached even before starting
        yield $this->waitTimeout ?? false;
        if (count($this->terminatedTasks) == 0) $this->timedOut = true;
        return $this->terminatedTasks;
    }

    protected function addMonitoredTasks()
    {
        $this->isComplete = false;
        $this->terminatedTasks = [];

        # verify if any task is terminated, we do not need to add any handlers in this case
        foreach ($this->waitMonitoredTasks as $taskId => $taskObject) {
            if (!$taskObject->taskRunning()) {
                $this->terminatedTasks[$taskId] = $taskObject;
                unset($this->waitMonitoredTasks[$taskId]);
            }
        }
        if (!empty($this->terminatedTasks)) return false; # some tasks already terminated, we do not need to do anything, terminate our wait task as well

        # now just add terminate handlers
        foreach ($this->waitMonitoredTasks as $taskId => $taskObject)
            $taskObject->taskAddOnTerminateHandler([$this, 'terminateMonitor'], $this->taskId);

        return !empty($this->waitMonitoredTasks); # if we have no tasks to monitor, terminate our wait task as well
    }

    public function terminateMonitor($taskObject)
    {
        if (!isset($this->waitMonitoredTasks[$taskObject->taskId])) throw new \ErrorException("Received onTerminate event from task `{$taskObject->taskId}` which is not in the monitor list");
        $taskObject->taskRemoveOnTerminateHandler($this->taskId);
        $this->terminatedTasks[$taskObject->taskId] = $this->waitMonitoredTasks[$taskObject->taskId];
        unset($this->waitMonitoredTasks[$taskObject->taskId]);
        $this->remainingTasks = $this->waitMonitoredTasks;

        foreach ($this->waitMonitoredTasks as $id => $taskObject)
            $taskObject->taskRemoveOnTerminateHandler($this->taskId);

        if (!$this->isComplete) {
            $this->isComplete = true;
            $this->taskSchedule(); # reschedule us for immediate execution
        }
    }
}

# Promise class that provides a promise-like object which has success, error and result properties, success is true if onSuccess is called, false if onError is called, null otherwise, result is filled by onSuccess/onError or null
# your task handler can call $taskObject->onSuccess() or $taskObject->onError() to return the promise result before terminating, otherwise it should act just as normal task handler
# you can restart Promise task as much as needed to repeat the promise operation, Promise taskOnStartup() handler will handle the internal reinitialization (do not forget to call it if you extend Promise class)
# in case of multiple onSuccess() / onError() calls, the last call type and data prevails

# you can easily avoid using Promise by just doing proper return from the task handler (Generator or Fiber) or taskFinish() (object handler) and checking it at the parent task or elsewhere
# Promise is mostly intended for Closure type tasks that cannot return result easily, so onSuccess() / onError() provides at least some way to do it

class Promise extends \ATL\Task
{
    public $success = false;
    public $error = false;
    public $state = 'nothing';
    public $result = null;

    public function taskOnStartup($taskObject, &$parameters)
    {
        $this->success = false;
        $this->error = false;
        $this->state = 'nothing';
        $this->result = null;
    }

    public function onSuccess($result = null)
    {
        $this->success = true;
        $this->error = false;
        $this->state = 'success';
        $this->result = $result;
    }

    public function onError($result = null)
    {
        $this->success = false;
        $this->error = true;
        $this->state = 'error';
        $this->result = $result;
    }
}

# GarbageCollector class provides easy task to run PHP heap garbage collection mechanism once in a while for long running tasks
# By default runs gc_collect_cycles() / gc_mem_caches() every minute, can be overridden by setting specific interval properties
# Never terminates, add as child task to your main task or take care of it yourself if you need it to stop

class GarbageCollector extends \ATL\Task
{
    # small fractional parts are there to make sure we do not run both at once
    public $gcCollectCyclesInterval = 60.25;
    public $gcMemCachesInterval = 60.75;

    public function main()
    {
        if (function_exists('gc_enable')) @gc_enable();
        yield true;

        $cyclesTimeout = $this->gcCollectCyclesInterval;
        $cachesTimeout = $this->gcMemCachesInterval;
        while (true) {
            $interval = yield min($cyclesTimeout, $cachesTimeout);
            $cyclesTimeout -= $interval;
            $cachesTimeout -= $interval;

            if ($cyclesTimeout <= 0) {
                if (function_exists('gc_collect_cycles')) @gc_collect_cycles();
                $cyclesTimeout = $this->gcCollectCyclesInterval;
            }

            if ($cachesTimeout <= 0) {
                if (function_exists('gc_mem_caches')) @gc_collect_cycles();
                $cachesTimeout = $this->gcMemCachesInterval;
            }
        }
    }
}
