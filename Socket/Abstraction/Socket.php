<?php

namespace ATL;

# Base socket interface and helper traits, shared among all sockets and socket-like objects
# Implementing it means your object supports general socket functions and events
# General socket class provides only base implementation and base read(), all internal read/write operations, bulk reads, etc. must be implemented by socket code
# General traits can be reused to add specific read and other functions to sockets if support for them is intended

# Supported general event handlers:
# - connect ($socket, $remoteName, $localName, ...) - called when socket is connected, additional socket-dependent parameters can be provided
# - hasData ($socket, $readBufferSize) - called when there is more data available on the socket, called one extra time right after eof handler if some data is left to be read
# - writeEmpty ($socket) - called when write buffer becomes empty
# - readClosed ($socket) - called when read side of the socket closes, buffer may still be read but no new data will be available
# - writeClosed ($socket) - called when write side of the socket is requested to be closed
#                           take care writes may still be flushed so real write side close may be happen a bit later, but no new writes are allowed past this point
#                           if you need to monitor for real write close, check getWriteBufferSize() and wait until write buffer size gets to zero
# - disconnect ($socket) - called when socket completely disconnects, so no more reads/writes are possible
# - error ($socket, $errorCode, $errorText) - called when socket transitions to error state (usually coincides with disconnect by obvious reason)

interface ISocket extends IEventHandlers
{
    # sockets can have your intermediate states in child classes, they will be handled by general handlers as below or above one of these specific states
    # not all states are set by base socket code, it is up to socket implementation discretion which additional states to use and when
    # take care that at disconnected state (DISCONNECTED, ERROR, ABORTED) read buffer may be not empty and may still be read, flush wait is not mandatory
    const STATE_UNINITIALIZED           = 0x0000; # socket operations have not begun
    const STATE_CREATED                 = 0x2000; # socket has created all internal data and started operating
    const STATE_CONNECTING              = 0x4000; # [optional] socket is attempting connection or initializing pre-connected structures
    const STATE_CONNECTED               = 0x6000; # connection succeeded, read and write may be open (or only read or write for half open sockets)
    const STATE_DISCONNECTING           = 0x8000; # [optional] socket is disconnecting, read closed, writes not allowed, flushing write buffer if write is open
    const STATE_FLUSHING_READS          = 0xA000; # [optional] socket disconnected, both read and write are closed, waiting for read buffer to be read
    const STATE_DISCONNECTED            = 0xC000; # socket has flushed write buffer, disconnected and have ceased operations completely
    const STATE_ERROR                   = 0xE000; # socket disconnected and immediately aborted operations due to runtime error
    const STATE_ABORTED                 = 0xF000; # socket was explicitly requested to disconnect and abort all operations, read and write buffers are dropped

    # again, implement additional error codes in child classes
    const ERROR_CONNECTION_TIMED_OUT    = -0xD000;
    const ERROR_CONNECTION_ABORTED      = -0xD001;
    const ERROR_CONNECTION_FAILURE      = -0xD002;
    const ERROR_GENERAL_FAILURE         = -0xD003;

    const DEFAULT_CONNECT_TIMEOUT = 15; # last resort if not specified and there is no INI setting

    # wonder why strings are called chunks? because technically there can be non-string values possible on some specific socket types
    public function read(); # reads next chunk/datagram/message from read buffer, returns null is there is nothing to read
    public function returnRead($chunks); # returns single string passed or multiple strings passed as array to the read buffer
    public function peek(); # returns chunk/datagram/message from read buffer without removing it from the read buffer, or null if there is nothing to read
    public function write($chunk); # writes single string passed to write buffer, returns false if write is not allowed or new number of bytes in the write buffer after writing
    public function disconnect(); # starts socket disconnection process (disallow write -> flush write buffer -> close socket -> flush read buffer -> disconnect)
    public function abort(); # immediately aborts socket operations, closes socket and resets both read and write buffers, calling error and disconnect event handlers
    public function closeRead(); # closes read side of the socket, returns true if closed, false if not open or not possible, take care real socket read side may still remain open, but data will not be read anymore from the real socket
    public function closeWrite(); # closes (requests close of) write side of the socket and disallows writes, return true if closed, false if not open or not possible, take care real socket write side will only be closed after flushing write buffer

    public function isInitialized(); # returns true if socket has been initialized
    public function isBeforeConnect(); # returns true if socket is still to be connected, including unitialized state (so transitioning to connected phase)
    public function isConnectingNow(); # returns true if socket is attempting to establish connection right now (use isBeforeConnect to check for connection)
    public function isConnected(); # returns true if socket is connected and operating
    public function isInDisconnect(); # returns true if socket has started disconnection or already disconnected (so transitioning to disconnected phase)
    public function isDisconnecting(); # returns true if socket is tearing down connection right now (user isDisconnected to check for disconnection)
    public function isDisconnected(); # returns true if socket has disconnected (including in error or aborted phases)
    public function isInError(); # returns true if socket is in error or aborted (socket abort request is also reported as error)
    public function isAborted(); # returns true if socket has been explicitly aborted

    public function getErrorCode(); # returns socket error code or null if no distinguishable error, valid only in error and aborted phases
    public function getErrorText(); # returns socket error code or null if no distinguishable error, valid only in error and aborted phases
    public function getReadBufferSize(); # returns read buffer size in bytes, take care read buffer size may be zero even when read buffer count is non-zero and isReadBufferEmpty() returns false, this happens when there are i.e. empty datagrams present in read buffer
    public function getWriteBufferSize(); # returns write buffer size in bytes, take care write buffer size may be zero even when write buffer count is non-zero and isWriteBufferEmpty() returns false, this happens when there are i.e. objects or empty datagrams present in write buffer
    public function getReadBufferCount(); # returns count of objects (usable for datagram and message based sockets as some datagrams/messages can be zero length) in read buffer
    public function getWriteBufferCount(); # returns count of objects (usable for datagram and message based sockets as some datagrams/messages can be zero length) in write buffer
                                           # take care write buffer count may be zero while write buffer size is non-zero and isWriteBufferEmpty() returns false, this happens when some write is inflight, removed from the buffer
                                           # take care write buffer may contain internal and socket-dependent sequencing objects that will not be sent over the network and are not counted against write size
    public function isReadBufferEmpty(); # returns true if the read buffer is empty
    public function isWriteBufferEmpty(); # returns true if the write buffer is completely empty, including any in-flight write
    public function isReadOpen(); # returns true if read side of the socket is open, meaning new data can still be going to read buffer
    public function isWriteOpen(); # returns true if write side of the socket is open, meaning new data is allowed to be written to write buffer
                                   # take care this does not reflect real socket state, real socket write is only closed after write buffer is flushed, use isWriteBufferEmpty() along to confirm

    public function onConnect($owner, $callback, $runHandler = true, $addLast = false);
    public function onHasData($owner, $callback, $runHandler = true, $addLast = false);
    public function onWriteEmpty($owner, $callback, $runHandler = true, $addLast = false);
    public function onError($owner, $callback, $runHandler = true, $addLast = false); # sets error event handler
    public function onDisconnect($owner, $callback, $runHandler = true, $addLast = false); # sets disconnect event handler
    public function onReadClosed($owner, $callback, $runHandler = true, $addLast = false); # sets readClosed event handler
    public function onWriteClosed($owner, $callback, $runHandler = true, $addLast = false); # sets writeClosed event handler
}

trait TSocket
{
    use TEventHandlers;

    # public socket state
    public $socketId; # contains socket ID
    public $socketState; # contains current socket state
    public $socketAddress; # fill this with the textual representation of address your socket is connected to

    # socket endpoint names, may be or not be filled depending on socket type
    public $socketLocalName; # contains local socket name
    public $socketRemoteName; # contains remote socket name

    # internal socket state
    protected $skErrorCallback;
    protected $skLastErrorNo;
    protected $skLastError;

    # buffers and their state
    /** @var \SplDoublyLinkedList */ protected $skReadBuffer;
    /** @var \SplDoublyLinkedList */ protected $skWriteBuffer;
    protected $skCurrentWrite;
    protected $skReadRemaining;
    protected $skWriteRemaining;
    protected $skReadOpen;
    protected $skWriteOpen;

    # call after your own variables initialization as it calls skSocketSetup() code
    # do not do any dynamic initialization here, just store variables, for dynamic initialization there is skSocketSetup()
    public function __construct()
    {
        # set up unique socket ID
        $this->socketId = \ATL\Routines::getUniqueObjectID($this);

        # put base event handlers out of optimization
        $this->ehEventHandlerUseClosure['connect'] = false;
        $this->ehEventHandlerUseClosure['disconnect'] = false;
        $this->ehEventHandlerUseClosure['error'] = false;

        # set up error handler closure for places it is needed
        $this->skErrorCallback = \ATL\Routines::callableToClosure([$this, 'skErrorHandler'], true);

        # call socket setup code
        $this->skSocketSetup();
    }

    # perform your dynamic socket initialization there, do not forget to call parent method before your code
    # will be ran twice: once on construction, the other time (or multiple times) at each Task startup
    # do not forget to open read and write in the connection process by calling skSetReadOpen/skSetWriteOpen as otherwise they will remain closed
    protected function skSocketSetup()
    {
        $this->socketState = $this::STATE_UNINITIALIZED;
        $this->socketLocalName = null;
        $this->socketRemoteName = null;

        $this->skReadOpen = false;
        $this->skWriteOpen = false;
        $this->skResetReadBuffer();
        $this->skResetWriteBuffer();

        $this->skLastError = null;
        $this->skLastErrorNo = null;
    }

    # initializes read buffer, can be overridden for complex read buffers
    protected function skResetReadBuffer()
    {
        $this->skReadBuffer = new \SplDoublyLinkedList();
        $this->skReadRemaining = 0;
    }

    # initializes write buffer, can be overridden for complex write buffers
    protected function skResetWriteBuffer()
    {
        $this->skWriteBuffer = new \SplDoublyLinkedList();
        $this->skWriteRemaining = 0;
        $this->skCurrentWrite = null;
    }

    ########
    # Public Socket API
    # As Socket objects are not intended for extension for anything out of socket handling scope, public methods are not prepended by "socket" prefix
    # Only basic operations are provided for general socket class, insert other traits to add specific additional functions

    # reads next received data block from the socket, returns null if there is nothing to read anymore
    public function read()
    {
        if ($this->skReadBuffer->isEmpty()) return $this->skReadResultNothing(); # nothing to read yet
        $data = $this->skReadBuffer->shift(); # get next data block queued
        $this->skReadRemaining -= strlen($data);
        return $this->skReadResultSuccess($data); # return the next data block queued
    }

    # returns previously read chunk(s) to the read buffer, returns number of data bytes in the read buffer so you can adjust reads if necessary
    # you can pass either single chunk to return or array of chunks to return, both variants will be handled properly, multiple chunks will be returned so they are to be read back in the same order
    public function returnRead($chunks)
    {
        if (!is_array($chunks)) {
            # single string
            if ($chunks !== '') {
                $this->skReadBuffer->unshift($chunks);
                $this->skReadRemaining += strlen($chunks);
            }
        } else {
            # multiple lines, take care we must return multiple entries in reverse order
            $chunks = array_reverse($chunks);
            foreach ($chunks as $chunk) {
                if ($chunk !== '') {
                    $this->skReadBuffer->unshift($chunk);
                    $this->skReadRemaining += strlen($chunk);
                }
            }
        }
        return $this->skReadRemaining;
    }

    # peeks at next received data block from the socket without removing it from read queue, returns null if there is nothing to peek at
    # looks unnecessary but is complementary and may occasionally be useful for datagram and messaging sockets where you can check whole datagram or message without actually reading it
    public function peek()
    {
        if ($this->skReadBuffer->isEmpty()) return null; # nothing to peek at yet, do not cause occasional polling
        return $this->skReadBuffer->bottom(); # get next data block queued without removing
    }

    # writes data block to the socket, returns number of data bytes in the write buffer after write so you can throttle writes if necessary
    # a special result of false indicates write is not allowed anymore
    public function write($chunk)
    {
        if (!$this->skWriteOpen) return false; # write is closed, so nothing is allowed to be written
        if (($result = $this->skPreWriteCheck($chunk)) !== null) return $result; # perform pre-write check and return special result if necessary
        if ($chunk === '') return $this->skWriteRemaining; # the line is empty so we do not need to push anything
        $this->skWriteBuffer->push($chunk); # push the data to the write buffer if the line is not empty
        $this->skWriteRemaining += strlen($chunk);
        return $this->skWriteRemaining; # return number of bytes in the write buffer
    }

    # when we call disconnect, we expect socket to stop receiving anything and transition to disconnected state afterwards
    # call generic disconnect routine AFTER you have processed your own disconnect sequence and return the result
    # should return false if socket was already disconnected, true otherwise, so duplicate disconnected check
    # do not forget to half-close socket for read if it is necessary at this phase, do not use closeRead() as it may call disconnect() back
    public function disconnect()
    {
        if ($this->socketState >= $this::STATE_DISCONNECTING) return false; # socket is already disconnecting or disconnected, nothing to do

        # perform immediate socket disconnection (not that we can do anything better than that in basic handler)
        $this->socketState = $this::STATE_DISCONNECTED;
        $this->skLastError = null;
        $this->skLastErrorNo = null;
        $this->skResetWriteBuffer();
        $this->skSetReadClosed();
        $this->skSetWriteClosed();
        return true;
    }

    # socket abort() is a special call that immediately aborts the running socket operations, calling error and disconnect handlers if they exist (disconnect handler is only called if socket was connected in prior)
    # both read and write buffers are immediately cleared to prevent any more data from being read/written to the socket
    # call generic abort routine BEFORE you have processed your own abort sequence, but take care about internal state changes
    public function abort()
    {
        $wasConnected = $this->isConnected();
        $this->socketState = $this::STATE_ABORTED;
        $this->skLastErrorNo = $this::ERROR_CONNECTION_ABORTED;
        $this->skLastError = 'Socket operations aborted by explicit abort() call';
        $this->skResetReadBuffer();
        $this->skResetWriteBuffer();
        if ($wasConnected) {
            $this->ehInvokeEventHandlers('error', $this, $this->skLastErrorNo, $this->skLastError);
            $this->skSetReadClosed();
            $this->skSetWriteClosed();
            $this->ehInvokeEventHandlers('disconnect', $this);
        }
    }

    # user callable for half-close operation on the socket, disconnects socket if both read and write are closed
    public function closeRead()
    {
        if (!$this->skReadOpen) return false;
        if (($this->socketState < $this::STATE_CONNECTED) || ($this->socketState >= $this::STATE_DISCONNECTING)) return false; # socket is not connected, already disconnecting or disconnected, nothing to do
        $this->skSetReadClosed();
        if (!$this->skReadOpen && !$this->skWriteOpen) return $this->disconnect();
        return true;
    }

    # user callable for half-close operation on the socket, disconnects socket if both read and write are closed
    public function closeWrite()
    {
        if (!$this->skWriteOpen) return false;
        if (($this->socketState < $this::STATE_CONNECTED) || ($this->socketState >= $this::STATE_DISCONNECTING)) return false; # socket is not connected, already disconnecting or disconnected, nothing to do
        $this->skSetWriteClosed();
        if (!$this->skReadOpen && !$this->skWriteOpen) return $this->disconnect();
        return true;
    }

    public function isInitialized() { return $this->socketState > $this::STATE_UNINITIALIZED; }
    public function isBeforeConnect() { return $this->socketState < $this::STATE_CONNECTED; }
    public function isConnectingNow() { return ($this->socketState >= $this::STATE_CONNECTING) && ($this->socketState < $this::STATE_CONNECTED); }
    public function isConnected() { return ($this->socketState >= $this::STATE_CONNECTED) && ($this->socketState < $this::STATE_DISCONNECTED); }
    public function isInConnectedState() { return ($this->socketState >= $this::STATE_CONNECTED) && ($this->socketState < $this::STATE_DISCONNECTING); }
    public function isInDisconnect() { return ($this->socketState >= $this::STATE_DISCONNECTING); }
    public function isDisconnecting() { return ($this->socketState >= $this::STATE_DISCONNECTING) && ($this->socketState < $this::STATE_DISCONNECTED); }
    public function isDisconnected() { return ($this->socketState >= $this::STATE_DISCONNECTED); }
    public function isInError() { return ($this->socketState >= $this::STATE_ERROR); }
    public function isAborted() { return ($this->socketState >= $this::STATE_ABORTED); }

    public function getErrorCode() { return $this->skLastErrorNo; }
    public function getErrorText() { return $this->skLastError; }
    public function getReadBufferSize() { return $this->skReadRemaining; }
    public function getWriteBufferSize() { return $this->skWriteRemaining; }
    public function getReadBufferCount() { return $this->skReadBuffer->count(); }
    public function getWriteBufferCount() { return $this->skWriteBuffer->count(); }
    public function isReadBufferEmpty() { return $this->skReadBuffer->isEmpty(); }
    public function isWriteBufferEmpty() { return (($this->skCurrentWrite === null) && $this->skWriteBuffer->isEmpty()); }
    public function isReadOpen() { return $this->skReadOpen; }
    public function isWriteOpen() { return $this->skWriteOpen; }

    # simplified event handler addition support
    public function onConnect($owner, $callback, $runHandler = true, $addLast = false) { $this->addEventHandler($owner, 'connect', $callback, $runHandler, $addLast); }
    public function onHasData($owner, $callback, $runHandler = true, $addLast = false) { $this->addEventHandler($owner, 'hasData', $callback, $runHandler, $addLast); }
    public function onWriteEmpty($owner, $callback, $runHandler = true, $addLast = false) { $this->addEventHandler($owner, 'writeEmpty', $callback, $runHandler, $addLast); }
    public function onReadClosed($owner, $callback, $runHandler = true, $addLast = false) { $this->addEventHandler($owner, 'error', $callback, $runHandler, $addLast); }
    public function onWriteClosed($owner, $callback, $runHandler = true, $addLast = false) { $this->addEventHandler($owner, 'error', $callback, $runHandler, $addLast); }
    public function onError($owner, $callback, $runHandler = true, $addLast = false) { $this->addEventHandler($owner, 'error', $callback, $runHandler, $addLast); }
    public function onDisconnect($owner, $callback, $runHandler = true, $addLast = false) { $this->addEventHandler($owner, 'disconnect', $callback, $runHandler, $addLast); }

    ########
    # Internal socket API

    # read results, override if you need some specific handling (i.e. enabling polling) from your read code
    # always call parent readResult return and do not return result yourself because some capabilities also use these
    protected function skReadResultNothing()
    {
        return null;
    }

    protected function skReadResultSuccess($result)
    {
        return $result;
    }

    # supplements readResult*(), returns null if it is safe to write data normally from write(), otherwise returns result to be returned to write() caller
    # does nothing in the base class, to be overridden from daughter classes as necessary (i.e. to provide socket polling, etc.)
    protected function skPreWriteCheck($line) { }

    # event handler addition handling
    # do not forget to call from your own extensions to this when you cannot match any handlers of yours
    protected function ehOnEventHandlerAdd($owner, $handler, $callback, $runHandler)
    {
        switch ($handler) {
            case 'connect':
            if ($runHandler)
                if (($this->socketState >= $this::STATE_CONNECTED) && ($this->socketState < $this::STATE_DISCONNECTED))
                    ($callback)($this, $this->socketRemoteName, $this->socketLocalName);
            break;

            case 'hasData':
            if ($runHandler)
                if (($this->socketState >= $this::STATE_CONNECTED) && ($this->socketState < $this::STATE_DISCONNECTED))
                    if (!$this->skReadBuffer->isEmpty())
                        ($callback)($this, $this->skReadRemaining);
            break;

            case 'writeEmpty':
            if ($runHandler)
                if (($this->socketState >= $this::STATE_CONNECTED) && ($this->socketState < $this::STATE_DISCONNECTED))
                    if (($this->skCurrentWrite === null) && $this->skWriteBuffer->isEmpty())
                        ($callback)($this);
            break;

            case 'readClosed':
            if (($this->socketState >= $this::STATE_CONNECTED) && ($this->socketState < $this::STATE_DISCONNECTED))
                if (!$this->skReadOpen)
                    ($callback)($this);
            break;

            case 'writeClosed':
            if (($this->socketState >= $this::STATE_CONNECTED) && ($this->socketState < $this::STATE_DISCONNECTED))
                if (!$this->skWriteOpen)
                    ($callback)($this);
            break;

            case 'disconnect':
            if ($runHandler)
                if (($this->socketState >= $this::STATE_DISCONNECTED) && ($this->socketState <= $this::STATE_ABORTED))
                    ($callback)($this);
            break;

            case 'error':
            if ($runHandler)
                if (($this->socketState >= $this::STATE_ERROR) && ($this->socketState <= $this::STATE_ABORTED))
                    ($callback)($this, $this->skLastErrorNo, $this->skLastError);
            break;
        }
    }

    # internal functions to set read/write state to open (to be used on connection only) or closed
    # take care these may be called even if the state is already open/closed, so do not forget to pre-verify
    protected function skSetReadOpen()
    {
        if (!$this->skReadOpen)
            $this->skReadOpen = true;
    }

    protected function skSetWriteOpen()
    {
        if (!$this->skWriteOpen)
            $this->skWriteOpen = true;
    }

    protected function skSetReadClosed()
    {
        if ($this->skReadOpen) {
            $this->skReadOpen = false;
            $this->ehInvokeEventHandlers('readClosed', $this);
        }
    }

    protected function skSetWriteClosed()
    {
        if ($this->skWriteOpen) {
            $this->skWriteOpen = false;
            $this->ehInvokeEventHandlers('writeClosed', $this);
        }
    }

    # PHP error handler
    public function skErrorHandler($errno, $errstr)
    {
        $this->skLastErrorNo = $errno;
        $this->skLastError = trim($errstr);
        return defined('ATL_DEBUG_SOCKETS') ? false : true; # handle global debugging define
    }
}

# Base Socket class
class Socket implements \ATL\ISocket { use \ATL\TSocket; }

# Exception classes

class SocketException extends \Exception { }
