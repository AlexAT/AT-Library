<?php

namespace ATL\Sockets;

# Generalized socket buffer that can be used for i.e. datagram/message data, both for reads and writes
# Provides external interface and events for reading data from the buffer and adding data to the buffer, checking and manipulating buffer state, etc.
# Also provides internal interface for i.e. handling bytewise length changes, state changes, etc.
# It is specialized into friendly read/write buffers further down the stack

# The socket buffer data and state transitions are all event-based, connecting socket and transport (i.e. poller) API sets to react on buffer changes mutually
# Event setters are lowerCamelCased on<event> calls, i.e. onHasData() for hasData event, onEmpty() for empty event, etc.
# Take care that events must be set using class constants, as socket events are frequent and need to be optimized for performance

# The events are defined as follows:
# - hasData($buffer)
#     invoked when some data is added to the empty buffer
#     read buffer example: transport added new data to the buffer, socket catches the event and invokes its own hasData event
#     write buffer example: socket added new data to the buffer, transport catches the event and pushes polling to write out the data
# - newData($buffer)
#     invoked when any data is added to the buffer
#     read buffer example: transport added new data to the buffer, socket catches the event and invokes its own newData event
#     write buffer example: normally there is no need to use nor handle this event for write buffers
# - empty($buffer)
#     invoked when the last piece of data is removed from the empty buffer
#     read buffer example: socket consumed all the data in the buffer, transport catches the event and pushes polling to read more data, also handled by socket when in disconnecting state to detect when reads are flushed
#     write buffer example: transport added new data to the buffer, socket catches the event and invokes its own writeEmpty event
# - lowWatermark($buffer)
#     invoked when buffer data size falls down to or below the low watermark defined
#     read buffer example: socket consumed enough data from the buffer, transport catches the event and pushes polling to read more data
#     write buffer example: transport added enough data to the buffer, socket catches the event and invokes its own writeLowWatermark event
# - highWatermark($buffer)
#     invoked once when buffer data size grows up to or over the high watermark defined
#     read buffer example: transport added enough data to the buffer, socket catches the event and invokes its own readHighWatermark event
#     write buffer example: socket added enough data to the buffer, transport catches the event and pushes polling to write out the data, socket also catches the event and invokes its own writeHighWatermark event
# - full($buffer)
#     invoked once when buffer data size grows up to or over the maximum size defined
#     read buffer example: transport added enough data to the buffer, socket catches the event and invokes its own readFull event, transport also catches the event and reduces amount of read polling
#     write buffer example: socket added enough data to the buffer, transport catches the event and pushes polling to write out the data, socket also catches the event and invokes its own writeFull event
# - pendingData($buffer)
#     invoked once when real maximum buffer size is extended by some operation that needs more data to be read
#     read buffer example: socket tries to read data from the full buffer, but i.e. delimited or byte read needs more data, transport catches the event and pushes polling to read more data
#     write buffer example: normally there is no need to use nor handle this event for write buffers
# - opening($buffer)
#     invoked when socket asks to open the buffer, designed to make complex transports open their read/write sides of the socket then confirm it to the socket
#     read buffer example: socket requests to open the buffer, transport catches the event and starts read polling, then requests back to open the buffer
#     write buffer example: socket requests to open the buffer, transport catches the event and starts write polling, then requests back to open the buffer
# - open($buffer)
#     invoked when transport confirms opening the buffer, this normally happens after transport connects and starts polling on corresponding side of the socket
#     read buffer example: transport requests to open the buffer, socket catches the event and invokes its own connected event when all buffers are open
#     write buffer example: transport requests to open the buffer, socket catches the event and invokes its own connected event when all buffers are open
# - closing($buffer)
#     invoked when socket asks to close the buffer, designed to provide a way for transport to reach some closure on the socket state (i.e. flush all outstanding write data) before closing corresponding side of the socket
#     read buffer example: socket requests to close the buffer, transport catches the event and stops read polling, then closes read side of the socket (and the whole socket if both sides are closed), then requests back to close the buffer
#     write buffer example: socket requests to close the buffer, transport catches the event and starts write flushing, socket also catches the event and invokes its own writeClosing event
#                           when all outstanding writes are flushed, transport stops write polling, closes write side of the socket (and the whole socket if both sides are closed) and requests back to close the buffer
# - closed($buffer), this normally happens after transport stops polling for and closes corresponding side of the socket
#     invoked when transport asks to close the buffer, this normally happens after transport closed corresponding end of the socket and polling, also can be triggered by transport without notice if i.e. transport host closed some side of the socket or error happens
#     read buffer example: transport requests to close the buffer, socket catches the event and invokes its own readClosed event, then disconnected event when all buffers are closed
#     write buffer example: transport requests to close the buffer, socket catches the event and invokes its own writeClosed event, then disconnected event when all buffers are closed
# - error($buffer, $errorCode, $errorSubCode, $errorText)
#     this event is invoked when socket buffer encounters an error during the operations, but it exists only to provide error code/message and should not cause immediate socket aborts
#     if the error is fatal, this event is to be accompanied with closed/abort events by using skbAbort(), normally all handled by socket side only to register buffer errors
#     code is normally one of SKB_ERROR constants, while subcode can be i.e. operating system error code encountered, also take care the base buffer implementation provides no error messaging
# - aborted($buffer)
#     as this event is normally accompanied by closed events, socket side normally needs no specific handling for this event, the abort operation itself is usually error-related and so forced abort of operation on all sides is necessary
#     read buffer example: buffer abort is requested, transport catches the event and stops read polling, also closing the read side of the socket (and the whole socket if both sides are closed)
#     write buffer example: buffer abort is requested, transport catches the event and stops write polling, also closing the write side of the socket (and the whole socket if both sides are closed)
# - dataRead($buffer, $data)
#     invoked when the buffer is operating in event read mode, sending each data block received via this event
#     on enabling event read mode, all buffered data blocks will be sent via this event if any handler exists, otherwise the data will be left buffered
#     on adding new handler for this event, if running handler is allowed and event read mode is enabled, all buffered data blocks will be sent via this event

interface IBuffer
{
    ########
    # general buffer states
    # the socket open transition graph is: CREATED => (socket open) => OPENING => (opening event) => (transport open) => OPEN => (open event)
    # the transport open transition graph: CREATED/OPENING => (transport open) => OPEN => (open event)
    # the socket close transition graph is: OPEN => (socket close) => CLOSING => (closing event) => (transport close) => CLOSED => (closed event)
    # the transport close transition graph is: OPEN/CLOSING => (transport close) => CLOSED => (closed event)
    # the abort transition graph is: ANYTHING => (abort) => ABORTED => (closed event if was not CLOSED or ABORTED) => (aborted event if was not ABORTED)
    # take care the state does not anyhow affect how the generalized buffer behaves, check it yourself where necessary in the specialized code
    # socket states are validated internally, trying to transition from any unexpected phase will result in exception
    const SKB_STATE_UNINITIALIZED           = 0x0000; # buffer operations have not begun
    const SKB_STATE_CREATED                 = 0x1000; # buffer has created all internal data and is ready to start operating
    const SKB_STATE_OPENING                 = 0x4000; # socket requested channel to be open, waiting for transport to confirm opening channel, no data flow is possible
    const SKB_STATE_OPEN                    = 0x8000; # both socket and transport opened the channel and the data is ready to flow, all operations are available
    const SKB_STATE_CLOSING                 = 0xC000; # socket requested channel to close, waiting for transport to optionally flush the remaining data and disconnect, only transport side reads are possible
    const SKB_STATE_CLOSED                  = 0xF000; # socket requested channel to close and transport confirmed the channel is closed, only socket side reads are possible
    const SKB_STATE_ABORTED                 = 0xF800; # buffer aborted either due to error or socket/transport requesting it to abort, only socket side reads are possible

    ########
    # size operation codes
    const SKB_SIZE_OPERATION_POP_LEFT       = 0x0000; # [data removed] ...[the rest]
    const SKB_SIZE_OPERATION_POP_RIGHT      = 0x0001; # ...[the rest] [data removed]
    const SKB_SIZE_OPERATION_ADD_LEFT       = 0x0002; # [data added] ...[the rest]
    const SKB_SIZE_OPERATION_ADD_RIGHT      = 0x0003; # ...[the rest] [data added]
    const SKB_SIZE_OPERATION_POP_OTHER      = 0x0004; # ...[the rest] [data removed somewhere] ...[the rest]
    const SKB_SIZE_OPERATION_ADD_OTHER      = 0x0005; # ...[the rest] [data added somewhere] ...[the rest]
    const SKB_SIZE_OPERATION_CLEAR          = 0x0006; # [buffer empty]
    const SKB_SIZE_OPERATION_OTHER          = 0x0007; # other kinds of operations

    ########
    # event IDs
    const SKB_EVENT_HAS_DATA                = 0x0000; # hasData($buffer), sent when empty buffer receives new data
    const SKB_EVENT_NEW_DATA                = 0x0001; # newData($buffer), sent when buffer receives any new data
    const SKB_EVENT_EMPTY                   = 0x0002; # empty($buffer), sent when buffer becomes empty
    const SKB_EVENT_LOW_WATERMARK           = 0x0003; # lowWatermark($buffer), sent when buffer size drops below high watermark
    const SKB_EVENT_HIGH_WATERMARK          = 0x0004; # highWatermark($buffer), sent when buffer size raises to or above high watermark
    const SKB_EVENT_FULL                    = 0x0005; # full($buffer), sent when buffer size reaches or raises above maximum size
    const SKB_EVENT_PENDING_DATA            = 0x0006; # pendingData($buffer), sent when buffer forcibly requests more data

    const SKB_EVENT_OPENING                 = 0x0010; # opening($buffer), sent when buffer transitions to opening state
    const SKB_EVENT_OPEN                    = 0x0011; # open($buffer), sent when buffer transitions to open state
    const SKB_EVENT_CLOSING                 = 0x0012; # closing($buffer), sent when buffer transitions to closing state
    const SKB_EVENT_CLOSED                  = 0x0013; # closed($buffer), sent when buffer transitions to closed state
    const SKB_EVENT_ERROR                   = 0x0014; # error($buffer, $code, $subCode, $text, $fatal), sent when buffer encounters some operational error
    const SKB_EVENT_ABORTED                 = 0x0015; # aborted($buffer), sent when buffer operations are aborted

    const SKB_EVENT_DATA_READ               = 0x0020; # dataRead($buffer, $data), sent in event read mode for each data block existing in the buffer

    ########
    # error IDs
    # take care base implementation is basically a placeholder, it has no possibility of such errors occurence and sends none
    # this is to be potentially extended with child classes own error codes, to be handled in sockets knowing about specific error conditions
    # 0001-1FFF range is for socket errors, 2000-3FFF range is for socket buffer errors, F000-FFFF range is for system errors
    const SKB_ERROR_INTERNAL                = 0x2000; # for errors inside the socket buffer handler itself, if any
    const SKB_ERROR_OS_ERROR                = 0xFFFE; # for errors that stem from OS call errors, subcode should specify OS error code returned
    const SKB_ERROR_UNSPECIFIED             = 0xFFFF; # for anything else unclassified

    ########
    # buffer parameters IDs, these start with 0x2000 and can be passed to sockets directly to affect default buffer setup
    # when adding customized user-specific parameters to daughter classes, start them with 0xA000 and keep in 0xA000-0xBFFF range
    const SKB_PARAM_MAX_SIZE                = 0x2000; # maximum count (for object/message buffers) or byte size (for byte sized buffers) of the buffer it will attempt to maintain, take care this value is not absolute and may be exceeded or not honored at all
    const SKB_PARAM_LOW_WATERMARK           = 0x2001; # lowWatermark event is sent when the amount of data held in the buffer falls below this value (same units as max size)
    const SKB_PARAM_HIGH_WATERMARK          = 0x2002; # highWatermark event is sent when the amount of data held in the buffer gets to or above this value (same units as max size), take care this value may be automatcially adjusted temporarily
    const SKB_PARAM_EXTEND_SIZE_BY          = 0x2003; # the amount to temporarily extend buffer size and watermark by when buffer forcibly requests more data (same units as max size)

    ########
    # buffer interface, take care constructor signature is commented as it may be overridden completely and does not need to follow the interface

    # public function __construct($id, $socket, $parameters = []);
    public function skbInitialize($id, $socket, $parameters = []); # basic buffer setup and initialization sequence
    public function skbClear($silent = false); # clears the buffer contents completely

    public function skbPopLeft($silent = false, $noSizeUpdate = false); # removes and returns the data from the left of the buffer (FIFO out side, typical data read call)
    public function skbPopRight($silent = false, $noSizeUpdate = false); # removes and returns the data from the right of the buffer (FIFO in side, rarely usable call)
    public function skbAddLeft($data, $silent = false, $noSizeUpdate = false); # adds data to the left of the buffer (FIFO out side, typical data return call)
    public function skbAddRight($data, $silent = false, $noSizeUpdate = false); # adds data to the right of the buffer (FIFO in side, typical data add call)
    public function skbPeekLeft(); # returns data at the left of the buffer without removing (FIFO out side, typical data peek call)
    public function skbPeekRight(); # returns data at the right of the buffer without removing (FIFO in side, rarely usable call)

    public function skbSocketOpen(); # called by socket to request opening the buffer operations
    public function skbTransportOpen(); # called by transport to confirm buffer operations can be opened
    public function skbSocketClose(); # called by socket to request gracefully closing the buffer operations
    public function skbTransportClose(); # called by transport to confirm buffer operations can be closed
    public function skbAbort($silent = false); # called by either side to immediately abort all socket operations

    public function getCount(); # returns count of data blocks held in the buffer
    public function getSize(); # returs size of all the data held in the buffer
    public function hasData(); # returns true when buffer is not empty
    public function isEmpty(); # returns true when buffer is empty
    public function isFull(); # returns true when size of the data held in the buffer is at or above buffer maximum size
    public function isAboveLowWatermark(); # returns true when size of the data held in the buffer is at or above low watermark
    public function isBelowHighWatermark(); # returns true when size of the data held in the buffer is below low watermark

    public function isBeforeOpen(); # returns true when buffer operations have not yet been opened (state < opening)
    public function isOpening(); # returns true when buffer is opening operations (opening <= state < open)
    public function isOpen(); # returns true when buffer is open (open <= state < closing)
    public function isWriteable(); # returns true when buffer can accept socket side writes (open <= state < closed)
    public function isClosing(); # returns true when buffer is closing operations (closing <= state < closed)
    public function isClosed(); # returns true when buffer operations are closed (state >= closed)
    public function isAborted(); # returns true when buffer operations were aborted (state >= aborted)
    public function isActive(); # returns true when buffer is already open or readying to open/close (opening <= state < closed)

    public function setEventReadMode($enabled = false); # sets event read mode flag (event read mode calls onDataRead() for every data block received)

    #########
    # event setters

    public function onHasData($owner, $callback, $silent = false);
    public function onNewData($owner, $callback, $silent = false);
    public function onEmpty($owner, $callback, $silent = false);
    public function onLowWatermark($owner, $callback, $silent = false);
    public function onHighWatermark($owner, $callback, $silent = false);
    public function onFull($owner, $callback, $silent = false);
    public function onPendingData($owner, $callback, $silent = false);
    public function onOpening($owner, $callback, $silent = false);
    public function onOpen($owner, $callback, $silent = false);
    public function onClosing($owner, $callback, $silent = false);
    public function onClosed($owner, $callback, $silent = false);
    public function onError($owner, $callback, $silent = false);
    public function onAborted($owner, $callback, $silent = false);
    public function onDataRead($owner, $callback, $silent = false);

    ########
    # internal interface
    # as PHP does not allow to declare protected API in the interfaces but it is important to follow its signature, we just place it here commented
/*
    # protected function skbInitializeDefaults(); # called from skbInitialize(), sets all buffer parameters to default values
    # protected function skbReadParameters($parameters); # called from skbInitialize(), reads user-supplied parameters and adjusts buffer parameters to them

    # protected function skbDataCleared($silent); # called when the buffer is cleared
    # protected function skbDataRemoved($data, $silent, $operationHint = null); # called when some data block is removed from the buffer
    # protected function skbSizeRemoved($count, $size, $silent, $operationHint = null); # called with the size of the data removed from the buffer
    # protected function skbDataAdded($data, $silent, $operationHint = null); # called when some data block is added to the buffer
    # protected function skbSizeAdded($count, $size, $silent, $operationHint = null); # called internally with the size of the data added to the buffer
    # protected function skbGetDataSize($data); # calculates size of the data block added to the buffer or removed from the buffer
    # protected function skbExtendMaxSize($targetSize, $targetHighWatermark = null, $silent = false); # temporarily extends the maximum buffer size to facilitate read requirements
    # protected function skbRequestMoreData(); # called when buffer needs to request more data from the transport

    protected function skbSendEventModeData(); # unconditionally sends all data in the buffer to the dataRead event handlers
    protected function skbSendError($code, $subCode, $text, $fatal = true); # sends error message to error event handlers
*/
}

trait TBuffer
{
    ########
    # take care every ID and volatile property of the socket buffer is public so weird manupulations are possible, even ones that may break the buffer operation
    # this is to avoid using getters/setters for every internal thing specific socket types need to check, anyways, modifying anything directly is highly discouraged

    /** @var \ATL\Sockets\Socket */ public $skbSocket; # the socket associated
    public $splID; # object ID for external handlers
    public $skbID; # buffer ID for socket handlers
    public $skbState; # contains current buffer state
    public $skbParameters; # buffer parameters provided on creation

    /** @var \SplDoublyLinkedList */ public $skbData; # here all the data is buffered
    public $skbCount; # take care this is always in datagrams/messages
    public $skbSize; # take care here this follows skbCount, but specializations may use different size factor

    ########
    # non-volatile settings, make sure these all are set properly if need to alter before buffer is requested to open

    # take care for generalized buffer this all is in datagrams/messages but may be different for specializations
    public $skbMaxSize; # indicates maximum buffer size at or above which the full event is sent
    public $skbRealMaxSize; # indicates temporary extension if the buffer size, does not affect events but transport needs to account for it when adding data, resets on reaching low watermark
    public $skbExtendSizeBy; # indicates by how much to extend the buffer at minimum, any extensions below that will get higher
    public $skbLowWatermark; # indicates maximum buffer size at or below which the lowWatermark event is sent
    public $skbHighWatermark; # indicates maximum buffer size at or above which the highWatermark event is sent
    public $skbRealHighWatermark; # indicates temporary extension of highWatermark, happens alongside skbRealMaxSize extension

    ########
    # volatile settings, these may be changed on the fly by their setters, can be read but never ever manipulate these directly

    public $skbEventReadMode = false; # set to true to make buffer send every data piece added by transport via dataRead events and not add it for real, no empty/hasData/watermark events are generated in this case

    ########
    # implementation

    # calling order: first to last (mandatory), parents are to be called first
    public function __construct($id, $socket, $parameters = [])
    {
        $this->splID = spl_object_id($this);
        $this->skbInitialize($id, $socket, $parameters);
    }

    # calling order: first to last (strong), parents should be called first
    # avoid overriding this one unless absolutely necessary
    public function skbInitialize($id, $socket, $parameters = [])
    {
        $this->skbID = $id;
        $this->skbSocket = $socket;
        $this->skbParameters = $parameters;
        $this->skbInitializeDefaults();
        $this->skbReadParameters();
        $this->skbClear(true);
        $this->skbState = $this::SKB_STATE_CREATED;
    }

    # calling order: first to last, parents should be called first
    protected function skbInitializeDefaults()
    {
        # default parameters initialization
        # specializations can place any additional internal initialization here that needs to happen before skbReadParameters() call
        $this->skbRealMaxSize = $this->skbMaxSize = 256;
        $this->skbLowWatermark = 64;
        $this->skbRealHighWatermark = $this->skbHighWatermark = 192;
        $this->skbExtendSizeBy = 16;
    }

    # calling order: first to last, parents should be called first
    protected function skbReadParameters()
    {
        # specializations can place any additional internal parameter read here that needs to happen before skbClear() call
        $this->skbRealMaxSize = $this->skbMaxSize = $this->skbParameters[$this::SKB_PARAM_MAX_SIZE] ?? $this->skbMaxSize;
        $this->skbLowWatermark = $this->skbParameters[$this::SKB_PARAM_LOW_WATERMARK] ?? $this->skbLowWatermark;
        $this->skbRealHighWatermark = $this->skbHighWatermark = $this->skbParameters[$this::SKB_PARAM_HIGH_WATERMARK] ?? $this->skbHighWatermark;
        $this->skbExtendSizeBy = $this->skbParameters[$this::SKB_PARAM_EXTEND_SIZE_BY] ?? $this->skbExtendSizeBy;
    }

    ########
    # intrinsic data manipulation API for both ends of the buffer as it all depends on which side we are on
    # no peeking or bulk operations are provided, if these are necessary, they are to be implemented separately
    # for inflight data processing, use silent operations and call skbDataAdded/skbDataRemoved with only the actual data added/removed
    # bulk operations can benefit from doing it all with noSizeUpdate = true then calling skbSizeRemoved/skbSizeAdded directly

    # calling order: first to last (mandatory), parents must be called first
    public function skbClear($silent = false)
    {
        $this->skbData = new \SplDoublyLinkedList();
        return $this->skbDataCleared($silent);
    }

    # calling order: task-dependent, normally first to last, parents should be called first
    # avoid overriding this one unless absolutely necessary
    public function skbPopLeft($silent = false, $noSizeUpdate = false)
    {
        if ($this->skbCount == 0) return false; # take care null is considered to be a valid readable value
        $data = $this->skbData->shift();
        if (!$noSizeUpdate) $this->skbDataRemoved($data, $silent, $this::SKB_SIZE_OPERATION_POP_LEFT);
        return $data;
    }

    # calling order: task-dependent, normally first to last, parents should be called first
    # avoid overriding this one unless absolutely necessary
    public function skbPopRight($silent = false, $noSizeUpdate = false)
    {
        if ($this->skbCount == 0) return false; # take care null is considered to be a valid readable value
        $data = $this->skbData->pop();
        if (!$noSizeUpdate) $this->skbDataRemoved($data, $silent, $this::SKB_SIZE_OPERATION_POP_RIGHT);
        return $data;
    }

    # calling order: last to first (mandatory), parents must be called last
    # avoid overriding this one unless absolutely necessary
    public function skbAddLeft($data, $silent = false, $noSizeUpdate = false)
    {
        $this->skbData->unshift($data);
        if (!$noSizeUpdate) $this->skbDataAdded($data, $silent, $this::SKB_SIZE_OPERATION_ADD_LEFT);
        return true;
    }


    # calling order: last to first (mandatory), parents must be called last
    # avoid overriding this one unless absolutely necessary
    public function skbAddRight($data, $silent = false, $noSizeUpdate = false)
    {
        $this->skbData->push($data);
        if (!$noSizeUpdate) $this->skbDataAdded($data, $silent, $this::SKB_SIZE_OPERATION_ADD_RIGHT);
        return true;
    }

    # calling order: task-dependent
    # avoid overriding this one unless absolutely necessary
    public function skbPeekLeft()
    {
        if ($this->skbCount == 0) return false; # take care null is considered to be a valid readable value
        return $this->skbData->top();
    }

    # calling order: task-dependent
    # avoid overriding this one unless absolutely necessary
    public function skbPeekRight()
    {
        if ($this->skbCount == 0) return false; # take care null is considered to be a valid readable value
        return $this->skbData->bottom();
    }

    ########
    # internal data handlers (override for specifics)

    # calling order: last to first (mandatory), parents must be called last
    protected function skbDataCleared($silent)
    {
        $this->skbSizeRemoved($this->skbCount, $this->skbSize, $silent, $this::SKB_SIZE_OPERATION_CLEAR);
        $this->skbRealMaxSize = $this->skbMaxSize;
        $this->skbRealHighWatermark = $this->skbHighWatermark;
    }

    # calling order: last to first (mandatory), parents must be called last
    # avoid overriding this one unless absolutely necessary
    protected function skbDataRemoved($data, $silent, $operationHint = null)
    {
        return $this->skbSizeRemoved(1, $this->skbGetDataSize($data), $silent, $operationHint);
    }

    # calling order: last to first (mandatory), parents must be called last
    protected function skbSizeRemoved($count, $size, $silent, $operationHint = null)
    {
        $oldSize = $this->skbSize;
        $this->skbCount -= $count;
        $this->skbSize -= $size;
        if ($this->skbCount < 0) throw new \LogicException("Socket internal data count went lower than zero");
        if ($this->skbSize < 0) throw new \LogicException("Socket internal size count went lower than zero");

        if (($this->skbSize < $this->skbLowWatermark) && ($oldSize >= $this->skbLowWatermark)) {
            $this->skbRealMaxSize = $this->skbMaxSize; # reset maximum buffer size on reaching low watermark
            $this->skbRealHighWatermark = $this->skbHighWatermark; # also reset high watermark
            if (!$silent) $this->ehInvokeEventHandlers($this::SKB_EVENT_LOW_WATERMARK, $this);
        }

        if ($silent) return;
        if ($this->skbCount == 0) $this->ehInvokeEventHandlers($this::SKB_EVENT_EMPTY, $this);
    }

    # calling order: last to first (mandatory), parents must be called last
    # avoid overriding this one unless absolutely necessary
    protected function skbDataAdded($data, $silent, $operationHint = null)
    {
        return $this->skbSizeAdded(1, $this->skbGetDataSize($data), $silent, $operationHint);
    }

    # calling order: last to first (mandatory), parents must be called last
    protected function skbSizeAdded($count, $size, $silent, $operationHint = null)
    {
        $oldSize = $this->skbSize;
        $this->skbCount += $count;
        $this->skbSize += $size;

        if ($this->skbEventReadMode) return $this->skbSendEventModeData();
        if ($silent) return;

        if ($this->skbCount == 1) $this->ehInvokeEventHandlers($this::SKB_EVENT_HAS_DATA, $this);

        # this needs the isset here as we really want to avoid frequent method calls
        if (isset($this->ehEventHandlers[$this::SKB_EVENT_NEW_DATA]))
            $this->ehInvokeEventHandlers($this::SKB_EVENT_NEW_DATA, $this);

        if (($this->skbSize >= $this->skbRealHighWatermark) && ($oldSize < $this->skbRealHighWatermark))
            $this->ehInvokeEventHandlers($this::SKB_EVENT_HIGH_WATERMARK, $this);

        if (($this->skbSize >= $this->skbRealMaxSize) && ($oldSize < $this->skbRealMaxSize))
            $this->ehInvokeEventHandlers($this::SKB_EVENT_FULL, $this);
    }

    # specializations may use their own data sizing here
    # calling order: parents are not expected to be called, first to last if necessary, parents should be called first
    protected function skbGetDataSize($data)
    {
        return 1; # just count of buffer elements following skbCount
    }

    # this can be called with target size, the buffer will be extended to either that or one extra
    # this also temporarily changes buffer high watermark to fire at specific point, take care that this may fire high watermark event if the watermark is lower than amount of data
    # calling order: last to first (mandatory), parents must be called last
    # avoid overriding this one unless absolutely necessary
    protected function skbExtendMaxSize($targetSize, $targetWaterMark = null, $silent = false)
    {
        $oldSize = $this->skbRealMaxSize;
        $newBufferSize = max($targetSize, $this->skbRealMaxSize + $this->skbExtendSizeBy);
        $this->skbRealMaxSize = $newMaxSize;
        if ($targetWaterMark !== null) {
            $this->skbRealHighWatermark = $targetWaterMark;
            if (($this->skbSize >= $this->skbRealHighWatermark) && ($oldSize < $this->skbRealHighWatermark))
                $this->ehInvokeEventHandlers($this::SKB_EVENT_HIGH_WATERMARK, $this);
        }
        if (!$silent) $this->skbRequestMoreData();
    }

    # calling order: last to first (mandatory), parents must be called last
    protected function skbRequestMoreData()
    {
        $this->ehInvokeEventHandlers($this::SKB_EVENT_PENDING_DATA, $this);
    }

    ########
    # public data state API (override for specifics)
    # take care this state API must be consistent with the internal representation of all the state
    # calling order for all the group is task-dependent, normally first to last, parents should be called first, if at all

    public function getCount()
    {
        return $this->skbCount;
    }

    # the difference from getCount() is this may be some i.e. byte length in specific implementations
    public function getSize()
    {
        return $this->skbSize;
    }

    public function hasData()
    {
        return ($this->skbCount != 0);
    }

    public function isEmpty()
    {
        return ($this->skbCount == 0);
    }

    public function isFull()
    {
        return ($this->skbSize >= $this->skbRealMaxSize);
    }

    public function isAboveLowWatermark()
    {
        return ($this->skbSize >= $this->skbLowWatermark);
    }

    public function isBelowHighWatermark()
    {
        return ($this->skbSize < $this->skbRealHighWatermark);
    }

    ########
    # intrinsic state manipulation API
    # calling order for all the group is last to first (mandatory), parents must be called last
    # avoid overriding this group unless absolutely necessary, instead of overriding, try to always rely on provided events if possbile

    public function skbSocketOpen()
    {
        if ($this->skbState >= $this::SKB_STATE_OPENING) throw new \LogicException('Socket attempted to open socket buffer that is already initialized');
        $this->skbState = $this::SKB_STATE_OPENING;
        $this->ehInvokeEventHandlers($this::SKB_EVENT_OPENING, $this);
    }

    public function skbTransportOpen()
    {
        if ($this->skbState >= $this::SKB_STATE_OPEN) throw new \LogicException('Transport attempted to open socket buffer that is already open');
        $this->skbState = $this::SKB_STATE_OPEN;
        $this->ehInvokeEventHandlers($this::SKB_EVENT_OPEN, $this);
    }

    public function skbSocketClose()
    {
        if ($this->skbState < $this::SKB_STATE_OPEN) throw new \LogicException('Socket attempted to close socket buffer that is not yet open');
        if ($this->skbState >= $this::SKB_STATE_CLOSING) throw new \LogicException('Socket attempted to close socket buffer that is already closing or closed');
        $this->skbState = $this::SKB_STATE_CLOSING;
        $this->ehInvokeEventHandlers($this::SKB_EVENT_CLOSING, $this);
    }

    public function skbTransportClose()
    {
        if ($this->skbState < $this::SKB_STATE_OPEN) throw new \LogicException('Transport attempted to close socket buffer that is not yet open');
        if ($this->skbState >= $this::SKB_STATE_CLOSED) throw new \LogicException('Transport attempted to close socket buffer that is already closed');
        $this->skbState = $this::SKB_STATE_CLOSED;
        $this->ehInvokeEventHandlers($this::SKB_EVENT_CLOSED, $this);
    }

    public function skbAbort($silent = false)
    {
        if ($this->skbState >= $this::SKB_STATE_ABORTED) return;
        $sendClose = ($this->skbState < $this::SKB_STATE_CLOSED);
        $this->skbState = $this::SKB_STATE_ABORTED;
        if (!$silent) {
            if ($sendClose) $this->ehInvokeEventHandlers($this::SKB_EVENT_CLOSED, $this);
            $this->ehInvokeEventHandlers($this::SKB_EVENT_ABORTED, $this);
        }
    }

    ########
    # public socket buffer state API (override for specifics)
    # take care this state API must be consistent with the internal representation of all the state
    # calling order for all the group is task-dependent, normally first to last, parents should be called first, if at all

    public function isBeforeOpen()
    {
        return ($this->skbState < $this::SKB_STATE_OPENING);
    }

    public function isOpening()
    {
        return (($this->skbState >= $this::SKB_STATE_OPENING) && ($this->skbState < $this::SKB_STATE_OPEN));
    }

    public function isOpen()
    {
        return (($this->skbState >= $this::SKB_STATE_OPEN) && ($this->skbState < $this::SKB_STATE_CLOSING));
    }

    public function isWriteable()
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
    # avoid overriding this group unless absolutely necessary

    # calling order: last to first (mandatory), parents must be called last
    public function setEventReadMode($enabled = false)
    {
        $this->skbEventReadMode = $enabled;

        # if enabled, send all accumulated data via event handler if any, otherwise leave the data intact until any handler is added
        if ($enabled) $this->skbSendEventModeData();
    }

    # calling order: parents are not expected to be called
    protected function skbSendEventModeData()
    {
        if (isset($this->ehEventHandlers[$this::SKB_EVENT_DATA_READ]))
            while (($data = $this->skbPopLeft(true)) !== false)
                $this->ehInvokeEventHandlers($this::SKB_EVENT_DATA_READ, $this, $data);
    }

    # calling order is last to first (mandatory), parents must be called last
    protected function skbSendError($code, $subCode, $text, $fatal = true)
    {
        $this->ehInvokeEventHandlers($this::SKB_EVENT_ERROR, $this, $code, $subCode, $text, $fatal);
    }

    ########
    # public event handler registration API and its helpers
    # event setters are not expected to be overridden

    public function onHasData($owner, $callback, $silent = false) { $this->addEventHandler($owner, $this::SKB_EVENT_HAS_DATA, $callback, !$silent); }
    public function onNewData($owner, $callback, $silent = false) { $this->addEventHandler($owner, $this::SKB_EVENT_NEW_DATA, $callback, !$silent); }
    public function onEmpty($owner, $callback, $silent = false) { $this->addEventHandler($owner, $this::SKB_EVENT_EMPTY, $callback, !$silent); }
    public function onLowWatermark($owner, $callback, $silent = false) { $this->addEventHandler($owner, $this::SKB_EVENT_LOW_WATERMARK, $callback, !$silent); }
    public function onHighWatermark($owner, $callback, $silent = false) { $this->addEventHandler($owner, $this::SKB_EVENT_HIGH_WATERMARK, $callback, !$silent); }
    public function onFull($owner, $callback, $silent = false) { $this->addEventHandler($owner, $this::SKB_EVENT_FULL, $callback, !$silent); }
    public function onPendingData($owner, $callback, $silent = false) { $this->addEventHandler($owner, $this::SKB_EVENT_PENDING_DATA, $callback, !$silent); }
    public function onOpening($owner, $callback, $silent = false) { $this->addEventHandler($owner, $this::SKB_EVENT_OPENING, $callback, !$silent); }
    public function onOpen($owner, $callback, $silent = false) { $this->addEventHandler($owner, $this::SKB_EVENT_OPEN, $callback, !$silent); }
    public function onClosing($owner, $callback, $silent = false) { $this->addEventHandler($owner, $this::SKB_EVENT_CLOSING, $callback, !$silent); }
    public function onClosed($owner, $callback, $silent = false) { $this->addEventHandler($owner, $this::SKB_EVENT_CLOSED, $callback, !$silent); }
    public function onError($owner, $callback, $silent = false) { $this->addEventHandler($owner, $this::SKB_EVENT_ERROR, $callback, !$silent); }
    public function onAborted($owner, $callback, $silent = false) { $this->addEventHandler($owner, $this::SKB_EVENT_ABORTED, $callback, !$silent); }
    public function onDataRead($owner, $callback, $silent = false) { $this->addEventHandler($owner, $this::SKB_EVENT_DATA_READ, $callback, !$silent); }

    # calling order: last to first (mandatory), parents must be called last if the event type is yet unhandled or partially handled
    # take care not to duplicate event handling by calling parents as errors here may cause events to be sent more than once
    protected function ehOnEventHandlerAdd($owner, $handler, $callback, $runHandler)
    {
        switch ($handler) {
            case $this::SKB_EVENT_HAS_DATA:
            if (!$runHandler) break;
            if (!$this->isEmpty()) $callback($this);
            break;

            case $this::SKB_EVENT_EMPTY:
            if (!$runHandler) break;
            if ($this->isEmpty()) $callback($this);
            break;

            case $this::SKB_EVENT_LOW_WATERMARK:
            if (!$runHandler) break;
            if (!$this->isAboveLowWatermark()) $callback($this);
            break;

            case $this::SKB_EVENT_HIGH_WATERMARK:
            if (!$runHandler) break;
            if ($this->isBelowHighWatermark()) $callback($this);
            break;

            case $this::SKB_EVENT_FULL:
            if (!$runHandler) break;
            if ($this->isFull()) $callback($this);
            break;

            case $this::SKB_EVENT_OPENING:
            if (!$runHandler) break;
            if ($this->isOpening()) $callback($this);
            break;

            case $this::SKB_EVENT_OPEN:
            if (!$runHandler) break;
            if ($this->isOpen()) $callback($this);
            break;

            case $this::SKB_EVENT_CLOSING:
            if (!$runHandler) break;
            if ($this->isClosing()) $callback($this);
            break;

            case $this::SKB_EVENT_CLOSED:
            if (!$runHandler) break;
            if ($this->isClosed()) $callback($this);
            break;

            case $this::SKB_EVENT_ABORTED:
            if (!$runHandler) break;
            if ($this->isAborted()) $callback($this);
            break;

            case $this::SKB_EVENT_DATA_READ:
            if (!$runHandler) break;

            # when the first dataRead handler is added, all accumulated data needs to be sent to it
            if ($this->skbEventReadMode && !$this->isEmpty()) $this->skbSendEventModeData();
            break;
        }
    }

    public function __debugInfo(...$args)
    {
        $data = [
            'skbID' => $this->skbID,
            'splID' => $this->splID,
            'socket' => $this->skbSocket->splID,
            'state' => $this->skbState
                .($this->isBeforeOpen() ? ' [before open]' : '')
                .($this->isActive() ? ' [active]' : '')
                .($this->isOpening() ? ' [opening]' : '')
                .($this->isOpen() ? ' [open]' : '')
                .($this->isWriteable() ? ' [writeable]' : '')
                .($this->isClosing() ? ' [closing]' : '')
                .($this->isClosed() ? ' [closed]' : '')
                .($this->isAborted() ? ' [aborted]' : ''),
            'dataCount' => $this->skbCount,
            'dataSize' => $this->skbSize,
            'parameters' => !empty($parameters = array_filter($this->skbParameters, function ($v) { return isset($v); })) ? $parameters : null,
            'maxSize' => $this->skbMaxSize,
            'realMaxSize' => $this->skbRealMaxSize,
            'extendBy' => $this->skbExtendSizeBy,
            'lowWatermark' => $this->skbLowWatermark,
            'highWatermark' => $this->skbHighWatermark,
            'realHighWatermark' => $this->skbRealHighWatermark,
            'eventReadMode' => $this->skbEventReadMode ? 'Y' : 'N',
            'contents' => [],
        ];

        foreach ($this->skbData as $v) $data['contents'][] = $v;
        if (empty($data['contents'])) $data['contents'] = '<EMPTY>';

        if (isset($args[0]))
            foreach ($args[0] as $k => $v)
                $data[$k] = $v;

        return $data;
    }
}

class BufferPrototype implements \ATL\IEventHandlers { use \ATL\TEventHandlers; }
class Buffer extends BufferPrototype implements IBuffer { use TBuffer; }

########
# here the very base capability set based buffer classes go

class BaseBuffer extends Buffer implements IBufferBaseCapability { use TBufferBaseCapability; }
class BulkBuffer extends BaseBuffer implements IBufferBulkCapability { use TBufferBulkCapability; }

class ByteBuffer extends BaseBuffer implements IBufferByteSizeCapability { use TBufferByteSizeCapability; }
class ByteBulkBuffer extends ByteBuffer implements IBufferBulkCapability { use TBufferBulkCapability; }
class ByteBulkStringBuffer extends ByteBulkBuffer implements IBufferBulkStringReadCapability { use TBufferBulkStringReadCapability; }
class ByteReadBulkStringBuffer extends ByteBulkStringBuffer implements IBufferByteReadCapability { use TBufferByteReadCapability; }
class ByteReadBulkStringDelimitedBuffer extends ByteReadBulkStringBuffer implements IBufferDelimitedReadCapability { use TBufferDelimitedReadCapability; }

class MessageBuffer extends BulkBuffer implements IBufferMessageCapability { }
class DatagramBuffer extends ByteBulkBuffer implements IBufferDatagramCapability { }
class ByteStreamBuffer extends ByteReadBulkStringDelimitedBuffer implements IBufferStreamCapability { }
class MixedStreamBuffer extends ByteReadBulkStringDelimitedBuffer implements IBufferStreamCapability, IBufferMixedStreamCapability { }
