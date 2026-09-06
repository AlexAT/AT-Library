<?php

namespace ATL\Socket;

# Easy semi-synchronous socket handling classes
# These Tasks provide you with a way to avoid socket polling and status callback handling, providing you with a way to work with sockets in a linear fashion
# The requirement is that you work on a single read socket in your connection handling thread, then you can just sequence (WaitOnConnect)-WaitOnRead-writing-WaitOnDisconnect and error checking
# Working on multiple sockets at once is of course totally possible, but you will have to use i.e. WaitOn/WaitOnAny mechanics to combine waits, moving a bit further from the linear code beauty

# These Tasks are generally not to be used with auto-reconnecting sockets if underlying data protocol is not stateless and/or needs any (re)initialization
# This is because these classes do not handle errors or disconnections occuring right after obtaining result, and may return success even if socket is already in error or disconnected state
# For non-reconnecting sockets this does not pose any issue because one of the next waits (when i.e. there is no more data to read) will result in error with the actual state
# In case of reconnecting sockets, this error state may be lost due to automatic reconnections and thus you may not notice any error occured on the socket inbetween waits
# If you still intend to use these Tasks with auto-reconnecting sockets, do not forget to check socket state after each wait to handle possible socket disconnections
# Also, auto-reconnecting sockets do not drop the data from read dispatch queue on disconnections, so you have to do this manually using flushReadQueue() on error detection where necessary

class SocketPrimitive extends \ATL\Task
{
    protected $socket;
    protected $timeout;
    protected $pendingEvent = false;

    public function taskOnTerminate($taskObject)
    {
        $this->socket->removeEventHandlers($this); # remove our event handlers
    }

    public function socketEvent()
    {
        if (!$this->pendingEvent) $this->taskSchedule();
        $this->pendingEvent = true;
    }

    public function socketEventOverride()
    {
        if (!$this->pendingEvent) $this->taskSchedule();
        $this->pendingEvent = true;
        return false;
    }
}

# Task to wait for connection of outbound sockets
# Yield it from your task or add the task and wait on it to wait on specific outbound socket to be connected or fail into error state, with optional connect timeout
# In case of success, taskResult will contain true (so using === true is the best way to check for success/error)
# In case of error, taskResult will contain socket error data array of [0 => code, 1 => text]
# In case waiting socket to connect times out, taskResult will contain false
# In case this task is forcibly terminated, taskResult will contain null
# If the socket is already connected at the time of issuance, task will immediately succeed as normal
# If the socket is already disconnected at the time of issuance, or connects and immediately disconnects, task will still immediately succeed as normal

class WaitForConnect extends \ATL\Socket\SocketPrimitive
{
    public function __construct($socket, $timeout = null)
    {
        $this->socket = $socket;
        $this->timeout = $timeout ?? ($socket->socketConnectTimeout + 1); # by default, allow socket to time out itself
    }

    public function main()
    {
        # assign socket state handlers and start waiting if not yet completed
        $this->pendingEvent = false;
        $this->socket->addEventHandlers($this, [
            'connect' => [$this, 'socketEvent'],
            'disconnect' => [$this, 'socketEvent'],
            'error' => [$this, 'socketEvent'],
        ]);
        if (!$this->pendingEvent) yield $this->timeout; # wait till scheduled by any of the handlers
        if ($this->socket->isInError()) return [$this->socket->getErrorCode(), $this->socket->getErrorText()];
        return $this->pendingEvent ? true : false;
    }
}

# Task to disconnect a socket
# Issues a disconnect() call to the socket, transitioning socket to flush/disconnect mode, with optional timeout to wait for the flush/disconnect operation to complete
# After socket flushes all the data, it terminates and then this task will result in success
# In case of success, taskResult will contain true (so using === true is the best way to check for success/error)
# In case or errors during socket flush operations, taskResult will contain socket error data array of [0 => code, 1 => text]
# In case waiting socket to flush and disconnect times out, taskResult will contain false
# You have three options of handling timeouts: waiting again (maybe indefinitely), leaving the socket running (it will probably disconnect eventually), or issuing abort() call to socket to immediately terminate it
# In case this task is forcibly terminated, taskResult will contain null
# If the socket is already in error or disconnected state at the time of issuance, task will immediately terminate as well, returning success or socket error state
# Calling disconnect() on a socket may be avoided by setting additional callDisconnect parameter to false (may be used to wait on sockets already disconnecting)

class Disconnect extends \ATL\Socket\SocketPrimitive
{
    protected $callDisconnect;

    public function __construct($socket, $timeout, $callDisconnect = true)
    {
        $this->socket = $socket;
        $this->timeout = $timeout;
        $this->callDisconnect = $callDisconnect;
    }

    public function main()
    {
        # assign socket state handlers and start waiting if not yet completed
        $this->pendingEvent = false;
        $this->socket->addEventHandlers($this, [
            'disconnect' => [$this, 'socketEvent'],
            'error' => [$this, 'socketEvent'],
        ]);
        if ($this->callDisconnect && !$this->socket->isInDisconnect()) $this->socket->disconnect(); # if socket is not yet in disconnect, perform disconnect() call
        if (!$this->pendingEvent) yield $this->timeout; # wait till scheduled by any of the handlers
        if ($this->socket->isInError()) return [$this->socket->getErrorCode(), $this->socket->getErrorText()];
        return $this->pendingEvent ? true : false;
    }
}

# Task to read from socket
# Yield it from your task or add the task and wait on it to wait on specific socket to have data to read, with optional read timeout
# Take care this is potentially a high overhead mechanism, but as it reads all available data from the socket in bulk, the overhead depends on read block
# In case of success, taskResult will contain true (so using === true is the best way to check for success/error) and this task readData property will contain array with the data pieces read from socket or null
# This task will still try to read all pending data from socket and succeed even if socket transitions to error state or disconnects during read operations, so it will always succeed if there was any data read
# In case socket gracefully disconnects without reading anything but with no error, taskResult will be true, but readData property will be null, make sure you handle this
# There can be a rare case where socket indicates some data to read but nothing can be read from socket, in this corner case task will wait until something is actually read from the socket or timeout
# In case or errors during socket read operations, taskResult will contain socket error data array of [0 => code, 1 => text]
# In case waiting on socket for data to appear times out, taskResult will contain false
# In case this task is forcibly terminated, taskResult will contain null
# Take care readData property will be null on errors, timeouts and disconnects, on success it will contain an *array* of data elements read from the socket in bulk, even if only a single element is read
# In case asString is true, readData property will contain combined string read instead of array of data elements, take care some socket types may be incompatible with asString = true
# You can limit number of data elements to read by setting maxElements argument, but take care the less elements are to be read at once, the higher the overhead
# If you get more data from socket than you need to handle, you can return the remaining data back to socket dispatch queue using returnRead() socket call
# This task tries to use socket readBulk() method if it exists, if readBulk() does not exist it resorts to issuing multiple read() calls
# This task does not propagate hasData event to any other handlers, effectively hiding events for presence of any data in socket until it terminates, but it propagates error and disconnect events
# You can restart this Task as much as needed without creating a new task for each socket read

class Read extends \ATL\Socket\SocketPrimitive
{
    public $readData;

    protected $readAsString;
    protected $maxReadCount;
    protected $socketHasReadBulk;

    public function __construct($socket, $timeout, $asString = false, $maxReadCount = PHP_INT_MAX)
    {
        $this->readData = null;
        $this->socket = $socket;
        $this->timeout = $timeout;
        $this->socketHasReadBulk = method_exists($this->socket, 'readBulk');
        $this->readAsString = $asString;
        $this->maxReadCount = $maxReadCount;
    }

    public function main()
    {
        # assign socket state handlers and start waiting if not yet completed
        $this->readData = null;
        $this->socket->addEventHandlers($this, [
            'hasData' => [$this, 'socketEventOverride'],
            'readClosed' => [$this, 'socketEvent'],
            'eof' => [$this, 'socketEvent'],
            'disconnect' => [$this, 'socketEvent'],
            'error' => [$this, 'socketEvent'],
        ]);

        # as we can somehow have nothing to read even if socket reports hasData, handle this in a wait loop
        $timeout = $this->timeout;
        do {
            $this->pendingEvent = false;

            # attempt to read something from the socket
            if ($this->socketHasReadBulk) {
                $this->readData = $this->socket->readBulk($this->readAsString, $this->maxReadCount);
                if ($this->readData !== null) return true; # yay, we read something
            } else {
                $this->readData = [];
                while ((count($this->readData) < $this->maxReadCount) && (($element = $this->socket->read()) !== null))
                    $this->readData[] = $element;
                if (!empty($this->readData)) {
                    if ($this->readAsString) $this->readData = implode('', $this->readData);
                    return true;
                }
                $this->readData = null;
            }
            
            # has read side been closed or has socket disconnected?
            if (!$this->socket->isReadOpen() || $this->socket->isDisconnected()) {
                if ($this->socket->isInError()) return [$this->socket->getErrorCode(), $this->socket->getErrorText()];
                return true;
            }

            # wait till scheduled by any of the handlers, decrementing timeout left
            if (!$this->pendingEvent)
                $timeout -= ($timePassed = yield $timeout);
        } while ($timeout > 0);

        # we only get there if we timed out and have not read anything at all
        if ($this->socket->isInError()) return [$this->socket->getErrorCode(), $this->socket->getErrorText()];
        return false;
    }
}

# Task to read given number of bytes from socket
# Completely similar to read task in general behavior, but has the following differences:
# - This task requires socket readBytes() support and will result in error when used on i.e. datagram or message sockets lacking readBytes()
# - readData property will always contain a single string with exact number of bytes (partial reads are impossible) or null on graceful disconnect or failures
# - It will read no more than bytesCount bytes from the socket and will not succeed until bytesCount bytes are available
# - This task will always use *exact* readBytes() mode to read enough bytes from the socket even if there is less data available, this can cause socket buffer to grow beyond socketMaxDataBuffered limit up to read limit or slightly more
# - There are two timeout arguments, first is readTimeout that causes task to fail if nothing at all can be read for the interval, second is fullTimeout that causes task to fail if not enough bytes are read during the interval
# - Take care that after this task completes (successfully or not), socket may still contain some data remaining to be read (last sequence of bytes shorter than bytesCount)

class ReadBytes extends \ATL\Socket\SocketPrimitive
{
    public $readData;

    protected $bytesCount;
    protected $readTimeout;

    public function __construct($socket, $bytesCount, $readTimeout, $fullTimeout = PHP_INT_MAX)
    {
        $this->readData = null;
        $this->socket = $socket;
        $this->bytesCount = $bytesCount;
        $this->readTimeout = $readTimeout;
        $this->timeout = $fullTimeout;
    }

    public function main()
    {
        # assign socket state handlers and start waiting if not yet completed
        $this->readData = null;
        $this->socket->addEventHandlers($this, [
            'hasData' => [$this, 'socketEventOverride'],
            'readClosed' => [$this, 'socketEvent'],
            'eof' => [$this, 'socketEvent'],
            'disconnect' => [$this, 'socketEvent'],
            'error' => [$this, 'socketEvent'],
        ]);

        # as we can somehow have nothing to read even if socket reports hasData, handle this in a wait loop
        $timeout = $this->timeout;
        $readTimeout = $this->readTimeout;
        do {
            $this->pendingEvent = false;

            # attempt to read enough bytes from the socket
            if (($this->readData = $this->socket->readBytes($this->bytesCount, true)) !== null) return true;

            # has read side been closed or has socket disconnected?
            if (!$this->socket->isReadOpen() || $this->socket->isDisconnected()) {
                if ($this->socket->isInError()) return [$this->socket->getErrorCode(), $this->socket->getErrorText()];
                return true;
            }

            # wait till scheduled by any of the handlers, decrementing timeout left
            if (!$this->pendingEvent)
                $timeout -= ($timePassed = yield $this->readTimeout);
        } while (($timeout > 0) && ($this->pendingEvent)); # pendingEvent = false means socket had no events during readTimeout, so nothing was read at all

        # we only get there if we have not read anything at all
        if ($this->socket->isInError()) return [$this->socket->getErrorCode(), $this->socket->getErrorText()];
        return false;
    }
}

# Task to read delimited data from socket
# Completely similar to read() task in general behavior, but has the following differences:
# - This task required socket setDelimiter() plus readDelimited() or readDelimitedBulk() support and will result in error when used on i.e. datagram or message sockets lacking these
# - readData will always contain an array of strings read from socket or null on graceful disconnect or failures, make sure you handle graceful disconnect properly
# - It is up to you to detect if lines read to readData are cut by maxLineLength or do really contain a delimiter
# - This task changes socket data delimiter via setDelimiter() to the delimiter supplied and does not restore it after return, you should track delimiters usage from your own code
# - Uses readDelimitedBulk() routines to optimize reads, but will resort to multiple readDelimited() calls if readDelimitedBulk() is not available
# - There is maxLineLength parameter that is passed to readDelimited() / readDelimitedBulk() methods
# - There are two timeout arguments, first is readTimeout that causes task to fail if nothing at all can be read for the interval, second is fullTimeout that causes task to fail if no delimited data is read during the interval
# - Take care that after this task completes (successfully or not), socket may still contain some data remaining to be read (last non-delimited line shorter or as long as maxLineLength)

class ReadDelimited extends \ATL\Socket\SocketPrimitive
{
    public $readData;

    protected $delimiter;
    protected $maxLineLength;
    protected $maxReadCount;
    protected $readTimeout;
    protected $socketHasReadDelimitedBulk;

    public function __construct($socket, $delimiter, $readTimeout, $fullTimeout = PHP_INT_MAX, $maxLineLength = PHP_INT_MAX, $maxReadCount = PHP_INT_MAX)
    {
        $this->readData = null;
        $this->socket = $socket;
        $this->delimiter = $delimiter;
        $this->maxLineLength = $maxLineLength;
        $this->socketHasReadDelimitedBulk = method_exists($this->socket, 'readBulk');
        $this->readTimeout = $readTimeout;
        $this->timeout = $fullTimeout;
        $this->maxReadCount = $maxReadCount;
    }

    public function main()
    {
        # assign socket state handlers and start waiting if not yet completed
        $this->readData = null;
        $this->socket->addEventHandlers($this, [
            'hasData' => [$this, 'socketEventOverride'],
            'readClosed' => [$this, 'socketEvent'],
            'eof' => [$this, 'socketEvent'],
            'disconnect' => [$this, 'socketEvent'],
            'error' => [$this, 'socketEvent'],
        ]);
        $this->socket->setDelimiter($this->delimiter); # set socket read delimiter

        # as we can somehow have nothing to read even if socket reports hasData, handle this in a wait loop
        $timeout = $this->timeout;
        do {
            $this->pendingEvent = false;

            # attempt to read something from the socket
            if ($this->socketHasReadDelimitedBulk) {
                $this->readData = $this->socket->readDelimitedBulk($this->maxLineLength, $this->maxReadCount);
                if ($this->readData !== null) return true; # yay, we read something
            } else {
                $this->readData = [];
                while ((count($this->readData) < $this->maxReadCount) && (($element = $this->socket->readDelimited($this->maxLineLength)) !== null))
                    $this->readData[] = $element;
                if (!empty($this->readData)) return true;
                $this->readData = null;
            }

            # has read side been closed or has socket disconnected?
            if (!$this->socket->isReadOpen() || $this->socket->isDisconnected()) {
                if ($this->socket->isInError()) return [$this->socket->getErrorCode(), $this->socket->getErrorText()];
                return true;
            }

            # wait till scheduled by any of the handlers, decrementing timeout left
            if (!$this->pendingEvent)
                $timeout -= ($timePassed = yield $this->readTimeout);
        } while (($timeout > 0) && ($this->pendingEvent)); # pendingEvent = false means socket had no events during readTimeout, so nothing was read at all

        # we only get there if we have not read anything at all
        if ($this->socket->isInError()) return [$this->socket->getErrorCode(), $this->socket->getErrorText()];
        return false;
    }
}

# Task to wait until all writes to the socket are flushed down (until socket buffer is empty) towards the operating system
# In case of success (write buffer is empty and socket is not in error state), taskResult will contain true (so using === true is the best way to check for success/error)
# In case socket catches any error during wait, taskResult will contain socket error data array of [0 => code, 1 => text]
# In case waiting for socket to flush times out (no errors, but still some data in the write buffer), taskResult will contain false
# In case this task is forcibly terminated, taskResult will contain null
# In case there is no data in socket write buffer at the time of issuance and socket is in no error state, the task will immediately succeed as normal
# In case some writeEmpty event handler adds data to the socket, this task will still complete as normal as it monitors socket state *before* event handler adds the data

class WaitForWriteFlush extends \ATL\Socket\SocketPrimitive
{
    public function __construct($socket, $timeout)
    {
        $this->socket = $socket;
        $this->timeout = $timeout;
    }

    public function main()
    {
        # assign socket state handlers and start waiting if not yet completed
        $this->pendingEvent = false;
        $this->socket->addEventHandlers($this, [
            'writeEmpty' => [$this, 'socketEvent'],
            'error' => [$this, 'socketEvent'],
        ]);
        if (!$this->pendingEvent) yield $this->timeout; # wait till scheduled by any of the handlers
        if ($this->socket->isInError()) return [$this->socket->getErrorCode(), $this->socket->getErrorText()];
        return $this->pendingEvent ? true : false;
    }
}
