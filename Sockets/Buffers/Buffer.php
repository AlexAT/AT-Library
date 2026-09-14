<?php

namespace ATL\Sockets;

# Generalized socket buffer that can be used for i.e. datagram/message data, both for reads and writes
# Provides external interface and events for reading data from the buffer and adding data to the buffer, checking and manipulating buffer state, etc.
# Also provides internal interface for i.e. handling bytewise length changes, state changes, etc.
# It is specialized into friendly read/write buffers further down the stack

# The socket buffer data and state transitions are all event-based, connecting socket and remote (i.e. poller) API sets to react on buffer changes mutually
# Event setters are lowerCamelCased on<event> calls, i.e. onHasData() for hasData event, onEmpty() for empty event, etc.
# The events are defined as follows:
# - hasData($buffer)
#     invoked when some data is added to the empty buffer
#     read buffer example: remote added new data to the buffer, socket catches the event and invokes its own hasData event
#     write buffer example: socket added new data to the buffer, remote catches the event and pushes polling to write out the data
# - newData($buffer)
#     invoked when any data is added to the buffer
#     read buffer example: remote added new data to the buffer, socket catches the event and invokes its own newData event
#     write buffer example: normally there is no need to use nor handle this event for write buffers
# - empty($buffer)
#     invoked when the last piece of data is removed from the empty buffer
#     read buffer example: socket consumed all the data in the buffer, remote catches the event and pushes polling to read more data
#     write buffer example: remote added new data to the buffer, socket catches the event and invokes its own writeEmpty event
# - lowWatermark($buffer)
#     invoked when buffer data size falls down to or below the low watermark defined
#     read buffer example: socket consumed enough data from the buffer, remote catches the event and pushes polling to read more data
#     write buffer example: remote added enough data to the buffer, socket catches the event and invokes its own writeLowWatermark event
# - highWatermark($buffer)
#     invoked once when buffer data size grows up to or over the high watermark defined
#     read buffer example: remote added enough data to the buffer, socket catches the event and invokes its own readHighWatermark event
#     write buffer example: socket added enough data to the buffer, remote catches the event and pushes polling to write out the data, socket also catches the event and invokes its own writeHighWatermark event
# - full($buffer)
#     invoked once when buffer data size grows up to or over the maximum size defined
#     read buffer example: remote added enough data to the buffer, socket catches the event and invokes its own readFull event, remote also catches the event and reduces amount of read polling
#     write buffer example: socket added enough data to the buffer, remote catches the event and pushes polling to write out the data, socket also catches the event and invokes its own writeFull event
# - pendingData($buffer)
#     invoked once when real maximum buffer size is extended by some operation that needs more data to be read
#     read buffer example: socket tries to read data from the full buffer, but i.e. delimited or byte read needs more data, socket sends pendingData event, remote catches the event and pushes polling to read more data
#     write buffer examplt: normally there is no need to use nor handle this event for write buffers
# - opening($buffer)
#     invoked when socket asks to open the buffer, designed to make complex remotes open their read/write sides of the socket then confirm it to the socket
#     read buffer example: socket requests to open the buffer, remote catches the event and starts read polling, then requests back to open the buffer
#     write buffer example: socket requests to open the buffer, remote catches the event and starts write polling, then requests back to open the buffer
# - open($buffer)
#     invoked when remote confirms opening the buffer, this normally happens after remote connects and starts polling on corresponding side of the socket
#     read buffer example: remote requests to open the buffer, socket catches the event and invokes its own connected event when all buffers are open
#     write buffer example: remote requests to open the buffer, socket catches the event and invokes its own connected event when all buffers are open
# - closing($buffer)
#     invoked when socket asks to close the buffer, designed to provide a way for remote to reach some closure on the socket state (i.e. flush all outstanding write data) before closing corresponding side of the socket
#     read buffer example: socket requests to close the buffer, remote catches the event and stops read polling, then closes read side of the socket (and the whole socket if both sides are closed), then requests back to close the buffer
#     write buffer example: socket requests to close the buffer, remote catches the event and starts write flushing, socket also catches the event and invokes its own writeClosing event
#                           when all outstanding writes are flushed, remote stops write polling, closes write side of the socket (and the whole socket if both sides are closed) and requests back to close the buffer
# - closed($buffer), this normally happens after remote stops polling for and closes corresponding side of the socket
#     invoked when remote asks to close the buffer, this normally happens after remote closed corresponding end of the socket and polling, also can be triggered by remote without notice if i.e. remote host closed some side of the socket or error happens
#     read buffer example: remote requests to close the buffer, socket catches the event and invokes its own readClosed event, then disconnected event when all buffers are closed
#     write buffer example: remote requests to close the buffer, socket catches the event and invokes its own writeClosed event, then disconnected event when all buffers are closed
# - aborted($buffer)
#     as this event is normally accompanied by closed events, socket side normally needs no specific handling for this event, the abort operation itself is usually error-related and so forced abort of operation on all sides is necessary
#     read buffer example: buffer abort is requested, remote catches the event and stops read polling, also closing the read side of the socket (and the whole socket if both sides are closed)
#     write buffer example: buffer abort is requested, remote catches the event and stops write polling, also closing the write side of the socket (and the whole socket if both sides are closed)
# - dataRead($buffer)
#     invoked when the buffer is operating in event read mode, sending each data block received via this event
#     on enabling event read mode, all buffered data blocks will be sent via this event if any handler exists, otherwise the data will be left buffered
#     on adding new handler for this event, if running handler is allowed and event read mode is enabled, all buffered data blocks will be sent via this event

interface IBuffer
{
    ########
    # internal buffer states
    # the socket open transition graph is: UNINITIALIZED => (socket open) => OPENING => (opening event) => (remote open) => OPEN => (open event)
    # the remote open transition graph: UNINITIALIZED/OPENING => (remote open) => OPEN => (open event)
    # the socket close transition graph is: OPEN => (socket close) => CLOSING => (closing event) => (remote close) => CLOSED => (closed event)
    # the remote close transition graph is: OPEN/CLOSING => (remote close) => CLOSED => (closed event)
    # the abort transition graph is: ANYTHING => (abort) => ABORTED => (closed event if was not CLOSED or ABORTED) => (aborted event if was not ABORTED)
    # take care the state does not anyhow affect how the generalized buffer behaves, check it yourself where necessary in the specialized code
    # socket states are validated internally, trying to transition from any unexpected phase will result in exception
    const SKB_STATE_UNINITIALIZED = 0x0000;
    const SKB_STATE_OPENING = 0x4000;
    const SKB_STATE_OPEN = 0x8000;
    const SKB_STATE_CLOSING = 0xC000;
    const SKB_STATE_CLOSED = 0xF000;
    const SKB_STATE_ABORTED = 0xF800;
    
    const SKB_SIZE_OPERATION_POP_LEFT = 0;
    const SKB_SIZE_OPERATION_POP_RIGHT = 1;
    const SKB_SIZE_OPERATION_ADD_LEFT = 2;
    const SKB_SIZE_OPERATION_ADD_RIGHT = 3;
    const SKB_SIZE_OPERATION_POP_OTHER = 4;
    const SKB_SIZE_OPERATION_ADD_OTHER = 5;
    const SKB_SIZE_OPERATION_CLEAR = 6;
    const SKB_SIZE_OPERATION_OTHER = 7;
    
    public function __construct($id, $socket);

    public function skbClear($silent = false);
    public function skbPopLeft($silent = false, $noSizeUpdate = false);
    public function skbPopRight($silent = false, $noSizeUpdate = false);
    public function skbAddLeft($data, $silent = false, $noSizeUpdate = false);
    public function skbAddRight($data, $silent = false, $noSizeUpdate = false);
    public function skbPeekLeft();
    public function skbPeekRight();

    public function skbSocketOpen();
    public function skbRemoteOpen();
    public function skbSocketClose();
    public function skbRemoteClose();
    public function skbAbort();

    public function getCount();
    public function getSize();
    public function isEmpty();
    public function isFull();
    public function isAboveLowWatermark();
    public function isBelowHighWatermark();

    public function isOpening();
    public function isOpen();
    public function isClosing();
    public function isClosed();
    public function isAborted();
    public function isActive();

    public function setEventReadMode($enabled = false);

    public function onHasData($owner, $callback = null, $silent = false);
    public function onNewData($owner, $callback = null, $silent = false);
    public function onEmpty($owner, $handler = null, $silent = false);
    public function onLowWatermark($owner, $callback = null, $silent = false);
    public function onHighWatermark($owner, $callback = null, $silent = false);
    public function onFull($owner, $callback = null, $silent = false);
    public function onOpening($owner, $callback = null, $silent = false);
    public function onOpen($owner, $callback = null, $silent = false);
    public function onClosing($owner, $callback = null, $silent = false);
    public function onClosed($owner, $callback = null, $silent = false);
    public function onAborted($owner, $callback = null, $silent = false);
    public function onDataRead($owner, $callback = null, $silent = false);
}

trait TBuffer
{
    ########
    # take care every ID and volatile property of the socket buffer is public so weird manupulations are possible when necessary
    # this is to avoid using getters/setters for everything obscure specific socket types need to check, but modifying is discouraged
    
    /** @var \ATL\Sockets\Socket */ public $skbSocket; # the socket associated
    public $skbId; # buffer ID for socket handlers
    public $splId; # object ID for external handlers
    public $skbState;

    /** @var \SplDoublyLinkedList */ public $skbData; # here all the data is buffered
    public $skbCount; # take care this is always in datagrams/messages
    public $skbSize; # take care here this follows skbCount, but specializations may use different size factor

    ########
    # non-volatile settings, make sure these all are set properly if need to alter before buffer is requested to open

    # take care for generalized buffer this all is in datagrams/messages but may be different for specializations
    public $skbMaxSize = 256; # indicates maximum buffer size at or above which the full event is sent
    public $skbRealMaxSize; # indicates temporary extension if the buffer size, does not affect events but remote needs to account for it when adding data, resets on reaching low watermark
    public $skbLowWatermark = 64; # indicates maximum buffer size at or below which the lowWatermark event is sent
    public $skbHighWatermark = 192; # indicates maximum buffer size at or above which the highWatermark event is sent

    ########
    # volatile settings, these may be changed on the fly by their setters, can be read but never ever manipulate these directly

    public $skbEventReadMode = false; # set to true to make buffer send every data piece added by remote via dataRead events and not add it for real, no empty/hasData/watermark events are generated in this case

    ########
    # implementation
    
    public function __construct($id, $socket)
    {
        $this->skbId = $id;
        $this->splId = spl_object_id($this);
        $this->skbSocket = $socket;
        $this->skbState = $this::SKB_STATE_UNINITIALIZED;
        $this->skbInitialize();
        $this->skbClear(true);
    }
    
    protected function skbInitialize()
    {
        # specializations can place any additional internal initialization here that needs to happen before skbClear() call
    }
    
    ########
    # intrinsic data manipulation API for both ends of the buffer as it all depends on which side we are on
    # no peeking or bulk operations are provided, if these are necessary, they are to be implemented separately
    # for inflight data processing, use silent operations and call skbDataAdded/skbDataRemoved with only the actual data added/removed
    # bulk operations can benefit from doing it all with noSizeUpdate = true then calling skbSizeRemoved/skbSizeAdded directly

    public function skbClear($silent = false)
    {
        $this->skbData = new \SplDoublyLinkedList();
        $this->skbDataCleared($silent);
    }
    
    public function skbPopLeft($silent = false, $noSizeUpdate = false)
    {
        if ($this->skbCount == 0) return false; # take care null is considered to be a valid readable value
        $data = $this->skbData->shift();
        if (!$noSizeUpdate) $this->skbDataRemoved($data, $silent, $this::SKB_OPERATION_POP_LEFT);
        return $data;
    }

    public function skbPopRight($silent = false, $noSizeUpdate = false)
    {
        if ($this->skbCount == 0) return false; # take care null is considered to be a valid readable value
        $data = $this->skbData->pop();
        if (!$noSizeUpdate) $this->skbDataRemoved($data, $silent, $this::SKB_OPERATION_POP_RIGHT);
        return $data;
    }
    
    public function skbAddLeft($data, $silent = false, $noSizeUpdate = false)
    {
        $this->skbData->unshift($data);
        if (!$noSizeUpdate) $this->skbDataAdded($data, $silent, $this::SKB_OPERATION_ADD_LEFT);
        return true;
    }


    public function skbAddRight($data, $silent = false, $noSizeUpdate = false)
    {
        $this->skbData->push($data);
        if (!$noSizeUpdate) $this->skbDataAdded($data, $silent, $this::SKB_OPERATION_ADD_RIGHT);
        return true;
    }

    public function skbPeekLeft()
    {
        if ($this->skbCount == 0) return false; # take care null is considered to be a valid readable value
        return $this->skbData->top();
    }

    public function skbPeekRight()
    {
        if ($this->skbCount == 0) return false; # take care null is considered to be a valid readable value
        return $this->skbData->bottom();
    }
    
    ########
    # internal data handlers (override for specifics)
    
    protected function skbDataCleared($silent)
    {
        $this->skbSizeRemoved($this->skbCount, $this->skbSize, $silent, $this::SKB_SIZE_OPERATION_CLEAR);
        $this->skbRealMaxSize = $this->skbMaxSize;
        if ($silent) return;
    }
    
    protected function skbDataRemoved($data, $silent, $operationHint = null)
    {
        $this->skbSizeRemoved(1, $this->skbGetDataSize($data), $silent, $operationHint);
    }
    
    protected function skbSizeRemoved($count, $size, $silent, $operationHint = null)
    {
        $oldSize = $this->skbSize;
        $this->skbCount -= $count;
        $this->skbSize -= $size;
        if ($this->skbCount < 0) throw new \ErrorException("Socket internal data count went lower than zero");
        if ($this->skbSize < 0) throw new \ErrorException("Socket internal size count went lower than zero");

        if (($this->skbSize <= $this->skbLowWatermark) && ($oldSize > $this->skbLowWatermark)) {
            $this->skbRealMaxSize = $this->skbMaxSize; # reset maximum buffer size on reaching low watermark
            if (!$silent) $this->ehInvokeEventHandlers('lowWatermark', $this);
        }
        
        if ($silent) return;
        if ($this->skbCount == 0) $this->ehInvokeEventHandlers('empty', $this);
    }

    protected function skbDataAdded($data, $silent, $operationHint = null)
    {
        $this->skbSizeAdded(1, $this->skbGetDataSize($data), $silent, $operationHint);
    }
    
    protected function skbSizeAdded($count, $size, $silent, $operationHint = null)
    {
        $oldSize = $this->skbSize;
        $this->skbCount += $count;
        $this->skbSize += $this->skbGetDataSize($data);

        if ($this->skbEventReadMode) return $this->skbSendEventModeData();
        if ($silent) return;

        if ($this->skbCount == 1) $this->ehInvokeEventHandlers('hasData', $this);

        $this->ehInvokeEventHandlers('newData', $this);

        if (($this->skbSize >= $this->skbHighWatermark) && ($oldSize < $this->skbHighWatermark))
            $this->ehInvokeEventHandlers('highWatermark', $this);

        if (($this->skbSize >= $this->skbMaxSize) && ($oldSize < $this->skbMaxSize))
            $this->ehInvokeEventHandlers('full', $this);
    }
    
    # specializations may use their own data sizing here
    protected function skbGetDataSize($data)
    {
        return 1; # just count of buffer elements following skbCount
    }
    
    protected function skbExtendMaxSize($newMaxSize, $silent)
    {
        if ($newMaxSize > $this->skbRealMaxSize) {
            $this->skbRealMaxSize = $newMaxSize;
            if (!$silent) $this->skbRequestMoreData();
        }
    }
    
    protected function skbRequestMoreData()
    {
        $this->ehInvokeEventHandlers('pendingData', $this);
    }

    ########
    # public data state API (override for specifics)
    # take care this state API must be consistent with the internal representation of all the state

    public function getCount()
    {
        return $this->skbCount;
    }

    # the difference from getCount() is this may be some i.e. byte length in specific implementations
    public function getSize()
    {
        return $this->skbSize;
    }
    
    public function isEmpty()
    {
        return ($this->skbCount == 0);
    }
    
    public function isFull()
    {
        return ($this->skbSize >= $this->skbMaxSize);
    }

    public function isAboveLowWatermark()
    {
        return ($this->skbSize > $this->skbLowWatermark);
    }
    
    public function isBelowHighWatermark()
    {
        return ($this->skbSize < $this->skbHighWatermark);
    }

    ########
    # intrinsic state manipulation API
    
    public function skbSocketOpen()
    {
        if ($this->skbState >= $this::SKB_STATE_OPENING) throw new \ErrorException('Socket attempted to open socket buffer that is already initialized');
        $this->skbState = $this::SKB_STATE_OPENING;
        $this->ehInvokeEventHandlers('opening', $this);
    }
    
    public function skbRemoteOpen()
    {
        if ($this->skbState >= $this::SKB_STATE_OPEN) throw new \ErrorException('Remote attempted to open socket buffer that is already open');
        $this->skbState = $this::SKB_STATE_OPEN;
        $this->ehInvokeEventHandlers('open', $this);
    }
    
    public function skbSocketClose()
    {
        if ($this->skbState < $this::SKB_STATE_OPEN) throw new \ErrorException('Socket attempted to close socket buffer that is not yet open');
        if ($this->skbState >= $this::SKB_STATE_CLOSING) throw new \ErrorException('Socket attempted to close socket buffer that is already closing or closed');
        $this->skbState = $this::SKB_STATE_CLOSING;
        $this->ehInvokeEventHandlers('closing', $this);
    }
    
    public function skbRemoteClose()
    {
        if ($this->skbState < $this::SKB_STATE_OPEN) throw new \ErrorException('Remote attempted to close socket buffer that is not yet open');
        if ($this->skbState >= $this::SKB_STATE_CLOSED) throw new \ErrorException('Remote attempted to close socket buffer that is already closed');
        $this->skbState = $this::SKB_STATE_CLOSED;
        $this->ehInvokeEventHandlers('closed', $this);
    }

    public function skbAbort()
    {
        if ($this->skbState >= $this::SKB_STATE_ABORTED) return;
        $sendClose = ($this->skbState < $this::SKB_STATE_CLOSED);
        $this->skbState = $this::SKB_STATE_ABORTED;
        if ($sendClose) $this->ehInvokeEventHandlers('closed', $this);
        $this->ehInvokeEventHandlers('aborted', $this);
    }
       
    ########
    # public socket buffer state API (override for specifics)
    # take care this state API must be consistent with the internal representation of all the state
    
    public function isOpening()
    {
        return (($this->skbState >= $this::SKB_STATE_OPENING) && ($this->skbState < $this::SKB_STATE_OPEN));
    }
    
    public function isOpen()
    {
        return (($this->skbState >= $this::SKB_STATE_OPEN) && ($this->skbState < $this::SKB_STATE_CLOSED));
    }
    
    public function isClosing()
    {
        return (($this->skbState >= $this::SKB_STATE_CLOSING) && ($this->skbState < $this::SKB_STATE_CLOSED));
    }
    
    public function isClosed()
    {
        return ($this->skbState >= $this::SKB_STATE_CLOSED);
    }
    
    public function isAborted()
    {
        return ($this->skbState >= $this::SKB_STATE_ABORTED);
    }
    
    public function isActive()
    {
        return (($this->skbState >= $this::SKB_STATE_OPENING) && ($this->skbState < $this::SKB_STATE_CLOSED));
    }
    
    ########
    # volatile settings and their helpers
    
    public function setEventReadMode($enabled = false)
    {
        $this->skbEventReadMode = $enabled;
        
        # if enabled, send all accumulated data via event handler if any, otherwise leave the data intact until any handler is added
        if ($enabled) $this->skbSendEventModeData();
    }
    
    protected function skbSendEventModeData()
    {
        if (isset($this->ehEventHandlers['dataRead']))
            while (($data = $this->skbPop(true)) !== false)
                $this->ehInvokeEventHandlers('dataRead', $this, $data);
    }

    ########
    # public event handler registration API and its helpers

    public function onHasData($owner, $callback, $silent = false) { $this->addEventHandler($owner, 'hasData', $callback, !$silent); }
    public function onEmpty($owner, $handler, $silent = false) { $this->addEventHandler($owner, 'empty', $callback, !$silent); }
    public function onLowWatermark($owner, $callback, $silent = false) { $this->addEventHandler($owner, 'lowWatermark', $callback, !$silent); }
    public function onHighWatermark($owner, $callback, $silent = false) { $this->addEventHandler($owner, 'highWatermark', $callback, !$silent); }
    public function onFull($owner, $callback, $silent = false) { $this->addEventHandler($owner, 'full', $callback, !$silent); }
    public function onOpening($owner, $callback, $silent = false) { $this->addEventHandler($owner, 'opening', $callback, !$silent); }
    public function onOpen($owner, $callback, $silent = false) { $this->addEventHandler($owner, 'open', $callback, !$silent); }
    public function onClosing($owner, $callback, $silent = false) { $this->addEventHandler($owner, 'closing', $callback, !$silent); }
    public function onClosed($owner, $callback, $silent = false) { $this->addEventHandler($owner, 'closed', $callback, !$silent); }
    public function onAborted($owner, $callback, $silent = false) { $this->addEventHandler($owner, 'aborted', $callback, !$silent); }
    public function onDataRead($owner, $callback, $silent = false) { $this->addEventHandler($owner, 'dataRead', $callback, !$silent); }

    protected function ehOnEventHandlerAdd($owner, $handler, $callback, $runHandler)
    {
        switch ($handler) {
            case 'hasData':
            if (!$runHandler) break;
            if (!$this->isEmpty()) $callback($this);
            break;

            case 'empty':
            if (!$runHandler) break;
            if ($this->isEmpty()) $callback($this);
            break;

            case 'lowWatermark':
            if (!$runHandler) break;
            if (!$this->isAboveLowWatermark()) $callback($this);
            break;

            case 'highWatermark':
            if (!$runHandler) break;
            if ($this->isBelowHighWatermark()) $callback($this);
            break;

            case 'full':
            if (!$runHandler) break;
            if ($this->isFull()) $callback($this);
            break;
            
            case 'opening':
            if (!$runHandler) break;
            if ($this->isOpening()) $callback($this);
            break;

            case 'open':
            if (!$runHandler) break;
            if ($this->isOpen()) $callback($this);
            break;

            case 'closing':
            if (!$runHandler) break;
            if ($this->isClosing()) $callback($this);
            break;

            case 'closed':
            if (!$runHandler) break;
            if ($this->isClosed()) $callback($this);
            break;

            case 'aborted':
            if (!$runHandler) break;
            if ($this->isAborted()) $callback($this);
            break;
            
            case 'dataRead':
            if (!$runHandler) break;

            # when the first dataRead handler is added, all accumulated data needs to be sent to it
            if ($this->skbEventReadMode && !$this->isEmpty()) $this->skbSendEventModeData();
            break;
        }
    }
}

class Buffer implements \ATL\Sockets\IBuffer, \ATL\IEventHandlers
{
    use \ATL\Sockets\TBuffer;
    use \ATL\TEventHandlers;
}

########
# here the very base buffer classes go

