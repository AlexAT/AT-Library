<?php

namespace ATL\Socket;

# Abstract Task-based general polling socket class, StreamBase class extends on it and then Stream/DatagramStream along with TCP/UDP are built on it all, ICMP is built directly on this one
# Abstract Task-based general polling factory class, StreamPollingFactory class extends on it

# New event handlers:
# - eof ($socket, $readBufferSize) - called when remote side disconnects (so no more hasData events will be available), hasData handler is called once more right after eof event if some data is left to be read

interface IPollingSocket extends \ATL\ISocket
{
    public function assignToPollingFactory(/** @var ATL\Socket\PollingFactory */ $factory); # assigns polling socket to polling factory, pass null to return being self-polled

    public function isRunning(); # returns true if our polling socket Task is running
    public function pollForRead(); # schedules Socket Task to poll real socket for read disregarding polling state, mostly used internally

    public function onEOF($owner, $callback, $runHandler = true, $addLast = false);
}

trait TPollingSocket
{
    # general socket setup
    public $socketReadSize = 16000; # this is maximum number of bytes attempted to read from socket in one go, multiply this by socketReadCount and you will get max data retrieved in single task run
    public $socketReadCount = 4; # this is maximum number of reads attempted on socket in one go, along with the above gives us 16000*4 = 64000 bytes read per task invocation
    public $socketMaxDataBuffered = 256000; # this is socket data buffer watermark before socket stops reading data, buffer can jump over this watermark by up to socketReadSize * socketReadCount though
                                            # take care socket may also jump over data buffer watermark if you are using readBytes/readDelimited/readDelimitedBulk because extra buffering may be needed to satisfy these
    public $socketWriteSize = 64000; # socket will attempt to write up to this number of bytes in one go, but if the single write pending is higher it of course would be attempted to be written completely
    public $socketConnectTimeout; # mostly used in daughter classes, only supplementary in the base General class (still acts is EINTR is happening)
    public $socketPollingInterval = 0; # by default, the TaskLoop polling interval is used for socket polling, although you can set this to something else to reduce polling overhead or decrease latency

    # socket polling state
    protected $skReaderWaitsForRead; # this one has dual meaning: first, it requests reader hasData handler to be called, second, it allows us to go over socketMaxDataBuffered for the next read
    protected $skSocketWantsRead;
    protected $skSocketWantsWrite;
    protected $skPollRead;
    protected $skPollWrite;

    # subtask scheduling state
    protected $skScheduled;
    protected $skScheduleTarget;
    
    protected $skEOFSent; # EOF state, can be set to true to prevent double-sending eof event in termination code, hasData may still be sent in duplicate

    # real socket close states
    protected $skSocketReadClosed;
    protected $skSocketWriteClosed;

    # polling factory support
    protected $skPollingFactory;
    protected $skPollingFactoryCallback;
    
    public function __construct()
    {
        # put EOF handler out of optimization, it is called very rarely
        $this->ehEventHandlerUseClosure['eof'] = false;

        parent::__construct();
    }

    # extend Socket::skSocketSetup() handler
    protected function skSocketSetup()
    {
        parent::skSocketSetup();
        
        # general socket initialization
        $this->skPollRead = false;
        $this->skPollWrite = false;
        $this->skScheduled = false;
        $this->skScheduleTarget = null;
        $this->skSocketReadClosed = true;
        $this->skSocketWriteClosed = true;
        $this->skEOFSent = false;
    }

    ########
    # Public Socket API
    # As Socket objects are not intended for extension for anything out of socket handling scope, public methods are not prepended by "socket" prefix

    # override Socket::disconnect() call to provide real disconnecting phase
    # when we call disconnect, we expect socket to stop receiving anything and transition to disconnected state afterwards
    # issuing disconnect on not yet connected sockets will result in immediate socket disconnection and task termination, not even calling disconnect handler
    # override this to provide additional disconnection sequences in child types of sockets
    public function disconnect()
    {
        if ($this->socketState >= $this::STATE_DISCONNECTING) return false; # socket is already disconnecting or disconnected, nothing to do
        if ($this->socketState < $this::STATE_CONNECTED) {
            # socket has not even connected yet, perform task termination
            $this->taskTerminate();
            return parent::disconnect();
        }

        # close writes and transition to disconnecting state to flush writes, real writes are closed later on after flush
        $this->skSetWriteClosed();
        $this->socketState = $this::STATE_DISCONNECTING;

        # reschedule us for write poll reexecution as we may need to really close the write path and it happens down the write drain
        $this->skSocketWantsWrite = true;
        $this->skScheduleMyself();
        return true;
    }

    # override Socket::abort() call to remove us from polling factory and terminate the Task
    # socket abort() is a special call that immediately aborts the running socket operations, calling error and disconnect handlers if they exist (disconnect handler is only called if socket was connected in prior)
    # both read and write buffers are immediately cleared to prevent any more data from being read/written to the socket
    public function abort()
    {
        parent::abort();
        if ($this->skPollingFactory) $this->skPollingFactory->removeFromPolling($this);
        $this->taskTerminate();
    }

    public function isRunning() { return ($this->taskLoop !== null); } # additional flag getter that indicates our socket is running as Task

    # assigns socket to polling factory
    public function assignToPollingFactory(/** @var ATL\Socket\PollingFactory */ $factory)
    {
        if (!$this->skPollingFactoryCallback)
            $this->skPollingFactoryCallback = \ATL\Routines::callableToClosure([$this, 'skPollingFactoryEvent'], true); # create callback reference for polling factory

        if ($this->isConnected()) {
            # we are connected, so we need to reassign us to different polling factory
            if ($this->skPollingFactory !== null)
                $this->skPollingFactory->removeFromPolling($this); # remove from old polling factory

            $this->skPollingFactory = $factory;
            $this->skScheduleMyself(); # reschedule for execution as we may still be waiting on older polling method

            if ($this->skPollingFactory && ($this->skPollRead || $this->skPollWrite)) {
                # we need to be polled, add us to new polling factory
                # take care recursive assignToPollingFactory(null) call may happen here if we are trying to be assigned to dead polling factory, so this must be the last operation
                $this->skPollingFactory->addForPolling(
                    $this, $this->skGetPolledResource(), $this->skPollingFactoryCallback,
                    ($this->skPollRead && (($this->skReadRemaining < $this->socketMaxDataBuffered) || $this->skReaderWaitsForRead)),
                    ($this->skPollWrite && (($this->skCurrentWrite !== null) || !$this->skWriteBuffer->isEmpty())),
                    false
                );
            }
        } else {
            # not yet connected or already disconnected, just remember the factory and that is it
            $this->skPollingFactory = $factory;
        }
    }

    # override Socket::closeRead() to close read path immediately, this may cause writing remote to abort with error and that is normal behavior
    public function closeRead()
    {
        if ($this->skReadOpen) {
            $this->skSocketCloseRead();

            # invoke EOF handlers early
            $this->skEOFSent = true;
            $this->ehInvokeEventHandlers('eof', $this, $this->skReadRemaining); # invoke EOF handler for anyone who monitors socket input state explicitly
        }

        return parent::closeRead();
    }

    # override Socket::closeWrite() to close write path immediately if we do not have anything to write anymore
    # if we are still in the middle of writes, write handler will close real write path later on
    public function closeWrite()
    {
        # reschedule us for write poll reexecution as we need to really close the write path and it happens down the write drain
        $this->skSocketWantsWrite = true;
        $this->skScheduleMyself();

        return parent::closeWrite();
    }

    # override this to provide resource required to be polled by polling factory (i.e. stream with Stream type sockets)
    abstract protected function skGetPolledResource();

    # requests execution of hasData handler on any next socket read and schedules the socket task to perform read ASAP even if polling indicates nothing yet
    # normally does not need to be called externally, but if you are in some tight loop and want to explicitly attempt reading something ASAP, may be used
    public function pollForRead()
    {
        $this->skSocketWantsRead = true; # force attempting reading something even if we are still to be polled
        if (!$this->skReaderWaitsForRead) {
            $this->skReaderWaitsForRead = true;
            $this->skScheduleMyself();
        }
    }

    # override Socket::skReadResultNothing() to poll socket for read explicitly when someone attempts read but there is nothing in the read buffer
    # this performance optimization can backfire if someone reads without checking if data is available, but it is better than not having it at all
    protected function skReadResultNothing()
    {
        $this->pollForRead(); # in case someone attempted to read something but was not able to, request calling the event handler and poll the task for reading
        return parent::skReadResultNothing();
    }

    # override Socket::skReadResultSuccess() to poll socket for read again when read buffer becomes empty after last read
    # this performance optimization has no noticeable drawbacks, it allows to get new data faster if everything has been read
    protected function skReadResultSuccess($result)
    {
        if ($this->skReadBuffer->isEmpty()) $this->pollForRead(); # read buffer went empty, request calling the event handler and poll the task for reading
        return parent::skReadResultSuccess($result);
    }

    ########
    # Internal socket API

    # override Socket::skPreWriteCheck() to start doing real writing again if waiting with empty write buffer or immediately poll for write possibility
    # also disallows writes when flushing reads, that is excessive as writes should be closed, but better safe than sorry
    protected function skPreWriteCheck($line)
    {
        if ($this->socketState >= $this::STATE_FLUSHING_READS) return false; # socket is disconnected and flushing reads, so no more writes are accepted
        if (($this->skCurrentWrite === null) && $this->skWriteBuffer->isEmpty()) {
            # write buffer was empty before this operation, so schedule the task if it was not having anything to write, ignoring any polling
            $this->skSocketWantsWrite = true;
            $this->skScheduleMyself();
        }
        return parent::skPreWriteCheck($line);
    }

    # extend Socket::ehOnEventHandlerAdd(), event handler addition handling, for calling handlers when necessary
    protected function ehOnEventHandlerAdd($owner, $handler, $callback, $runHandler)
    {
        switch ($handler) {
            case 'eof':
            if ($runHandler)
                if (($this->socketState >= $this::STATE_DISCONNECTING) && ($this->socketState < $this::STATE_DISCONNECTED))
                    ($callback)($this, $this->skReadRemaining);
            break;

            default:
            parent::ehOnEventHandlerAdd($owner, $handler, $callback, $runHandler);
            break;
        }
    }

    # these two are overridden to make sure our real state is unclosed as well
    protected function skSetReadOpen()
    {
        if ($this->skSocketReadClosed)
            $this->skSocketReadClosed = false;

        parent::skSetReadOpen();
    }

    protected function skSetWriteOpen()
    {
        if ($this->skSocketWriteClosed)
            $this->skSocketWriteClosed = false;

        parent::skSetWriteOpen();
    }

    ########
    # General socket Task set

    # main socket task that spawns socket handling subtasks in order
    public function main($taskObject)
    {
        # initialize the socket
        $this->skSocketSetup();
        $this->skScheduleTarget = null; # we are not waiting to be scheduled
        yield true;

        # run socket subtasks in order, i.e. we may skip connection/loop if socket is aborted during the operation, task result is checked for continuation
        # overhead? it is just one task over the main one for the whole socket life, and the main task is not executing, waiting for the subtask to terminate, just see how simple it is in return
        # we could avoid creating subtasks with Fiber, but Fiber is PHP 8.1+ only and also is not as performant as Generator, so we resort to spawning subtasks we wait on here, may be changed later
        # this chain of ifs is possible because we get taskResult on subtask yield
        if (yield new \ATL\Task([$this, 'skSocketCreateTask']))
            if (yield new \ATL\Task([$this, 'skSocketConnectTask']))
                if (yield new \ATL\Task([$this, 'skSocketConnectedTask']))
                    yield new \ATL\Task([$this, 'skSocketLoopTask']);
        yield new \ATL\Task([$this, 'skSocketFinishTask']);
    }

    # provide subtask that handles socket initialization, returns true on success, false on failure
    # check StreamBase class for implementation details
    abstract public function skSocketCreateTask($taskObject);

    # asynchronous 'wait until connected' phase subtask handler, returns true on success, false on failure
    # again, while this Task handler may be overridden for complex connection procedures, it is wiser to use abstract skSocketCheckForConnection handler for simple actions
    public function skSocketConnectTask($taskObject)
    {
        # asynchronous connect mechanism
        $this->socketState = $this::STATE_CONNECTING;
        $this->skScheduled = false; # assume we were not scheduled yet
        $this->skScheduleTarget = $taskObject; # set schedule target to ourselves
        yield true;

        $timeout = $this->socketConnectTimeout;
        while (true) {
            $this->skLastErrorNo = $this->skLastError = null;
            if (($result = $this->skSocketCheckForConnection()) !== null) {
                if ($result) {
                    # connected, proceed to the connected phase
                    if (!$this->skReadOpen && !$this->skWriteOpen)
                        throw new \ErrorException("Specific socket code reported connection but did not open read or write");
                    $this->socketState = $this::STATE_CONNECTED;
                    return true;
                } else {
                    # failure
                    return false;
                }
            }

            # still waiting
            $timeout -= yield $this->socketPollingInterval; # relinquish control and poll next time
            if ($timeout < 0) {
                # handle connect timeout, set error and transition to finishing phase
                $this->skLastErrorNo = $this::ERROR_CONNECTION_TIMED_OUT;
                $this->skLastError = 'Connection timed out';
                return false;
            }
        }
    }

    # connection check handler, mandatory to override even if you do not use it and override skSocketConnectTask instead, see Stream and i.e. TCP for implementation details
    # returns true on success, false on failure, null if there is a need to wait (connection task waits for polling interval then)
    # if necessary, late initialization of skStream may be performed there (do not forget to set non-blocking mode yourself)
    # do not forget to enable read/write here by calling skSetReadOpen/skSetWriteOpen, otherwise an ErrorException will be thrown
    abstract public function skSocketCheckForConnection();

    # this subtask acts after socket has been connected, it does not change socket state as normal, override to add your own handling
    # while this Task handler may be overridden for complex post-connection procedures, it is wiser to use abstract skSocketConnected for simple actions
    # also setups socket for first time read polling and calls connect event handler
    public function skSocketConnectedTask($taskObject)
    {
        $this->skScheduleTarget = null; # we are not waiting to be scheduled
        yield true;

        $this->skReaderWaitsForRead = true; # call hasData handler
        $args = $this->skSocketConnected();
        if (is_array($args)) {
            $this->ehInvokeEventHandlers('connect', $this, $this->socketRemoteName, $this->socketLocalName, ...$args);
        } else {
            $this->ehInvokeEventHandlers('connect', $this, $this->socketRemoteName, $this->socketLocalName);
        }
        return true;
    }

    # after connection handler, mandatory to override even if you do not use it and override skSocketConnectedTask instead, see Stream and i.e. TCP for implementation details
    # if it returns array of arguments (array is mandatory), these arguments are passed as additional parameters to connect event handler
    abstract public function skSocketConnected();

    # general socket read/write loop subtask, the most complex of it all
    # do not override this unless absolutely necessary, use skAttemptRead and skAttemptWrite to provide read/write operation handlers, and optionally override skPoll to provide specific self polling operation handler
    public function skSocketLoopTask($taskObject)
    {
        $this->skPollRead = $this->skPollWrite = false; # we avoid polling excessively during realtime runs and read while we can read something and have the buffer to, write until we cannot write anymore
        $this->skScheduled = false; # assume we were not scheduled yet
        $this->skScheduleTarget = $taskObject; # set schedule target to ourselves
        yield true;

        while (true) {
            # set general socket error handler
            $this->skLastErrorNo = $this->skLastError = null;

            # anything to read?
            if (!$this->skSocketReadClosed && !$this->skPollRead || $this->skSocketWantsRead) {
                # attempt reading
                # if the reader waits for read explicitly, allow us to go over the buffer limit (otherwise reads expecting for specific length or content may never end otherwise because of not enough data buffered)
                if (($this->skReadRemaining < $this->socketMaxDataBuffered) || $this->skReaderWaitsForRead) {
                    if (!($this->skPollRead = $this->skAttemptRead())) {
                        if ($this->skPollRead === null) return false; # read indicates error, so close reads and writes and transition to finishing phase
                        if ($this->skReadRemaining < $this->socketMaxDataBuffered) $this->skScheduled = true; # we read our maximum and can read even more, so we actually can repeat the loop without polling
                    }
                }
            }

            # can we write? do we want to?
            if (!$this->skPollWrite || $this->skSocketWantsWrite) {
                # attempt writing
                if (($this->skCurrentWrite !== null) || !$this->skWriteBuffer->isEmpty()) {
                    if (!($this->skPollWrite = $this->skAttemptWrite())) {
                        if ($this->skPollWrite === null) return false; # write indicates error, so transition to finishing phase
                        if (($this->skCurrentWrite !== null) || !$this->skWriteBuffer->isEmpty()) {
                            # we wrote something and still have stuff to write, request the next run in realtime because we may have just hit the write limit
                            $this->skScheduled = true;
                        } else {
                            # nothing to write anymore
                            $this->ehInvokeEventHandlers('writeEmpty', $this); # call our writeEmpty handler if necessary
                            goto nothingToWrite; # yes this is "bad", but duplicating code is much worse
                        }
                    }
                } else {
nothingToWrite: # yes this is "bad", but duplicating code is much worse
                    # we are empty , really close write if requested
                    if (!$this->skWriteOpen)
                        $this->skSocketCloseWrite();

                    # if we were in the disconnect loop and flushed all the data, proceed to finishing phase to disconnect
                    if ($this->socketState == $this::STATE_DISCONNECTING) {
                        $this->skLastErrorNo = $this->skLastError = null;
                        return false;
                    }
                }
            }

            # poll the socket if necessary or add it to polling factory for polling
            $this->skSocketWantsRead = $this->skSocketWantsWrite = false;
            if ($this->skPollWrite || (!$this->skSocketReadClosed && $this->skPollRead)) {
                if ($this->skPollingFactory === null) {
                    if ($this->skPoll()) $this->skScheduled = true; # polling may request realtime run for another polling attempt by returning true, i.e. in case of EINTR
                } else {
                    $this->skPollingFactory->addForPolling(
                        $this, $this->skGetPolledResource(), $this->skPollingFactoryCallback,
                        (!$this->skSocketReadClosed && $this->skPollRead && (($this->skReadRemaining < $this->socketMaxDataBuffered) || $this->skReaderWaitsForRead)),
                        ($this->skPollWrite && (($this->skCurrentWrite !== null) || !$this->skWriteBuffer->isEmpty())),
                        false
                    );
                }
            }

            # relinquish control and request either realtime reexecution or poll some next interval (in case of polling factory, transition to manually scheduled run)
            yield $this->skScheduled ? true : (($this->skPollingFactory !== null) ? false : $this->socketPollingInterval);

            # reset scheduling state after regaining control
            $this->skScheduled = false;
        }
    }

    # call this function after real read complete to reset skReaderWaitsForRead and call hasData handlers if available
    protected function skReadComplete()
    {
        # if we are requested to call our hasData handler on next read, do it
        if ($this->skReaderWaitsForRead) {
            $this->ehInvokeEventHandlers('hasData', $this, $this->skReadRemaining);
            $this->skReaderWaitsForRead = false;
        }
    }

    # provide socket source (PHP socket, stream, whatever) regular read and write functions that attempt reading or writing when necessary
    # see Stream below for byte-based stream socket implementation details, see DatagramStream below for datagram-based stream socket implementation details
    abstract protected function skAttemptRead(); # returns null to finish or on errors, false if more read is available (read more, do not poll), true if nothing is available to read (so enabling polling)
    abstract protected function skAttemptWrite(); # returns null to finish or on errors, false if more write is possible (write more, do not poll), true if nothing could be written (so enabling polling)

    # provide self-polling routine for the socket that polls the socket when there is no polling factory
    # see StreamBase class for implementation details
    abstract protected function skPoll();

    # provide routine to really close read and write sides for the socket if necessary
    # these are best effort routines, so if they fail, it is not reported up the stack anyhow
    # take care these may be called even if already closed, so check and update internal state accordingly
    protected function skSocketCloseRead()
    {
        if (!$this->skSocketReadClosed)
            $this->skSocketReadClosed = true;
    }

    protected function skSocketCloseWrite()
    {
        if (!$this->skSocketWriteClosed)
            $this->skSocketWriteClosed = true;
    }

    # we come here when socket is deemed (doomed) to terminate, either by errors, or by remote disconnection, or by disconnect() call
    # while this Task handler may be overridden for complex disconnection procedures, it is wiser to use abstract skSocketEnd for simple actions
    public function skSocketFinishTask($taskObject)
    {
        # disable polling, especially important for polling factory
        $this->skPollRead = false;
        $this->skPollWrite = false;
        if ($this->skPollingFactory) $this->skPollingFactory->removeFromPolling($this);

        if ($this->socketState >= $this::STATE_DISCONNECTED) return true; # if we are already disconnected, just terminate the task, taskOnTerminate() will do the cleanup honors
        yield true;

        # close virtual and real reads and writes and end the socket
        $this->skSetWriteClosed();
        $this->skSocketCloseWrite();
        $this->skSetReadClosed();
        $this->skSocketCloseRead();
        $this->skSocketEnd();

        # if we were connected, wait all pending socket reads to complete, we must not invoke error and disconnect handlers before reads are complete to preserve sequencing
        $wasConnected = (($this->socketState >= $this::STATE_CONNECTED) && ($this->socketState < $this::STATE_FLUSHING_READS));
        if ($wasConnected) {
            if (!$this->skEOFSent) {
                $this->skEOFSent = true;
                $this->ehInvokeEventHandlers('eof', $this, $this->skReadRemaining); # invoke EOF handler for anyone who monitors socket input state explicitly
            }
            if (!$this->skReadBuffer->isEmpty()) {
                $this->socketState = $this::STATE_FLUSHING_READS;
                $this->skScheduled = false; # assume we were not scheduled yet
                $this->skScheduleTarget = $taskObject; # set schedule target to ourselves
                $this->ehInvokeEventHandlers('hasData', $this, $this->skReadRemaining); # invoke hasData handler one last time so everyone waiting for data may also notice new socket state

                # here we unanimously rely on being rescheduled after last read or zero read optimization, if the optimization is removed, we may hang there
                while (!$this->skReadBuffer->isEmpty()) {
                    yield false;
                    $this->skScheduled = false;
                }
            }
        }

        # call our error and disconnect handlers (error handler only gets called on errors, disconnect handler only gets called if we were connected)
        if ($this->skLastErrorNo !== null) {
            $this->socketState = $this::STATE_ERROR;
            $this->ehInvokeEventHandlers('error', $this, $this->skLastErrorNo, $this->skLastError);
        } else {
            $this->socketState = $this::STATE_DISCONNECTED;
        }
        if ($wasConnected) $this->ehInvokeEventHandlers('disconnect', $this);

        # end us to terminate the Socket task (Socket taskOnTerminate() will actually close the socket, remove us from polling factory, etc.)
        return true;
    }

    # ends the socket in finish task, mandatory to override even if you do not use it and override skSocketFinishTask instead, see Stream and i.e. TCP for implementation details
    # usually will be called twice: from socket finish Task handler and then from overall task termination routine, so make sure you can handle multiple calls to it correctly
    abstract public function skSocketEnd();

    ########
    # General Task and scheduling support code

    public function taskOnTerminate($taskObject)
    {
        # our task is requested to terminate, maybe forcibly, maybe not, close the socket, remove us from polling factory and remove task object reference
        $this->skScheduleTarget = null;
        if ($this->skPollingFactory !== null)
            $this->skPollingFactory->removeFromPolling($this);

        # close reads and writes and end the socket
        $this->skSetWriteClosed();
        $this->skSocketCloseWrite();
        $this->skSetReadClosed();
        $this->skSocketCloseRead();
        $this->skSocketEnd();
    }

    public function skPollingFactoryEvent($wantsRead, $wantsWrite, $wantsException)
    {
        $this->skSocketWantsRead = $wantsRead;
        $this->skSocketWantsWrite = $wantsWrite;
        $this->skScheduleMyself();
    }

    protected function skScheduleMyself()
    {
        if (!$this->skScheduled && ($this->skScheduleTarget !== null)) {
            $this->skScheduleTarget->taskSchedule();
            $this->skScheduled = true;
        }
    }

    # simplified event handler addition support
    public function onEOF($owner, $callback, $runHandler = true, $addLast = false) { $this->addEventHandler($owner, 'eof', $callback, $runHandler, $addLast); }
}

# Base PollingSocket socket class, the weird chain is a way to emulate multiple inheritance in PHP
class PollingSocketPrototype implements \ATL\ITask { use \ATL\TTask; } # bring in Task class first as first level parent
class PollingSocketPrototype2 extends \ATL\Socket\PollingSocketPrototype implements \ATL\ISocket { use \ATL\TSocket; } # bring in base Socket class as second level parent
abstract class PollingSocket extends \ATL\Socket\PollingSocketPrototype2 implements \ATL\Socket\IPollingSocket { use \ATL\Socket\TPollingSocket; }

########
# General type sockets polling factory built around socket_select()

# Before adding created socket as Task to TaskLoop for self-polling, you can add sockets to single compatible polling factory Task which polls all sockets at once
# Adding socket to polling factory converts socket tasks into waiting manually scheduled tasks that are only scheduled when factory detects socket needs to read or write data and can do this
# Factory issues socket_select() on all sockets added to it and calls the corresponding socket wakeup handler to wake up the socket task and perform operations necessary
# If multiple sockets point to the single Task to wake up, polling factory provides the waking task with list of all sockets that needed some action on select()
# Socket tasks can indicate sockets that are going to sleep and want to be polled again and provided to the wakeup event handler on polling events to polling factory
# This is due to socket can continue to run the read/write loop until it exhausts the data to read or the write buffer without need to be polled, and only then becomes polled/scheduled again
# Polling factory does not stay in socket handler paths, it expects sockets to always explicitly request polling when they need it (read and exception polling is always provided, write polling is optional)
# Terminating polling factory Task when it has some polled sockets will induce Socket polling change for all its sockets to become self-polling

interface IPollingFactory { } # nothing here yet, provided for sanity

trait TPollingFactory
{
    public $socketPollingInterval = 0; # by default, the TaskLoop polling interval is used for socket polling, although you can set this to something else to reduce polling overhead or decrease latency
    public $selectErrorRetries = 10; # retry this much before giving up and throwing error exception

    protected $pfSockets = [];
    protected $pfCallbacks = [];
    protected $pfReadPollList = [];
    protected $pfWritePollList = [];
    protected $pfExceptionPollList = [];

    protected $pfScheduled;
    protected $pfErrorCallback;
    protected $pfLastErrorNo;
    protected $pfLastError;
    protected $pfTerminated;
    protected $pfErrorRetriesLeft;

    public function __construct()
    {
        $this->pfTerminated = false;
        $this->pfScheduled = false;
        $this->pfErrorCallback = \ATL\Routines::callableToClosure([$this, 'pfErrorHandler'], true);
    }

    ########
    # Polling socket Task set

    public function main()
    {
        # this is a general poll loop, polls all the sockets we need to poll and sleeps
        yield true;

        $this->pfScheduled = false;
        $this->pfErrorRetriesLeft = $this->selectErrorRetries;
        while (true) {
            $pollResult = (!empty($this->pfCallbacks)) ? $this->pfDoPolling() : false;
            if ($pollResult === false) $this->pfScheduled = false; # we are not going to be scheduled until some external event, reset scheduled flag
            yield ($pollResult ?? $this->socketPollingInterval); # true/false, polling interval when null
        }
    }

    # main polling routine, returns null if it wants to wait till next polling interval, true if it needs to reexecute on next scheduling loop, false if it wants to sleep indefinitely until some event comes
    protected function pfDoPolling()
    {
        $this->pfLastErrorNo = $this->pfLastError = null;
        $wantRead = $this->pfReadPollList;
        $wantWrite = $this->pfWritePollList;
        $wantException = $this->pfExceptionPollList;
        $result = $this->pfDoPoll($wantRead, $wantWrite, $wantException);
        if ($result === false) {
            # select() error handling (we may get EINTR here which we do not consider an error)
            if (!preg_match('#interrupted\\s+system\\s+call#iS', $this->pfLastError ?? '')) {
                # on real error, attempt to retry but terminate with ErrorException if it fails for too much
                $this->pfErrorRetriesLeft--;
                if ($this->pfErrorRetriesLeft == 0) throw new \ErrorException("Polling factory failed to perform polling on assigned sockets");
            }
            return true;
        }
        $this->pfErrorRetriesLeft = $this->selectErrorRetries;

        # do we have anything to kick?
        if (!empty($wantRead) || !empty($wantWrite) || !empty($wantException)) {
            # remove sockets in question from next poll
            if (!empty($wantRead)) $this->pfReadPollList = array_diff_key($this->pfReadPollList, $wantRead);
            if (!empty($wantWrite)) $this->pfWritePollList = array_diff_key($this->pfReadPollList, $wantWrite);
            if (!empty($wantException)) $this->pfExceptionPollList = array_diff_key($this->pfExceptionPollList, $wantException);
            $callbacks = $this->pfCallbacks; # save callbacks locally as we actually may have poll inserted during callback invocation
            $this->pfCallbacks = array_diff_key($this->pfCallbacks, $wantRead, $wantWrite, $wantException);
            $this->pfSockets = array_intersect_key($this->pfSockets, $this->pfCallbacks);

            # kick all the sockets in question, the order is from less probable to more probable to reduce number of isset() and array_diff_key() calls
            if (!empty($wantException)) {
                foreach ($wantException as $id => $resource)
                    ($callbacks[$id])(isset($wantRead[$id]), isset($wantWrite[$id]), true);
                $wantWrite = array_diff_key($wantWrite, $wantException);
                $wantRead = array_diff_key($wantRead, $wantException);
            }
            if (!empty($wantWrite)) {
                foreach ($wantWrite as $id => $resource)
                    ($callbacks[$id])(isset($wantRead[$id]), true, false);
                $wantRead = array_diff_key($wantRead, $wantWrite);
            }
            foreach ($wantRead as $id => $resource)
                ($callbacks[$id])(true, false, false);

            # are we totally empty now?
            if (!empty($this->pfCallbacks)) {
                # still something to poll, sleep for poll interval
                return null;
            } else {
                # nothing to poll, sleep until something is added (we are scheduled then)
                return false;
            }
        } else {
            # nope, nothing, just sleep for poll interval
            return null;
        }
    }

    public function addForPolling($socket, $resource, $callback, $pollRead, $pollWrite, $pollException)
    {
        if (!$pollRead && !$pollWrite && !$pollException) return; # nothing to do

        if (!$this->pfCanPollResource($socket, $resource))
            throw new \ErrorException("Polling factory cannot poll this type of socket, wrong polling factory may have been used");

        # if we are terminating, ask socket to get the hell out of here
        if ($this->pfTerminated) {
            $socket->assignToPollingFactory(null);
            return;
        }

        # if something new is added while we are empty, schedule our task to start polling
        if (!$this->pfScheduled && empty($this->pfCallbacks) && !isset($this->pfCallbacks[$socket->socketId])) {
            $this->pfScheduled = true;
            $this->taskSchedule();
        }

        # add stream and callback for polling
        $this->pfSockets[$socket->socketId] = $socket;
        $this->pfCallbacks[$socket->socketId] = $callback;
        if ($pollRead) $this->pfReadPollList[$socket->socketId] = $resource;
        if ($pollWrite) $this->pfWritePollList[$socket->socketId] = $resource;
        if ($pollException) $this->pfExceptionPollList[$socket->socketId] = $resource;
    }

    public function removeFromPolling($socket)
    {
        unset($this->pfReadPollList[$socket->socketId], $this->pfWritePollList[$socket->socketId], $this->pfExceptionPollList[$socket->socketId], $this->pfCallbacks[$socket->socketId], $this->pfSockets[$socket->socketId]);
    }

    public function isInPolling($socket)
    {
        return isset($this->pfCallbacks[$socket->socketId]);
    }

    public function pfErrorHandler($errno, $errstr)
    {
        $this->pfLastErrorNo = $errno;
        $this->pfLastError = trim($errstr);
        #return true;
        return false; # temporary for debugging
    }

    # provide your routine to check if passed resource is applicable to polling with the polling factory
    # returns true if can be polled, false if cannot be polled, or throw some \ErrorException in case custom exception is necessary
    abstract protected function pfCanPollResource($socket, $resource);

    # provide your polling handler (see StreamPollingFactory for implementation details)
    # from wantRead, wantWrite, wantException arrays, only elements that request specific action must be left
    # return value is false on error, number of elements requesting any events remaining (can be zero if nobody does)
    abstract protected function pfDoPoll(&$wantRead, &$wantWrite, &$wantException);

    public function taskOnTerminate($taskObject)
    {
        # we are terminating, if any sockets are still to be polled, ask them politely to remove themselves, also set pfTerminated flag to do this on any addForPolling() call to remove stale references
        $this->pfTerminated = true;
        foreach ($this->pfSockets as $socket)
            $socket->assignToPollingFactory(null);
    }
}

class PollingFactoryPrototype implements \ATL\ITask { use \ATL\TTask; } # bring in Task class first as first level parent
abstract class PollingFactory extends \ATL\Socket\PollingFactoryPrototype implements \ATL\Socket\IPollingFactory { use \ATL\Socket\TPollingFactory; }
