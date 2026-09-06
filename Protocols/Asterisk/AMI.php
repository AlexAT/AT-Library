<?php

namespace ATL\Protocols\Asterisk;

# Supported event handlers:
# - connect ($version, $ami) - called when AMI interface is connected and version string is obtained
# - disconnect ($error, $ami) - called when AMI interface completely disconnects, result may be null (graceful socket disconnect) or error string
# - event ($event, $data, $ami) - called when asynchronous AMI Event comes from AMI interface
# - log ($message, $ami) - called when AMI class wants to log a message

# Message callbacks accept ($response, $messageParameters, $ami) arguments

# this is required because of PHP limitation: we need to override some constants and methods coming from interfaces/traits
class AMIPrototype extends \ATL\Task implements \ATL\IEventHandlers { use \ATL\TEventHandlers; }
class AMI extends \ATL\Protocols\Asterisk\AMIPrototype
{
    const STATE_INITIAL = 0x0000;
    const STATE_CONNECTING = 0x1000;
    const STATE_CONNECTED = 0x8000;
    const STATE_DISCONNECTED = 0xE000;
    const STATE_ABORTED = 0xF000;

    const ehEventHandlerUseClosure = ['connect' => false, 'disconnect' => false];

    public $connectTimeout = null; # use default socket connect timeout
    public $readTimeout = 10; # allow this much to read from AMI before timing out (only when we expect something to be read)
    public $writeTimeout = 10; # allow this much time for writes to AMI to be flushed before timing out (only when we are writing something)
    public $messageTimeout = 10; # allow this much time for message to wait for response before timing out (message callback will be called with null result on timeout)
    public $maxLineLength = 0x1000; # maximum line length to expect from AMI
    public $maxReadSize = 0x100; # maximum number of lines read from AMI and processed at once

    public $amiState;
    public $amiServerID;
    public $amiError;

    protected $amiHost;
    protected $amiPort;
    /** @var \ATL\Socket\TCP */ protected $amiSocket;

    /** @var \ATL\Task */ protected $readTask;
    /** @var \ATL\Task */ protected $writeTask;
    /** @var \ATL\Task */ protected $cleanerTask;

    protected $nextMessageId; # message ID to be set up next
    /** @var \SplDoublyLinkedList[] */ protected $writeQueues = []; # message queues with messages to be sent, [queue] => SplDoublyLinkedList of Message objects
    /** @var \ATL\Protocols\Asterisk\AMI\Message[] */ protected $waitQueue = []; # wait queues with messages pending to have response

    protected $pendingReadEvent;
    protected $pendingWriteEvent;

    public function __construct($amiHost = '127.0.0.1', $amiPort = 5038)
    {
        $this->amiState = $this::STATE_INITIAL;
        $this->amiHost = $amiHost;
        $this->amiPort = $amiPort;
    }

    protected function ehOnEventHandlerAdd($owner, $handler, $callback, $runHandler)
    {
        switch ($handler) {
            case 'connect':
            if ($runHandler)
                if (($this->amiState >= $this::STATE_CONNECTED) && ($this->amiState < $this::STATE_DISCONNECTED))
                    ($callback)($this->amiServerID, $this);
            break;

            case 'disconnect':
            if ($runHandler)
                if (($this->amiState >= $this::STATE_DISCONNECTED) && ($this->amiState <= $this::STATE_ABORTED))
                    ($callback)($this);
            break;
        }
    }

    # main socket task that spawns socket handling subtasks in order
    public function main($taskObject)
    {
        yield true;

        $this->nextMessageId = 1;
        $this->writeQueues = [];
        $this->waitQueue = [];
        $this->amiServerID = null;
        $this->amiError = null;

        $this->amiState = $this::STATE_CONNECTING;
        $this->ehInvokeEventHandlers('log', "Connecting to AMI interface @ {$this->amiHost} port {$this->amiPort}", $this);
        $this->taskAddChildTask($this->amiSocket = new \ATL\Socket\TCP($this->amiHost, $this->amiPort, $this->connectTimeout, ['socket' => ['tcp_nodelay' => true]]));

        # connect to AMI
        $result = null;
        if (($result = yield new \ATL\Task([$this, 'amiConnectTask'])) === true) {
            # connected to AMI
            $this->readTask = $this->taskAddChildTask([$this, 'amiReadTask']);
            $this->writeTask = $this->taskAddChildTask([$this, 'amiWriteTask']);
            $this->cleanerTask = $this->taskAddChildTask([$this, 'amiCleanerTask']);
            $this->amiState = $this::STATE_CONNECTED;
            $this->ehInvokeEventHandlers('log', "Connected to AMI interface @ {$this->amiHost} port {$this->amiPort}", $this);
            yield new \ATL\Task\WaitOnAny($this->readTask, $this->writeTask);

            # one of our vital tasks terminated, terminate both and check tasks and socket results for actual reason
            $result = $this->readTask->taskResult ?? $this->writeTask->taskResult;
            if (($result === null) && ($this->amiSocket->getErrorCode() !== null))
                $result = 'Socket error '.$this->amiSocket->getErrorCode().': '.$this->amiSocket->getErrorText();
        }
        $this->amiError = $result;
        $this->amiFinish();

        $this->ehInvokeEventHandlers('log', "AMI interface @ {$this->amiHost} port {$this->amiPort} has been disconnected".($result ? ": {$result}" : ""), $this);
        $this->ehInvokeEventHandlers('disconnect', $result, $this);
        return $result;
    }

    public function amiConnectTask($taskObject)
    {
        if (($result = yield new \ATL\Socket\WaitForConnect($this->amiSocket, $this->connectTimeout)) !== true) {
            if (is_array($result)) return "Failed to connect to AMI interface: error {$result[0]}: {$result[1]}";
            return "Failed to connect to AMI interface: timed out while trying to connect";
        }
        $this->ehInvokeEventHandlers('log', "Established AMI interface socket @ {$this->amiHost} port {$this->amiPort}", $this);
        return true;
    }

    public function amiReadTask($taskObject)
    {
        # after start, we always need to read a single line identifying the AMI server, we do this using ReadDelimited primitive subtask as we do not want to create 2 similar loops
        if ((yield $read = new \ATL\Socket\ReadDelimited($this->amiSocket, "\r\n", $this->readTimeout, $this->readTimeout, $this->maxLineLength, 1)) !== true)
            return "Failed to connect to AMI interface: timed out while reading AMI server identification line";
        if ($read->readData === null)
            return "Failed to connect to AMI interface: failed to read AMI server identification line";
        $this->amiServerID = trim(reset($read->readData));
        $this->ehInvokeEventHandlers('log', "AMI server: ".$this->amiServerID, $this);

        # prepare socket event handlers
        $this->amiSocket->addEventHandlers($this, [
            'hasData' => [$this, 'amiSocketHasDataEvent'],
            'writeEmpty' => [$this, 'amiSocketWriteEmptyEvent'],
            'eof' => [$this, 'amiSocketStateEvent'],
            'disconnect' => [$this, 'amiSocketStateEvent'],
            'error' => [$this, 'amiSocketStateEvent'],
        ]);

        # connected
        $this->amiState = $this::STATE_CONNECTED;
        $this->ehInvokeEventHandlers('connect', $this->amiServerID, $this); # invoke connect handler

        # schedule our write task as it is waiting for us to initialize
        $this->writeTask->taskSchedule();
        $this->pendingWriteEvent = true;

        # loop reading the socket
        $currentMessage = [];
        $currentType = null;
        $responseFollows = false;
        while (true) {
            $this->pendingReadEvent = false;

            # attempt reading something from the socket until we cannot read anything
            while (true) {
                $data = $this->amiSocket->readDelimitedBulk($this->maxLineLength, $this->maxReadSize);
                if ($data === null) break;

                # process the data read, we relinquish control after each successfully processed response/event to allow other tasks to handle them
                foreach ($data as $line) {
                    $line = trim($line); # cut all extra spaces and delimiters
                    if (!$responseFollows) {
                        if ($line !== '') {
                            if (preg_match('#^([^\\:]+?)\\:(.*)$#S', $line, $m)) {
                                $key = strtolower(trim($m[1]));
                                $value = trim($m[2]);
                                if (empty($currentMessage)) {
                                    # first line is type line, we need to record it
                                    $currentType = $key;
                                    if (($currentType == 'response') && !strcasecmp($value, 'follows')) {
                                        # multiline response expected
                                        $responseFollows = true;
                                        $currentMessage['output'] = [];
                                    }
                                }
                                if (!isset($currentMessage[$key])) {
                                    # first encounter
                                    $currentMessage[$key] = [$value];
                                } else {
                                    # already there
                                    $currentMessage[$key][] = $value;
                                }
                            }
                        } else {
                            # empty line completes the message
                            if (!empty($currentMessage)) {
                                # okay, current message is to be identified and sent
                                switch ($currentType) {
                                    case 'response':
                                    $currentMessage = array_map(function ($v) { return (count($v) > 1) ? $v : reset($v); }, $currentMessage); # make those arrays of 1 elements just elements themselves
                                    if (isset($currentMessage['actionid'])) {
                                        if (isset($this->waitQueue[$currentMessage['actionid']])) {
                                            # yes, we have where to send it
                                            /** @var \ATL\Protocols\Asterisk\AMI\Message */ $msg = $this->waitQueue[$currentMessage['actionid']];
                                            unset($this->waitQueue[$currentMessage['actionid']]);
                                            ($msg->callback)($currentMessage, $msg->parameters, $this);
                                        }
                                    }
                                    break;

                                    case 'event':
                                    $currentMessage = array_map(function ($v) { return (count($v) > 1) ? $v : reset($v); }, $currentMessage); # make those arrays of 1 elements just elements themselves
                                    $this->ehInvokeEventHandlers('event', is_array($currentMessage['event']) ? reset($currentMessage['event']) : $currentMessage['event'], $currentMessage, $this);
                                    break;
                                }

                                # reset message held
                                $currentMessage = [];
                                $currentType = null;
                                $responseFollows = false;
                            }
                        }
                    } else {
                        # we are getting output from Response: Follows, check for end of it
                        if (!preg_match('#^\\-\\-END\\s+.+\\-\\-$#S', $line)) {
                            # no, just a plain line
                            $currentMessage['output'][] = $line;
                        } else {
                            # this is the end of output processing
                            $responseFollows = false;
                        }
                    }
                }

                yield true; # relinquish control and attempt reading more on next loop
            }

            # nothing was read from socket, check socket state
            if (!$this->amiSocket->isConnected()) return; # abort read task with no error if socket was disconnected

            # wait for next socket event
            if (!$this->pendingReadEvent) {
                if (!empty($this->waitQueue)) {
                    # we are expecting message response(s) to be sent
                    yield $this->readTimeout; # wait until we are scheduled by any socket event or time out
                } else {
                    # we are not expecting anything at the moment, so we may just wait until scheduled by any new event
                    yield false;
                }
            }
            if (!$this->pendingReadEvent) {
                # we timed out waiting for anything to be read while we had outstanding messages to be fullfilled
                if (!$this->amiSocket->isConnected()) return; # socket was disconnected, abort gracefully
                return "Timed out waiting for AMI data to be read";
            }
        }
    }

    public function amiWriteTask($taskObject)
    {
        # after start, we wait until we are allowed to write (read task needs to read first line first)
        if ($this->amiState != $this::STATE_CONNECTED) yield false;

        # rinse, repeat
        while (true) {
            $this->pendingWriteEvent = false;
            if (!empty($this->writeQueues)) {
                foreach ($this->writeQueues as $wqid => $wq) {
                    /** @var \ATL\Protocols\Asterisk\AMI\Message */ $msg = $wq->shift();
                    if (!empty($this->waitQueue)) {
                        $this->waitQueue[$msg->id] = $msg;
                    } else {
                        $this->waitQueue[$msg->id] = $msg;
                        $this->cleanerTask->taskSchedule(); # schedule cleanup task to proceed
                    }
                    foreach ($msg->parameters as $k => $v)
                        $this->amiSocket->write(is_array($v) ? implode('', array_map(function ($vv) use ($k) { return "{$k}: {$vv}\r\n"; }, $v)) : "{$k}: {$v}\r\n");
                    $this->amiSocket->write("\r\n");
                    if ($wq->isEmpty()) unset($this->writeQueues[$wqid]); # remove empty queues
                }

                # wait until write is flushed or we time out
                if (!$this->pendingWriteEvent) yield $this->writeTimeout;
            } else {
                # write queues are empty, so we may just wait until scheduled by any new message
                if (!$this->pendingWriteEvent) yield false;
            }
            if (!$this->pendingWriteEvent) {
                # we timed out waiting for write queue to be flushed or socket was disconnected
                if (!$this->amiSocket->isConnected()) return; # socket was disconnected, abort gracefully
                return "Timed out waiting for AMI data to be written";
            }
        }
    }

    public function amiCleanerTask($taskObject)
    {
        while (true)
        {
            $interval = yield (empty($this->waitQueue)) ? false : 1; # wait queue is empty, so wait until we are scheduled, or wait for ~1 second to re-check
            $cleanupList = [];
            foreach ($this->waitQueue as $id => $msg) {
                $msg->timeout -= $interval; # this may look like excessive memory write, but it allows us to avoid weird behavior on possible backwards time jumps
                if ($msg->timeout <= 0) $cleanupList[$id] = $msg;
            }
            foreach ($cleanupList as $id => $msg) {
                ($msg->callback)(null, $msg->parameters, $this); # invoke message callback with null argument indicating message timeout
                unset($this->waitQueue[$id]);
            }
        }
    }

    public function amiFinish()
    {
        $this->amiState = $this::STATE_DISCONNECTED;

        # terminate tasks
        if ($this->readTask) $this->readTask->taskTerminate();
        if ($this->writeTask) $this->writeTask->taskTerminate();
        if ($this->cleanerTask) $this->cleanerTask->taskTerminate();

        # disable scheduling tasks on socket events
        $this->pendingReadEvent = true;
        $this->pendingWriteEvent = true;

        # abort and terminate socket
        if ($this->amiSocket) {
            $this->amiSocket->abort();
            $this->amiSocket->taskTerminate();
            $this->amiSocket = null;
        }

        # time out all waiting messages and clear queues
        foreach ($this->waitQueue as $id => $msg)
            ($msg->callback)(null, $msg->parameters, $this);
        $this->waitQueue = [];
        $this->writeQueues = [];

        # destroy task references
        $this->readTask = null; $this->writeTask = null; $this->cleanerTask = null;
    }

    public function taskOnTerminate($taskObject)
    {
        # our task is requested to terminate, maybe forcibly, maybe not, terminate the socket task as well
        $this->amiFinish();
    }

    # socket events
    public function amiSocketHasDataEvent()
    {
        # socket new data event, push read task as it is responsible for reading socket
        if (!$this->pendingReadEvent) $this->readTask->taskSchedule();
        $this->pendingReadEvent = true;
    }

    public function amiSocketWriteEmptyEvent()
    {
        # socket empty write buffer event, push write task as it is responsible for writing socket
        if (!$this->pendingWriteEvent) $this->writeTask->taskSchedule();
        $this->pendingWriteEvent = true;
    }

    public function amiSocketStateEvent()
    {
        # socket state changes event, push read task as it is responsible for socket state changes handling
        if (!$this->pendingReadEvent) $this->readTask->taskSchedule();
        $this->pendingReadEvent = true;
    }

    # public API

    public function sendMessage($parameters, $callback, $timeout = null, $queue = 'General')
    {
        if (empty($this->writeQueues) && !$this->pendingWriteEvent) {
            # schedule our write task if it is waiting for message to appear
            $this->writeTask->taskSchedule();
            $this->pendingWriteEvent = true;
        }
        $parameters['ActionID'] = $id = $queue.'-'.$this->nextMessageId; # set action ID as we need it to identify the response
        if (!isset($this->writeQueues[$queue])) $this->writeQueues[$queue] = new \SplDoublyLinkedList();
        $this->writeQueues[$queue]->push(new \ATL\Protocols\Asterisk\AMI\Message($id, $parameters, $callback, $timeout ?? $this->messageTimeout));
        $this->nextMessageId++;
        return $id;
    }

    public function abortMessage($id)
    {
        if (!preg_match('#^(.+)\\-(\\d+)$#S', $id, $m)) return; # split ID into queue name and number
        if (isset($this->waitQueue[$id])) {
            unset($this->waitQueue[$id]);
        } else {
            foreach ($this->writeQueues[$m[1]] ?? [] as $mid => $msg) {
                if ($msg->id == $id) {
                    unset($this->writeQueues[$mid]);
                    break;
                }
            }
        }
    }

    public function disconnect()
    {
        if ($this->amiSocket) {
            $this->amiSocket->disconnect(); # gracefully disconnect and allow read/write tasks to abort
        } else {
            $this->amiError = null;
            $this->taskTerminate(); # just abort the task
        }
    }

    # simplified event handler addition support
    public function onConnect($owner, $callback, $runHandler = true, $addLast = false) { $this->addEventHandler($owner, 'connect', $callback, $runHandler, $addLast); }
    public function onDisconnect($owner, $callback, $runHandler = true, $addLast = false) { $this->addEventHandler($owner, 'disconnect', $callback, $runHandler, $addLast); }
    public function onEvent($owner, $callback, $runHandler = true, $addLast = false) { $this->addEventHandler($owner, 'event', $callback, $runHandler, $addLast); }
    public function onLog($owner, $callback, $runHandler = true, $addLast = false) { $this->addEventHandler($owner, 'log', $callback, $runHandler, $addLast); }
}
