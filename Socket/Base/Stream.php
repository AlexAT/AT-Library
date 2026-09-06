<?php

namespace ATL\Socket;

# This base Stream socket class is shared between PHP-stream based socket types: TCP, Datagram (has modified read/write handlers), Unix (socket) and can be used over i.e. pipes or other file handles
# It provides all internal tasks necessary to facilitate self-polling or factory polled socket I/O over single PHP stream handle
# Can be used as generalized PHP stream socket from application code by supplying already connected readStream/writeStream to its constructor, specific sockets do not pass anything and use late initialization

# There are two socket classes defined there: abstract StreamBase that provides just a base stream socket without real internal read/write methods and with no capabilities, and full Stream socket class described above
# This is because i.e. Datagram uses StreamBase, provides its own send/recv based read/write functions and does not have any additional capabilities beyond polling factory support, so we need to have 'reduced' base class

########################## description is to be rewritten
# Each Stream socket spawns multiple subtasks during its operation
# consists of three tasks, only one of which may be running intervally in case socket is self polled, it is scheduled by Factory events in case of Factory polling
# the first task is the main task, it does socket polling in case there is no Factory polling by socket_select(), optionally schedules write task if write was suspended, reads the socket data and queues data read
# also, the main task is responsible for socket state (connection/disconnection/reconnection/error handling and sending), Factory operations, and scheduling dispatch task if some data was read
# the second task is write task, it only gets scheduled when there is some data available to write, and writes the data queued from write() calls to the socket
# in case socket cannot accept more data, the write task suspends and awaits for polling result allowing to write data by either main task or Factory, after successful polling it is resumed by scheduling
# the third task is dispatch task, it is only scheduled by the main task when some new data is read from the socket, and dispatches this data to attached onRead event handler if it is available
# in case there is no onRead event handler, dispatch task suspends and is only scheduled when new onRead handler becomes available and some data is present to dispatch
# this three-task combo looks bulky at first and adds task scheduling overhead, but it allows for independent socket read/status, write and dispatch processing
# the alternative is consecutive processing in a single task, and it will certainly add its queue checking overhead when i.e. there is only data to dispatch, but nothing to read and write
# in this example case, main/read task will continue to poll the socket (or Factory will do, and main task will be sleeping) at polling intervals, write task will sleep, and only dispatch task will run realtime
# until all data is dispatched, then will sleep leaving only main task running (or even sleeping with Factory polling), reducing number of write and dispatch queue checks and socket checks to almost zero
# if this was all done in main task, main task would have to run realtime, checking socket state at each invocation (or maintaining complex interval/scheduling differentiation code to self-dispatch which is unnecessary)

# all stream socket implementations support bulk reads and writes and override bulkWrite(), so we bring in the bulk read/write feature here early
abstract class StreamBasePrototype extends \ATL\Socket\PollingSocket implements \ATL\Socket\Capabilities\IBulk { use \ATL\Socket\Capabilities\TBulk; }
abstract class StreamBase extends \ATL\Socket\StreamBasePrototype
{
    # stream initialization mode, can be initialized half-closed
    const STREAM_INIT_RDWR = 0;
    const STREAM_INIT_RDONLY = 1;
    const STREAM_INIT_WRONLY = 2;

    const skLateStreamInitialization = false; # set this to true if daughter class initializes skStream in skSocketCheckForConnection and not in skSocketCreate

    protected $skCloseStreamNormally = true; # set this to false if daughter class does not want stream to be closed after socket processes end (read and write will be closed though)
    protected $skStreamInitMode = self::STREAM_INIT_RDWR; # set to initialization mode

    # public Stream socket state
    public $skStream;
    public $skContextOptions;

    # take care that while this constructor accepts stream parameter, it does not set skStream if stream is null, set it yourself if you need pre-supplied stream to be used
    public function __construct($stream = null, $contextOptions = null, $connectTimeout = null, $closeStreamNormally = null, $streamInitMode = null)
    {
        # Stream socket initialization
        $this->socketConnectTimeout = $connectTimeout ?? ini_get('default_socket_timeout');
        if (!is_numeric($this->socketConnectTimeout)) $this->socketConnectTimeout = $this::DEFAULT_CONNECT_TIMEOUT;
        $this->skContextOptions = stream_context_create($contextOptions ?? []);
        if ($stream !== null) {
            $this->skStream = $stream;
            if ($this->socketAddress === null) {
                # attempt to set socket address from stream metadata
                $meta = @stream_get_meta_data($stream);
                if (is_array($meta) && isset($meta['uri']) && !empty($meta['uri']))
                    $this->socketAddress = $meta['uri'];
            }
        }
        if ($closeStreamNormally !== null)
            $this->skCloseStreamNormally = $closeStreamNormally;
        if ($streamInitMode !== null)
            $this->skStreamInitMode = $streamInitMode;

        parent::__construct(); # this calls base Socket constructor and socketSetup() code
    }

    ########
    # Internal socket API

    protected function skGetPolledResource()
    {
        return $this->skStream;
    }

    # we need to poll our socket in time and also have flushing read mode of operation, so we add pre-write check to bulk feature as well
    public function skPreWriteBulkCheck($lines)
    {
        if ($this->socketState >= $this::STATE_FLUSHING_READS) return false; # socket is disconnected and flushing reads, so no more writes are accepted
        if (($this->skCurrentWrite === null) && $this->skWriteBuffer->isEmpty()) {
            # write buffer was empty before this operation, so schedule the task if it was not having anything to write, ignoring any polling
            $this->skSocketWantsWrite = true;
            $this->skScheduleMyself();
        }
        return parent::skPreWriteBulkCheck($lines);
    }

    public function skSocketConnected() { }
    public function skSocketEnd()
    {
        # finally close the stream if allowed
        if ($this->skCloseStreamNormally && $this->skStream) {
            fclose($this->skStream);
            $this->skStream = null;
        }
    }

    # same for these two, provide real stream read and write close attempt functions
    protected function skSocketCloseRead()
    {
        if (!$this->skSocketReadClosed) {
            # here we avoid closing read/write if we are to prevent closing stream on socket end
            if ($this->skCloseStreamNormally)
                if ($this->skStream) # under different error conditions we may be called when stream does not exist
                    @stream_socket_shutdown($this->skStream, STREAM_SHUT_RD);
        }
        parent::skSocketCloseRead();
    }

    protected function skSocketCloseWrite()
    {
        if (!$this->skSocketWriteClosed) {
            # here we avoid closing read/write if we are to prevent closing stream on socket end
            if ($this->skCloseStreamNormally)
                if ($this->skStream) # under different error conditions we may be called when stream does not exist
                    @stream_socket_shutdown($this->skStream, STREAM_SHUT_WR);
        }
        parent::skSocketCloseWrite();
    }

    ########
    # Stream socket Task set

    # this subtask handles socket initialization, returns true on success, false on failure
    # while this Task handler may be overridden for complex creation procedures, it is wiser to use abstract skSocketCreateHandler for simple actions
    # also sets stream to non-blocking mode of operation
    public function skSocketCreateTask($taskObject)
    {
        $this->skScheduleTarget = null; # we are not waiting to be scheduled
        yield true;

        # socket creation with context options
        $this->skLastErrorNo = $this->skLastError = null;
        if (!$this->skSocketCreate()) return false;

        # set stream to non-blocking mode of operation if it was filled in (some socket types like ICMP can feature late skStream initialization and change mode themselves)
        if (!$this::skLateStreamInitialization) {
            if ($this->skStream === null) throw new \ErrorException('skSocketCreate did not initialize skStream and no late initialization is expected for this socket');
            $this->skLastErrorNo = $this->skLastError = null;
            \set_error_handler($this->skErrorCallback, E_WARNING | E_NOTICE);
            $result = stream_set_blocking($this->skStream, false);
            \restore_error_handler();
            if ($result === false) return false; # on error, transition to finishing phase
        }

        # created
        $this->socketState = $this::STATE_CREATED;
        return true;
    }

    # late stream creation handler, mandatory to override even if you do not use it and override skSocketCreateTask instead, see Stream and i.e. TCP for implementation details
    # as StreamBase socket is considered to already have skStream supplied, we do not do anything here except checking if skStream is null
    # not declared abstract but is intended to be overridden in daughter classes as necessary
    public function skSocketCreate()
    {
        # stream creation, in base class context options are never set as we have already ready stream provided
        if ($this->skStream === null) {
            $this->skLastErrorNo = $this::ERROR_CONNECTION_FAILURE;
            $this->skLastError = 'Socket stream is NULL';
            return false;
        }

        return true;
    }

    # the thing is, as base Stream socket already has skStream supplied and so we do not have anything to wait for now
    # but we still do select on stream just to see in what state it is and what operations are possible on it
    public function skSocketCheckForConnection()
    {
        $wantRead = $wantExcept = []; $wantWrite = [$this->skStream];
        $this->skLastErrorNo = $this->skLastError = null;
        \set_error_handler($this->skErrorCallback, E_WARNING | E_NOTICE);
        $result = stream_select($wantRead, $wantWrite, $wantExcept, 0, 0);
        \restore_error_handler();
        if ($result === false) {
            # select() error handling (we may get EINTR here which we do not consider an error)
            if (!preg_match('#interrupted\\s+system\\s+call#iS', $this->skLastError ?? '')) return false; # on real error, transition to finishing phase
            $this->skLastErrorNo = $this->skLastError = null; # reset error state
            return null; # we have to wait
        }

        # we are okay, open read and write
        if ($this->skStreamInitMode !== $this::STREAM_INIT_WRONLY)
            $this->skSetReadOpen();
        if ($this->skStreamInitMode !== $this::STREAM_INIT_RDONLY)
            $this->skSetWriteOpen();
        return true;
    }

    # self-polling routine is part of base class as it is still the same for both byte based stream and datagram stream sockets
    protected function skPoll()
    {
        $wantRead = ($this->skPollRead && (($this->skReadRemaining < $this->socketMaxDataBuffered) || $this->skReaderWaitsForRead));
        $wantWrite = ($this->skPollWrite && (($this->skCurrentWrite !== null) || !$this->skWriteBuffer->isEmpty()));
        if ($wantRead || $wantWrite) {
            $wantRead = $wantRead ? [$this->skStream] : [];
            $wantWrite = $wantWrite ? [$this->skStream] : [];
            $wantExcept = [];
            $this->skLastErrorNo = $this->skLastError = null;
            \set_error_handler($this->skErrorCallback, E_WARNING | E_NOTICE);
            $result = stream_select($wantRead, $wantWrite, $wantExcept, 0, 0);
            \restore_error_handler();
            if ($result === false) {
                # select() error handling (we may get EINTR here which we do not consider an error)
                if (!preg_match('#interrupted\\s+system\\s+call#iS', $this->skLastError ?? '')) return false; # on real error, transition to finishing phase
                $this->skLastErrorNo = $this->skLastError = null; # reset error state
                return true; # this did not matter for connect(), but here if we get EINTR, we request immediate next run, otherwise we may delay read/write events on lost select() events
            }
            # convert to booleans as factory polling could do
            $this->skSocketWantsRead = !empty($wantRead);
            $this->skSocketWantsWrite = !empty($wantWrite);
        }
    }

    # returns true if skCurrentWrite has been set to string or null to write or repeat, returns false if error occured and needs to be propagated
    protected function skGetAndTranslateNextWrite()
    {
        $this->skCurrentWrite = $this->skWriteBuffer->shift();
        while (is_object($this->skCurrentWrite)) {
            try {
                $this->skCurrentWrite = $this->skObjectWriteHandler($this->skCurrentWrite);
            } catch (\Exception $e) {
                $this->skLastErrorNo = $e->getCode();
                if (!$this->skLastErrorNo) $this->skLastErrorNo = $this::ERROR_GENERAL_FAILURE;
                $this->skLastError = $e->getMessage();
                return false;
            }
            if ($this->skCurrentWrite === false) {
                # unhandled object, make it socket error
                $this->skLastErrorNo = $this::ERROR_GENERAL_FAILURE;
                $this->skLastError("Object write handler returned unspecified error while writing object to socket");
                return false;
            }
            if (!is_object($this->skCurrentWrite))
                if ($this->skCurrentWrite !== null)
                    $this->skWriteRemaining += strlen($this->skCurrentWrite); # add newly obtained string to write length
        }
        return true;
    }

    # this method is internally linked to skGetNextWrite() code in Stream and DatagramStream
    # may be overridden to handle different in-flight objects written to the stream (i.e. TLS layer uses this)
    # return string to make this string written, return null to write nothing and continue, return false to form a socket write error
    # throw an Exception to propagate specific socket error that must be defined as exception message
    protected function skObjectWriteHandler($object)
    {
        # by default, any object written is an error if it has no __toString(), otherwise object formed string is written
        if ($object instanceof \Stringable) return (string) $object;
        throw new \ErrorException("Unsupported and not stringable object of class `".get_class($object)."` was attempted to be written on socket");
    }
}

########
# bring in additional socket features for byte-based stream sockets
abstract class StreamPrototype extends \ATL\Socket\StreamBase implements \ATL\Socket\Capabilities\IReadBytes, \ATL\Socket\Capabilities\IDelimitedReads
{
    use \ATL\Socket\Capabilities\TReadBytes; # bring in readBytes capability
    use \ATL\Socket\Capabilities\TDelimitedReads; # bring in delimited reads capability
}

########
# main Stream socket class that is intended to work with byte stream sockets (TCP, Unix sockets, pipes, etc.)
class Stream extends \ATL\Socket\StreamPrototype
{
    # general byte-based stream socket read routine
    protected function skAttemptRead()
    {
        for ($i = 0; $i < $this->socketReadCount; $i++) {
            $this->skLastErrorNo = $this->skLastError = null;
            \set_error_handler($this->skErrorCallback, E_WARNING | E_NOTICE);
            $data = fread($this->skStream, $this->socketReadSize); # read up to socketReadSize bytes
            \restore_error_handler();
            if ($data === '') {
                # we read nothing apparently, this may be either end of read or socket disconnection condition
                $this->skLastErrorNo = $this->skLastError = null;
                \set_error_handler($this->skErrorCallback, E_WARNING | E_NOTICE);
                $eof = feof($this->skStream);
                \restore_error_handler();
                if ($eof) {
                    # yes, the socket has disconnected or half-disconnected in process, and now it depends on if there is some error or not
                    if ($this->skLastErrorNo) return null; # there is some error, transition to finishing phase
                    # close read direction and continue polling as we may be half-closed
                    $this->skSetReadClosed();
                    $this->skSocketCloseRead();
                    if ($this->skSocketReadClosed && $this->skSocketWriteClosed) return null; # both read and write sides are closed, transition to finishing phase
                    # invoke eof handler early
                    $this->skEOFSent = true;
                    $this->ehInvokeEventHandlers('eof', $this, $this->skReadRemaining); # invoke EOF handler for anyone who monitors socket input state explicitly
                }
                return true; # we just read nothing, this is normal end of read so we may happily allow us to poll for new data on the next polling interval
            } elseif ($data === false) {
                # fread() error handling, transition to finishing phase
                return null;
            } else {
                # okay, we have read the data chunk, push it to read queue and inform our hasData event handler if we were empty or read from before
                # we do not put anything into read buffer after we are explicitly transitioned to disconnecting phase by disconnect() call though
                if ($this->socketState < $this::STATE_DISCONNECTING) {
                    $this->skReadBuffer->push($data);
                    $this->skReadRemaining += strlen($data);
                    $this->skReadComplete();
                }
            }
        }

        # we just read something and want to read even more, so return false to do no polling and repeat the read attempt
        return false;
    }

    # general byte-based stream socket write routine
    protected function skAttemptWrite()
    {
        $bytesWritten = 0;
        do {
            if ($this->skCurrentWrite === null) {
                # current write portion is empty, so we need to get next write from the buffer
                if ($this->skWriteBuffer->isEmpty()) return false; # nothing more to write, bail out and do not poll
                if (!$this->skGetAndTranslateNextWrite()) return null; # could not translate next write, propagate error
                if ($this->skCurrentWrite === null) continue; # current write may be empty after object unwrapping, if is it, retry retrieval from the write buffer
            }

            # attempt to write the rest of the current write buffer
            $this->skLastErrorNo = $this->skLastError = null;
            \set_error_handler($this->skErrorCallback, E_WARNING | E_NOTICE);
            $writeLen = fwrite($this->skStream, $this->skCurrentWrite);
            \restore_error_handler();
            if ($writeLen === false) return null; # on error, transition to finishing phase
            if ($writeLen == strlen($this->skCurrentWrite)) {
                $bytesWritten += $writeLen;
                $this->skWriteRemaining -= $writeLen;
                $this->skCurrentWrite = null;
            } else {
                # partial write occured
                if ($writeLen == 0) return true; # we could not write totally anything, request polling for write buffer
                $bytesWritten += $writeLen;
                $this->skWriteRemaining -= $writeLen;
                $this->skCurrentWrite = substr($this->skCurrentWrite, $writeLen); # as we have partial write, we need to truncate our current write, yes, this is slow :(
                break; # we still may attempt writing more on the next loop, partial writes may be caused by single write size limits and other reasons
            }
        } while ($bytesWritten < $this->socketWriteSize);

        # we have written something and may possibly write even more, so return false to do no polling and repeat the write attempt if necessary
        return false;
    }
}

########
# slightly modified version of Stream socket code that is intended to work with datagrams (UDP, Unix datagram sockets, etc.)
class DatagramStream extends \ATL\Socket\StreamBase
{
    # general datagram-based stream socket read routine
    protected function skAttemptRead()
    {
        for ($i = 0; $i < $this->socketReadCount; $i++) {
            $address = null; # datagram sockets have a concept of remote address, so retrieve address along with datagram
            $this->skLastErrorNo = $this->skLastError = null;
            \set_error_handler($this->skErrorCallback, E_WARNING | E_NOTICE);
            $data = stream_socket_recvfrom($this->skStream, $this->socketReadSize, 0, $address); # receive datagram up to socketReadSize bytes
            \restore_error_handler();
            if (($data === false) || (($data === '') && ($address === null))) {
                # we read nothing apparently, this may be either end of read or socket disconnection condition (should not happen with datagram sockets but who knows)
                # data = false usually happens on errors, but sometimes can just happen without any error indicate and then it is similar to no error
                if ($this->skLastErrorNo !== null) return null; # handle the error case
                $this->skLastErrorNo = $this->skLastError = null;
                \set_error_handler($this->skErrorCallback, E_WARNING | E_NOTICE);
                $eof = feof($this->skStream);
                \restore_error_handler();
                if ($eof) {
                    # yes, the socket has disconnected or half-disconnected in process, and now it depends on if there is some error or not
                    if ($this->skLastErrorNo) return null; # there is some error, transition to finishing phase
                    # close read direction and continue polling as we may be half-closed
                    $this->skSetReadClosed();
                    $this->skSocketCloseRead();
                    if ($this->skSocketReadClosed && $this->skSocketWriteClosed) return null; # both read and write sides are closed, transition to finishing phase
                    # invoke eof handler early
                    $this->skEOFSent = true;
                    $this->ehInvokeEventHandlers('eof', $this, $this->skReadRemaining); # invoke EOF handler for anyone who monitors socket input state explicitly
                }
                return true; # we just read nothing, this is normal end of read so we may happily allow us to poll for new data on the next polling interval
            } else {
                # okay, we have read the datagram, push it to read queue and inform our hasData event handler if we were empty or read from before
                # we do not put anything into read buffer after we are explicitly transitioned to disconnecting phase by disconnect() call though
                if ($this->socketState < $this::STATE_DISCONNECTING) {
                    $this->skReadBuffer->push($data);
                    $this->skReadRemaining += strlen($data);
                    $this->skReadComplete();
                }
            }
        }

        # we just read something and want to read even more, so return false to do no polling and repeat the read attempt
        return false;
    }

    # general datagram-based stream socket write routine
    protected function skAttemptWrite()
    {
        $bytesWritten = 0;
        do {
            if ($this->skCurrentWrite === null) {
                if ($this->skWriteBuffer->isEmpty()) return false; # nothing more to write, bail out and do not poll
                if (!$this->skGetAndTranslateNextWrite()) return null; # could not translate next write, propagate error
                if ($this->skCurrentWrite === null) continue; # current write may be empty after object unwrapping, if is it, retry retrieval from the write buffer
            }

            # attempt to write datagram
            $this->skLastErrorNo = $this->skLastError = null;
            \set_error_handler($this->skErrorCallback, E_WARNING | E_NOTICE);
            $writeLen = stream_socket_sendto($this->skStream, $this->skCurrentWrite);
            \restore_error_handler();
            if ($writeLen === false) return null; # on error, transition to finishing phase
            if ($writeLen == strlen($this->skCurrentWrite)) {
                $bytesWritten += $writeLen;
                $this->skWriteRemaining -= $writeLen;
                $this->skCurrentWrite = null;
            } else {
                # partial write occured
                if ($writeLen <= 0) return true; # we could not write totally anything, request another polling for write
                $bytesWritten += $writeLen;
                $this->skWriteRemaining -= strlen($this->skCurrentWrite); # subtract real datagram size as we are not going to resend it
                $this->ehInvokeEventHandlers('partialWrite', $this, $this->skCurrentWrite, $writeLen); # call our partialWrite handler if set so software knows partial write happened
                $this->skCurrentWrite = null;
                return false; # do not attempt writing anything more during this invocation, partial writes are usually caused not by buffer full but by oversized datagrams, but it is better to be safe than sorry
            }
        } while ($bytesWritten < $this->socketWriteSize);

        # we have written something and may possibly write even more, so return false to do no polling and repeat the write attempt if necessary
        return false;
    }

    # simplified event handler addition support
    public function onPartialWrite($owner, $callback, $runHandler = true, $addLast = false) { $this->addEventHandler($owner, 'partialWrite', $callback, $runHandler, $addLast); }
}

########
# Stream type sockets polling factory built around stream_select()

# Before adding created socket as Task to TaskLoop for self-polling, you can add sockets to single compatible polling factory Task which polls all sockets at once
# Adding socket to polling factory converts socket tasks into waiting manually scheduled tasks that are only scheduled when factory detects socket needs to read or write data and can do this
# Factory issues socket_select() on all sockets added to it and calls the corresponding socket wakeup handler to wake up the socket task and perform operations necessary
# If multiple sockets point to the single Task to wake up, polling factory provides the waking task with list of all sockets that needed some action on select()
# Socket tasks can indicate sockets that are going to sleep and want to be polled again and provided to the wakeup event handler on polling events to polling factory
# This is due to socket can continue to run the read/write loop until it exhausts the data to read or the write buffer without need to be polled, and only then becomes polled/scheduled again
# Polling factory does not stay in socket handler paths, it expects sockets to always explicitly request polling when they need it (read and exception polling is always provided, write polling is optional)

interface IStreamPollingFactory
{

}

trait TStreamPollingFactory
{
    protected function pfCanPollResource($socket, $resource)
    {
        # some PHP version may change resource to object yet again, so we do both checks even if it has not happened yet
        if (is_resource($resource)) {
            if (!strcasecmp('stream', get_resource_type($resource))) return true; # we expect Stream resource
        } elseif (is_object($resource) && class_exists('\\Stream')) {
            if ($resource instanceof \Stream) return true; # this is a wild guess of how Stream object will be called
        }
        return false;
    }

    protected function pfDoPoll(&$wantRead, &$wantWrite, &$wantException)
    {
        $this->pfLastErrorNo = $this->pfLastError = null;
        \set_error_handler($this->pfErrorCallback, E_WARNING | E_NOTICE);
        $result = stream_select($wantRead, $wantWrite, $wantException, 0, 0);
        \restore_error_handler();
        return $result;
    }
}

class StreamPollingFactory extends \ATL\Socket\PollingFactory implements IStreamPollingFactory { use TStreamPollingFactory; }
