<?php

namespace ATL\Sockets;

# when composing capabilities, compose them one by one into a chain of classes as capabilities can overwrite methods and properties incrementally
# when composing capabilities, compose them in the interface dependency order listed here, as otherwise you may overwrite it a wrong way and end with unexpected result
# take care that capabilities provide both read and write counterparts at once, but normally each buffer is only used one way, very weird issues may happen if it is not so

########
# the very basic message/datagram buffer capability
# provides base read(), write(), peek(), returnRead()

interface IBufferBaseCapability
{
    public function read();
    public function write($data);
    public function peek();
    public function returnRead($data);
}

trait TBufferBaseCapability
{
    public function read()
    {
        if ($this->skbCount == 0) {
            # empty buffer, request more data from the socket (this speeds up socket polling)
            $this->skbRequestMoreData();
            return false;
        }

        return $this->skbPopLeft();
    }

    public function write($data)
    {
        if (!$this->isWriteable()) return false; # writing to not open or closed buffer is a bad idea
        $this->skbAddRight($data);
        return true;
    }

    public function peek()
    {
        return $this->skbPeekleft();
    }

    public function returnRead($data)
    {
        $this->skbAddLeft($data);
        return true;
    }
}

########
# byte size buffer capability maintains byte size inside the buffer instead of message-based count
# take care that when we cannot read anything from byte-based buffers, we return null and not false, writing nulls is also not allowed

interface IBufferByteSizeCapability extends IBufferBaseCapability { }

trait TBufferByteSizeCapability
{
    public $skbBlockSize; # suggested block size for external operations, take care this is amount skbMaxSize will usually be exceeded up to with

    protected function skbInitializeDefaults($parameters)
    {
        parent::skbInitializeDefaults($parameters);

        # here we change the defaults for size and watermarks and add block size
        $this->skbRealMaxSize = $this->skbMaxSize = 262144;
        $this->skbLowWatermark = 65536;
        $this->skbHighWatermark = 196608;
        $this->skbBlockSize = 131072;
    }

    protected function skbReadParameters($parameters)
    {
        parent::skbReadParameters($parameters);

        foreach ($parameters as $k => $v) {
            switch ($k) {
                case 'blockSize': $this->skbBlockSize = $v; break;
            }
        }
    }

    public function read()
    {
        return (($result = parent::read()) !== false) ? $result : null;
    }

    public function write($data)
    {
        if ($data === null) throw new \Exception('Attempted to write null to the byte-sized socket buffer'); # cannot add nulls to the byte buffer
        return parent::write($data);
    }

    public function peek()
    {
        return (($result = parent::peek()) !== false) ? $result : null;
    }

    public function returnRead($data)
    {
        if ($data === null) throw new \Exception('Attempted to return null read to the byte-sized socket buffer'); # cannot add nulls to the byte buffer
        return parent::returnRead($data);
    }

    protected function skbGetDataSize($data)
    {
        if (is_scalar($data)) return strlen($data); # strings and other scalars are just string byte count in size
        if ($data instanceof \ATL\Sockets\SizableByteBufferObject) return $data->skboGetSize(); # sizable byte buffer objects can get us their own size
        return 0; # anything not sizable does not count against the buffer size
    }
}

########
# flush capability provides flush() request method and corresponding event invocation

interface IBufferFlushCapability
{
    const SKB_EVENT_FLUSH = 0x0100;

    public function flush();
}

trait TBufferFlushCapability
{
    public function flush()
    {
        if (!$this->isWriteable()) return false; # flushing not open or closed buffer is a bad idea
        $this->ehInvokeEventHandlers($this::SKB_EVENT_FLUSH, $this);
        return true;
    }
}

########
# bulk capability provides readBulk(), writeBulk(), peekBulk() and returnReadBulk(), reads always return an array, empty array if there is nothing to read
# technically should be applicable for every socket buffer as base capability, but placed here just in case and because it's slightly more complex than normal operations

interface IBufferBulkCapability extends IBufferBaseCapability
{
    public function readBulk($maxReadCount = PHP_INT_MAX);
    public function writeBulk($dataSet);
    public function peekBulk($maxReadCount = PHP_INT_MAX);
    public function returnReadBulk($dataSet);
}

trait TBufferBulkCapability
{
    public function readBulk($maxReadCount = PHP_INT_MAX)
    {
        if ($this->skbCount == 0) {
            # empty buffer, request more data from the socket (this speeds up socket polling) and return nothing
            $this->skbRequestMoreData();
            return [];
        }

        if ($maxReadCount <= 0) return []; # nothing to read because of maximum read count being weird

        $read = [];
        $readSize = 0;
        for ($i = 0; $i < $maxReadCount; $i++) {
            if (($data = $this->skbPopLeft(true, true)) === false) break;
            $readSize += $this->skbGetDataSize($data);
            $read[] = $data;
        }
        $this->skbSizeRemoved(count($read), $readSize, false, $this::SKB_SIZE_OPERATION_POP_LEFT);
        return $read;
    }

    public function writeBulk($dataSet)
    {
        if (!$this->isWriteable()) return false; # writing to not open or closed buffer is a bad idea

        $writeSize = 0;
        foreach ($dataSet as $data) {
            $writeSize += $this->skbGetDataSize($data);
            $this->skbAddRight($data, true, true);
        }
        $this->skbSizeAdded(count($dataSet), $writeSize, false, $this::SKB_SIZE_OPERATION_ADD_RIGHT);
    }

    public function peekBulk($maxReadCount = PHP_INT_MAX)
    {
        if (($this->skbCount == 0) || ($maxReadCount <= 0)) return []; # nothing to peek because of empty buffer or maximum read count, we do not request more data here because we don't need to, we are just peeking

        $peek = [];
        $peekCount = 0;
        foreach ($this->skbData as $data) {
            $peek[] = $data;
            if (++$peekCount >= $maxReadCount) break;
        }
        return $peek;
    }

    public function returnReadBulk($dataSet)
    {
        $returnSize = 0;
        foreach ($dataSet as $data) {
            $returnSize += $this->skbGetDataSize($data);
            $this->skbAddLeft($data, true, true);
        }
        $this->skbSizeAdded(count($dataSet), $writeSize, false, $this::SKB_SIZE_OPERATION_ADD_LEFT);
    }
}

########
# bulk string read capability provides readBulkString() and peekBulkString() like bulk capability, reads normally return string (empty string if there is nothing to read), but can also return null if the read is impossible

interface IBufferBulkStringReadCapability extends IBufferBaseCapability, IBufferBulkCapability
{
    public function readBulkString($maxReadCount = PHP_INT_MAX, $throwOnImpossibleRead = false);
    public function peekBulkString($maxReadCount = PHP_INT_MAX, $throwOnImpossibleRead = false);
}

trait TBufferBulkStringReadCapability
{
    public function readBulkString($maxReadCount = PHP_INT_MAX, $throwOnImpossibleRead = false)
    {
        if ($this->skbCount == 0) {
            # empty buffer, request more data from the socket (this speeds up socket polling) and return nothing
            $this->skbRequestMoreData();
            return '';
        }

        if ($maxReadCount <= 0) return ''; # nothing to read because of maximum read count being weird

        $data = $this->skbPeekLeft();
        if (!($data instanceof \ATL\Sockets\StringableBufferObject) || !($data instanceof \ATL\Sockets\SizableByteBufferObject)) {
            if ($throwOnImpossibleRead) throw new ReadException("Cannot read anything in bulk as string because of special object present in the stream");
            return null;
        }

        return implode('', $this->readBulk($maxReadCount));
    }

    public function peekBulkString($maxReadCount = PHP_INT_MAX, $throwOnImpossibleRead = false)
    {
        if (($this->skbCount == 0) || ($maxReadCount <= 0)) return ''; # nothing to peek because of empty buffer or maximum read count, we do not request more data here because we don't need to, we are just peeking

        $data = $this->skbPeekLeft();
        if (!($data instanceof \ATL\Sockets\StringableBufferObject) || !($data instanceof \ATL\Sockets\SizableByteBufferObject)) {
            if ($throwOnImpossibleRead) throw new ReadException("Cannot peek anything in bulk as string because of special object present in the stream");
            return null;
        }

        return implode('', $this->peekBulk($maxReadCount));
    }
}

########
# byte read capability provides readBytes(), requires buffer to be byte-sized, normally returns some string (empty string if there is nothing to read)
# take care that when using mixed buffers (IBufferMixedStreamCapability), readBytes can either return null or throw a ReadException when not enough bytes are available until the first non-stringable / non-sizable object, depending on throwOnImpossibleRead flag

interface IBufferByteReadCapability extends IBufferBaseCapability, IBufferByteSizeCapability
{
    public function readBytes($count, $exact = false, $throwOnImpossibleRead = false);
}

trait TBufferByteReadCapability
{
    public function readBytes($count, $exact = false, $throwOnImpossibleRead = false)
    {
        if ($count <= 0) return ''; # requested nothing to read, return empty string

        if ($this->skbSize == 0) {
            # nothing to read, request more data
            $this->skbRequestMoreData();
            return '';
        }

        if ($exact && ($this->skbSize < $count)) {
            # we cannot get enough bytes for exact read, check if we need to request more bytes
            if ($this->skbMaxSize < $count) {
                $this->skbExtendMaxSize($count); # request to temporarily extend the max buffer size to accomodate
            } else {
                $this->skbRequestMoreData(); # just request more data to be read (this speeds up socket polling)
            }
            return '';
        }

        # read until we reach count bytes size or hit non-sizable / non-stringable object
        # take care on all the following operations we do not update the buffer size and do it in bulk when the read completes, this really avoids a lot of processing
        $read = [];
        $readSize = 0;
        while (($readSize < $count) && (($data = $this->skbPopLeft(true, true)) !== false)) {
            # to process as bytes, the object must be sizable and stringable, it will be converted to string after the operation
            if (!is_string($data) && !is_scalar($data)) {
                if (!($data instanceof \ATL\Sockets\StringableBufferObject) || !($data instanceof \ATL\Sockets\SizableByteBufferObject)) {
                    # we cannot, return the one we just read and end it here
                    $this->skbPushLeft($data, true, true);
                    break;
                }
                $readSize += $data->skboGetSize();
            } else {
                $readSize += strlen($data);
            }
            $read[] = $data;
        };

        # did we read anything, or enough if reading exactly?
        if (($readSize == 0) || ($exact && ($readSize < $count))) {
            # nope, that is error that must be handled by the caller for mixed buffers
            while (($data = array_pop($read)) !== null) $this->skbPushLeft($data, true, true); # return everything back to the read buffer
            if ($throwOnImpossibleRead) throw new ReadException("Cannot read requested number of bytes because of special object present in the stream");
            return null;
        }

        # did we read more than enough?
        if ($readSize > $count) {
            # the last one needs to be split and part of it returned back to the buffer
            $data = (string) array_pop($read);
            $keep = strlen($data) + $readSize - $count; # the number of bytes to keep
            if ($keep <= 0) throw new \ErrorException("The number of bytes to leave in the buffer on the byte read operation is wrong");
            $readSize = $count; # that's how much we are really to remove from the buffer
            $read[] = substr($data, 0, $keep); # that what is to keep is kept
            $this->skbPushLeft(substr($data, $keep), true, true); # return what is remaining
            $this->skbSizeAdded(1, 0, true, $this::SKB_SIZE_OPERATION_OTHER); # just add 1 new element to the buffer without changing the data size (as we remove exactly how much we need accounting for the new element)
        }

        # okay, now it is time to really remove all the read portion from the buffer, coalesce the read into string and return it
        $this->skbSizeRemoved(count($read), $readSize, false, $this::SKB_SIZE_OPERATION_POP_LEFT);
        return implode('', $read);
    }
}

########
# delimited read capability provides setDelimiter(), readDelimited(), readDelimitedBulk()
# take care it requires IBufferBulkCapability and IBufferByteReadCapability, as it is meaningless for buffers that cannot support one and also relies on byte reads in corner cases
# this relies on proper hints sent to skbSizeRemoved/skbSizeAdded to reset delimiter scan position as it needs unchanging buffer contents to fast-forward
# normally readDelimited() returns a string (empty if nothing to read), and readDelimitedBulk() returns an array (empty if nothing to read), but they can also return null if the read is impossible
# take care readDelimited() uses scan buffer that can grow up to the requested delimited read maximum line length, absolutely take care it does not use a lot of memory
# the bulk read is actually not optimized at all as the bulk read routine is already very complex and just calls readDelimited in sequence until it can read nothing more, but it is here for convenience

interface IBufferDelimitedReadCapability extends IBufferBaseCapability, IBufferBulkCapability, IBufferByteReadCapability
{
    public function setDelimiter($delimiter);
    public function skbDelimitedReadScanReset();
    public function readDelimited($maxLineLength = PHP_INT_MAX, $throwOnImpossibleRead = false);
    public function readDelimitedBulk($maxLineLength = PHP_INT_MAX, $maxReadCount = PHP_INT_MAX, $throwOnImpossibleRead = false);
}

trait TBufferDelimitedReadCapability
{
    public $skbDelimitedReadDelimiter;
    public $skbDelimitedReadDelimiterLength;
    public $skbDelimitedReadLastScanPosition;
    public $skbDelimitedReadScanBuffer;
    public $skbDelimitedReadScanBufferLength;
    public $skbDelimitedReadScanBufferPosition;

    public function setDelimiter($delimiter)
    {
        $this->skbDelimitedReadDelimiter = (string) $delimiter;
        $this->skbDelimitedReadDelimiterLength = strlen($delimiter);
        $this->skbDelimitedReadScanReset();
    }

    public function skbDelimitedReadScanReset()
    {
        $this->skbDelimitedReadLastScanPosition = 0;
        $this->skbDelimitedReadScanBuffer = '';
        $this->skbDelimitedReadScanBufferLength = 0;
        $this->skbDelimitedReadScanBufferPosition = 0;
    }

    public function readDelimited($maxLineLength = PHP_INT_MAX, $throwOnImpossibleRead = false)
    {
        if ($this->skbCount == 0) {
            # empty buffer, request more data from the socket (this speeds up socket polling) and return nothing
            $this->skbRequestMoreData();
            return '';
        }

        if ($maxLineLength <= 0) return ''; # nothing to read because of maximum read length being weird

        if (($this->skbDelimitedReadDelimiter ?? '') === '') return $this->readBytes($maxLineLength, true, $throwOnImpossibleRead); # if no delimiter, we resort to readBytes
        if ($this->skbDelimitedReadScanBufferLength > $maxLineLength) return $this->readBytes($maxLineLength, true, $throwOnImpossibleRead); # if we already scanned beyond maximum line size, just rely on readBytes again

        # now this is tricky, the buffer may have not enough data to satisfy at least the delimiter read
        if ($this->skbDelimitedReadDelimiterLength > ($this->skbSize - $this->skbDelimitedReadScanBufferPosition)) {
            # in case the buffer is too small, we need to extend
            if ($this->skbSize == $this->skbMaxSize) {
                $this->skbExtendMaxSize($this->skbSize + $this->skbBlockSize); # request to temporarily extend the max buffer size to accomodate one more read block
            } else {
                $this->skbRequestMoreData(); # just request more data to be read (this speeds up socket polling)
            }
            return null;
        }

        # okay, we have new data to read, fast forward read buffer until current scan position, then continue scanning for the delimiter
        $position = 0;
        foreach ($this->skbData as $data) {
            if ($position >= $this->skbDelimitedReadLastScanPosition) {
                # we found a new data block, let us check if it is valid to read first
                if (!($data instanceof \ATL\Sockets\StringableBufferObject) || !($data instanceof \ATL\Sockets\SizableByteBufferObject)) {
                    if ($throwOnImpossibleRead) throw new ReadException("Cannot read delimited string because of special object present in the stream");
                    return null;
                }

                # append data block to the scan buffer and advance the remembered position
                $this->skbDelimitedReadScanBuffer .= $data;
                $this->skbDelimitedReadScanBufferLength += $this->skbGetDataSize($data);

                # check if the resulting scan buffer length can fit the delimiter, because if we cannot satisfy a delimiter read, there is no point to scan
                $dPos = null; # here we also check for us to exceed the maximum requested line length, and the cutdown procedure is the same, so we combine the two, the trick to dPos is second condition never being executed if the first one matches
                if (($this->skbDelimitedReadDelimiterLength <= ($this->skbDelimitedReadScanBufferLength - $this->skbDelimitedReadScanBufferPosition)) || ($this->skbDelimitedReadScanBufferLength >= ($dPos = $maxLineLength))) {
                    # scan this data block for the delimiter *AND* check if we per chance do exceed the maximum requested line length (the cutdown procedure is the same)
                    $rPos = $dPos; # we need the final cutdown position in case dPos is set to be equal to the maximum length position, otherwise it will be set later to delimiter position + delimiter length
                    if (($dPos = null) && (($dPos = strpos($data, $this->skbDelimitedReadDelimiter)) !== false)) { # if we already found us reaching max line length, we do not need to check for the delimiter and the dPos is already set here
                        # yay, found our delimiter or reached maximum length, now pop everything up to new current position from the stack (if the current position is 2, pop 0/1/2) silently
                        for ($i = 0; $i <= $position; $i++) $this->skbPopLeft(true, true);

                        # check if we need to return some part of scan buffer back
                        if ($rPos === null) $rPos = $dPos + $this->skbDelimitedReadDelimiterLength; # if we reached maximum length, rPos is already set to the cutdown position here, otherwise set it to delimiter position + delimiter length
                        if ($rPos != $this->skbDelimitedReadScanBufferLength) {
                            # yes, return the remaining read into the buffer silently like we do in readBytes
                            $this->skbPushLeft(substr($data, $rPos), true, true); # return what is remaining
                            $this->skbSizeAdded(1, 0, true, $this::SKB_SIZE_OPERATION_OTHER); # just add 1 new element to the buffer without changing the data size (as we remove exactly how much we need accounting for the new element)
                        }

                        # prepare the resulting read, reset scan, remove result from the buffer and return it
                        $result = substr($this->skbDelimitedReadScanBuffer, 0, $rPos);
                        $this->skbDelimitedReadScanReset();
                        $this->skbSizeRemoved($position, $rPos, false, $this::SKB_SIZE_OPERATION_POP_LEFT);
                        return $result;
                    } else {
                        # nay, adjust the buffer position to its end - (delimiter length - 1)
                        $this->skbDelimitedReadScanBufferPosition = $this->skbDelimitedReadScanBufferLength - $this->skbDelimitedReadDelimiterLength + 1;
                    }
                }
            }

            $position++;
        }

        # we scanned it all, but the delimiter is still not found, remember our position, request more data from the socket and return nothing
        $this->skbDelimitedReadLastScanPosition = $position;
        $this->skbRequestMoreData();
        return '';
    }

    public function readDelimitedBulk($maxLineLength = PHP_INT_MAX, $maxReadCount = PHP_INT_MAX, $throwOnImpossibleRead = false)
    {
        if ($this->skbCount == 0) {
            # empty buffer, request more data from the socket (this speeds up socket polling) and return nothing
            $this->skbRequestMoreData();
            return '';
        }

        if ($maxReadCount <= 0) return ''; # nothing to read because of maximum read count being weird

        # now this one is not optimized at all, we just rely on readDelimited() to do the trick
        $read = [];
        for ($i = 0; $i < $maxReadCount; $i++) {
            $data = $this->readDelimited($maxLineLength, false);
            if ($data === '') return $read; # nothing more to read, return the result
            if ($data === null) break; # if encountering impossible read, break out
        }

        if (empty($read)) {
            if ($throwOnImpossibleRead) throw new ReadException("Cannot read any delimited string in bulk because of special object present in the stream");
            return null;
        }

        return $read;
    }

    # monitor socket data manipulation, we can only tolerate right side additions, the rest causes scan reset
    protected function skbSizeRemoved($count, $size, $silent, $operationHint = null)
    {
        if ($this->skbDelimitedReadScanBufferLength != 0) $this->skbDelimitedReadScanReset(); # at least we can avoid calling that each bloody time
        parent::skbSizeRemoved($count, $size, $silent, $operationHint);
    }

    protected function skbSizeAdded($count, $size, $silent, $operationHint = null)
    {
        if (($operationHint != $this::SKB_SIZE_OPERATION_ADD_RIGHT) && ($this->skbDelimitedReadScanBufferLength != 0)) $this->skbDelimitedReadScanReset(); # we can tolerate right add and do not need to call this if no scan
        parent::skbSizeAdded($count, $size, $silent, $operationHint);
    }
}

########
# virtual capabilities (just indications and dependencies, no code)

interface IBufferMessageCapability { }; # indicates buffer is a general message buffer
interface IBufferDatagramCapability { }; # indicates buffer is a byte datagram buffer
interface IBufferStreamCapability { }; # indicates buffer is a byte stream buffer
interface IBufferMixedStreamCapability { }; # indicates byte buffer can have non-string messages occuring alongside normal stream, while much not different for normal reads, this may have severe implications i.e. byte/bulk string/delimited reads will always return null or throw exceptions if read cannot be fullfilled because next element cannot be read as string
