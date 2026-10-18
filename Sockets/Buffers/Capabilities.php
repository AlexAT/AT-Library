<?php

namespace ATL\Sockets;

# Buffers capabilities provide user-side and additional internal data handling API for generic socket buffers, complementary to the transport API
# Take care that capabilities do specialize the buffers as buffers start doing different things, so socket capabilities API must follow the buffer type
# When composing capabilities, compose them one by one into a chain of classes as capabilities can overwrite methods and properties incrementally
# When composing capabilities, compose them in the interface dependency order listed here, as otherwise you may overwrite it a wrong way and end with unexpected result
# Take care that capabilities provide both read and write counterparts at once, but normally each buffer is only used one way, very weird issues may happen if it is not so

# Quick base capabilities public API documentation is all aggregated here, read the implementation for more details

# BufferBaseReadCapability
  # applicability: read buffers
# Public API:
# - read()
    # reads one data block from the buffer left side, returns false is there is nothing to read, automatically requests for more data if there is nothing to read
# - peek()
    # previews one data block from the buffer left side, returns false if there is nothing to preview
# - returnRead($data)
    # places one data block back to the buffer left side, returns true if operation succeeded (base implementation always returns true)

# BufferBaseWriteCapability
  # applicability: write buffers
# Public API:
# - write($data)
    # places one data block to the buffer right side, returns true if operation succeeded, false otherwise
    # false normally means the buffer is not writeable (anymore) - i.e. not yet open or already closed

# BufferByteSizeCapability
  # applicability: any buffers
# Details:
  # switches buffer size from data blocks to bytes, automatically calculates string blocks size, provides support for ISizableBufferObject objects in the buffer
  # non-sizable blocks in the buffer do not count against buffer size and are considered to be of zero length
  # this capability also prevents adding null blocks to the buffer, attempting to add such will result in exception
  # take care zero-length empty strings are perfectly valid data blocks and they can easily be encountered i.e. in UDP/other datagram buffers
# Parameters:
# - BLOCK_SIZE
    # recommended maximum read/write block size for transport implementations, not mandatory but is recommended to be honored
    # stored in skbBlockSize internally

# BufferFlushCapability
  # applicability: write buffers
# Public API:
# - flush()
    # requests urgent flush of the data accumulated in the buffer (may i.e. also enable TCP_NODELAY until buffer is empty for TCP sockets), not mandatory but is recommended to be honored
    # returns true if operation succeeded, false otherwise (i.e. when socket is not open or already closed, or when flushing is impossible / unsupported)
# Events:
# - flush
    # sent when flush() API method is invoked

# BufferBulkReadCapability
  # applicability: read buffers
  # requires: BufferBaseReadCapability
# Public API:
# - readBulk($maxReadCount = infinite)
    # reads up to maxReadCount blocks from the buffer left side into array, returns empty array if there is nothing to read, automatically requests more data if there is nothing to read
# - peekBulk($maxPeekCount = infinite)
    # previews up to maxPeekCount blocks from the buffer left side into array, returns empty array if there is nothing to preview
# - returnReadBulk(array $dataSet)
    # places an array of data blocks onto the buffer left side, the blocks are placed in reverse order, so the first block in array will be read first

# BufferBulkWriteCapability
  # applicability: write buffers
  # requires: BufferBaseWriteCapability
# Public API:
# - writeBulk(array $dataSet)
    # places an array of data blocks onto the buffer right side, the blocks are placed in array order so the first block in array will be sent first

# BufferBulkStringReadCapability
  # applicability: byte sized read buffers
  # requires: BufferBaseReadCapability, BufferByteSizeCapability
# Public API:
# - readBulkString($maxReadCount = infinite, $throwOnImpossibleRead = false)
    # reads up to maxReadCount blocks from the buffer left side, concatenates them and returns the result as string, returns empty string if there is nothing to read, automatically requests more data if there is nothing to read
    # take care that zero-sized blocks will not exist in the result because they evaluate to empty strings
    # in case non-sizable block/object is encountered in the mixed read stream and some sizable data before it is present, returns the data read up to the non-sizable block/object
    # in case non-sizable block/object is encountered and nothing can be read, either returns false, or if throwOnImpossibleRead is true, throws LengthException exception
# - peekBulkString($maxPeekCount = infinite, $throwOnImpossibleRead = false)
    # previews up to maxPeekCount blocks from the buffer left side, concatenates them and returns the result as string, returns empty string if there is nothing to read
    # take care that zero-sized blocks will not exist in the result because they evaluate to empty strings
    # in case non-sizable block/object is encountered in the mixed read stream and some sizable data before it is present, returns the data preview up to the non-sizable block/object
    # in case non-sizable block/object is encountered and nothing can be previewed, either returns false, or if throwOnImpossibleRead is true, throws LengthException exception

# BufferByteReadCapability
  # applicability: read buffers
  # requires: BufferBaseReadCapability, BufferByteSizeCapability, BufferBulkReadCapability
# Public API:
# - readBytes($count, $exact = false, $throwOnImpossibleRead = false)
    # reads up to count bytes from the buffer left side and returns bytes read as string, returns empty string if there is nothing to read, automatically requests more data if there is nothing to read
    # if exact = true, reads exactly count bytes from the buffer, or returns empty string if the requested amount of data cannot be read, automatically requests more data if the requested amount of data is not available
    # if more bytes are requested than buffer can contain (more than maximum buffer size), the buffer size is temporarily extended to accomodate for enough data and more data is requested
    # take care that zero-sized blocks will not exist in the result and do not count against read size because they evaluate to empty strings
    # take care that byte count must always be specified and has no default, also, due to the internal string buffering required, byte reads can cause temporary extra memory usage up to the count specified, with some excess up to the size of the last buffered data block
    # in case non-sizable block/object is encountered in the mixed read stream, some sizable data before it is present, and exact is false, returns the data read up to the non-sizable block/object
    # in case non-sizable block/object is encountered and there is nothing to read or exact number of bytes cannot be read with exact = true, either returns false, or if throwOnImpossibleRead is true, throws LengthException exception
# - readBytesBulk($count, $maxReadCount = infinite, $exact = false, $throwOnImpossibleRead = false)
    # bulk version of readBytes(), reads up to maxReadCount byte chunks of count size, if exact is true, all chunks will be exactly count byte sized, if exact is false, the last chunk may be shorter than specified count value
    # returns empty array if there is nothing to read or when exact is true and no exact size chunks can be read, automatically requests more data if nothing cannot be read
    # in case non-sizable block/object is encountered, the behavior is similar to readBytes() when no chunks can be read, when some chunks still can be, returns all chunks read up to the non-sizable block/object

# BufferDelimitedReadCapability
  # applicability: read buffers
  # requires: BufferBaseReadCapability, BufferByteSizeCapability, BufferBulkReadCapability, BufferByteReadCapability
# Public API:
# - setDelimiter($delimiter, $caseInsensitive = false)
    # sets the delimiter(s) to stop reading data at for readDelimited() and readDelimitedBulk(), multiple delimiters can be passed as an array
    # caseInsensitive = true makes delimited reads stop at delimiter of any character case, i.e. 'end' or 'END' or 'eNd', take care case conversion is UTF8-based, recommended to only be used when knowing exactly how this would work
    # in case of multiple overlapping delimiters, the shortest length prefix will match first (i.e. for \n and \r\n, both will match, but for \r and \r\n, \r\n will never match)
    # same applies to multiple inclusive delimiters, i.e. for ABCD and BC, ABCD will never match, in general, try to never ever use overlapping delimiters
# - readDelimited($maxLineLength, $strict = true, $throwOnImpossibleRead = false)
    # reads delimited string up to maxLineLength bytes from the buffer left side, and returns bytes read as string, returns empty string if there is nothing to read, automatically requests more data if there is nothing to read
    # delimited string means read stops when any delimiter set by setDelimiter is encountered in the data read, delimiter is returned at the end of the string
    # if maxLineLength is reached and strict is true, if the read stops in the middle of some delimiter, returns the read string without the delimiter to satisfy maxLineLength and not split the delimiter
    # if maxLineLength is reached and strict is false, returns maxLineLength bytes even if the read stops in the middle of some delimiter
    # take care maxLineLength cannot be less than maximum delimiter length, this is to make sure reads do not return empty strings when strict is true
    # if the delimiter is not found and maxLineLength is over the maximum buffer size, the buffer size is temporarily extended to accomodate for enough data and more data is requested
    # take care that zero-sized blocks will not exist in the result and do not count against read size because they evaluate to empty strings
    # take care maximum buffer size extension would be over the maxLineLength by the maximum size of delimiters supplied
    # take care delimited reads use special contents buffer internally to scan for the delimiter, thus maxLineLength always needs to be supplied to limit the memory usage and has no default
    # the buffered contents size usage may reach up to maxLineLength plus the maximum delimiter size, with some excess up to the size of the last buffered data block
    # in case non-sizable block/object is encountered in the mixed read stream, some sizable data before it is present, and strict is false, returns the data read up to the non-sizable block/object
    # in case non-sizable block/object is encountered and there is nothing to read, or strict is true and delimiter cannot be found, either returns false, or if throwOnImpossibleRead is true, throws LengthException exception
# - readDelimitedBulk($maxLineLength, $maxReadCount = infinite, $strict = true, $stopOnEmptyLine = false, $throwOnImpossibleRead = false)
    # bulk version of readDelimited(), reads up to maxReadCount delimited chunks of up to maxLineLength size, honoring strict parameter
    # returns empty array if there is nothing to read or delimiter could not be found / maximum line length was not reached, automatically requests more data if nothing cannot be read
    # in case non-sizable block/object is encountered, the behavior is similar to readDelimited() when no chunks can be read, when some chunks still can be, returns all chunks read up to the non-sizable block/object
    # stopOnEmptyLine also stops reading when an empty line (containing only the delimiter) is encountered, this is handy to i.e. easily implement not reading farther than HTTP headers, the last empty (delimiter only) line is returned in the read data

# There are also some indicative capabilities that do not provide any extensions to the buffer but are informative about the buffer type

# BufferMessageCapability
  # indicates the buffer does only contains indivisible messages (any scalars or objects or whatever) that cannot be read, sent or processed in parts
  # the messages can be byte-sized or non-sizeable, but this kind of buffer only handles whole messages and does not do any assumptions about their contents
# BufferDatagramCapability
  # indicates the buffer contains byte-sized datagrams (strings or stringable objects) that are intended to be read, sent or processed as whole and not in parts
  # normally means that partial writes on the buffer transport are errors that will be reported via error event handlers, with the datagram considered written
# BufferStreamCapability
  # indicates the buffer contains byte stream (strings or stringable objects) that can be read in arbitrary chunks, and contains no non-sizeable objects
  # normally means that buffer will transmit/receive the data in whatever size blocks transport uses, so data blocks sent and received will not match in size
# BufferMixedStreamCapability
  # technically equivalent to the BufferStreamCapability, but here buffer can also contain some non-sizeable objects that need special handling
  # normally means that readBytes()/readDelimited()/... methods that process data chunks of predetermined size may encounter impossible reads and return false (or throw exceptions if set to do so)

# Some capabilities also provide specific internal API that is documented here

# BufferByteReadCapability
# - readBytesBulkInternal($count, $maxReadCount = infinite, $exact = false, $throwOnImpossibleRead = false, $forceArray = false)
    # check readBytesBulk() explanation above for the details
    # forceArray works as such:
      # if forceArray is false and nothing is read, empty string is returned (except for the case of impossible read when false is returned)
      # if forceArray is false and only one chunk is read, the chunk is returned as string
      # in all other cases the return value is an array with the chunks read

# BufferDelimitedReadCapability
# - readDelimitedBulkInternal($maxLineLength, $maxReadCount = infinite, $strict = true, $stopOnEmptyLine = false, $throwOnImpossibleRead = false, $forceArray = false)
    # check readDelimitedBulk() explanation above for the details
    # forceArray works as such:
      # if forceArray is false and nothing is read, empty string is returned (except for the case of impossible read when false is returned)
      # if forceArray is false and only one chunk is read, the chunk is returned as string
      # in all other cases the return value is an array with the chunks read

########
# the very basic message/datagram buffer capability
# provides base read(), peek(), returnRead()

interface IBufferBaseReadCapability
{
    public function read(); # reads and removes single data block from the buffer (socket side)
    public function peek(); # peeks single data block from the buffer (socket side)
    public function returnRead($data); # returns single data block to the buffer to be read first, an opposite of read() (socket side)
}

trait TBufferBaseReadCapability
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
# the very basic message/datagram write buffer capability
# provides base write()

interface IBufferBaseWriteCapability
{
    public function write($data); # writes single data block to the buffer (socket side)
}

trait TBufferBaseWriteCapability
{
    public function write($data)
    {
        if (!$this->isWriteable()) return false; # writing to not open or closed buffer is a bad idea
        $this->skbAddRight($data);
        return true;
    }
}

########
# byte size buffer capability maintains byte size inside the buffer instead of message-based count
# take care that writing and returning nulls to byte-based buffers is not allowed
# suggested block size parameter may or may not be honored by the underlying transport, it is recommended but not mandatory to honor it
# on the other hand, maximum read buffer sizes are expected to be honored, in general transport must pause reads when buffer reaches its maximum
# write buffers can easily grow over the maximum size, and it is up to application to honor throttling the writes so they do not exceed

interface IBufferByteSizeCapability
{
    const SKB_PARAM_BLOCK_SIZE = 0x2100; # suggested maximum block size to be read or written into the buffer at once (transport side)
}

trait TBufferByteSizeCapability
{
    public $skbBlockSize; # suggested maximum block size to be read or written into the buffer at once

    protected function skbInitializeDefaults()
    {
        parent::skbInitializeDefaults();

        # here we change the defaults for size and watermarks and add block size
        # these defaults are more or less a middle ground for most of the network, file and other typical stream and datagram operations
        $this->skbRealMaxSize = $this->skbMaxSize = 262144;
        $this->skbLowWatermark = 65536;
        $this->skbRealHighWatermark = $this->skbHighWatermark = 196608;
        $this->skbExtendSizeBy = 65536;
    }

    protected function skbReadParameters()
    {
        $this->skbBlockSize = $this->skbParameters[$this::SKB_PARAM_BLOCK_SIZE] ?? $this->skbBlockSize;
    }

    protected function skbGetDataSize($data)
    {
        if ($data === null) throw new \UnexpectedValueException('Attempted to operate on null data block in the byte-sized socket buffer'); # cannot use nulls in the byte buffer
        if (is_scalar($data)) {
            # strings and other scalars are just string byte count in size, except booleans that are not allowed
            if (is_bool($data)) throw new \UnexpectedValueException('Attempted to operate on boolean data block in the buffer'); # cannot use booleans in the byte buffer
            return strlen($data);
        }
        if ($data instanceof \ATL\Sockets\ISizableByteBufferObject) return $data->skboGetSize(); # sizable byte buffer objects can get us their own size
        return 0; # anything not sizable does not count against the buffer size
    }
}

########
# flush capability provides flush() request method and corresponding event invocation

interface IBufferFlushCapability
{
    const SKB_EVENT_FLUSH = 0x0100; # called when urgent buffer flush is requested (normally handled transport side)

    public function flush(); # called to request buffer to flush the data urgently (socket side)
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
# bulk read capability provides readBulk(), peekBulk() and returnReadBulk(), reads always return an array, empty array if there is nothing to read
# technically should be applicable for every socket read buffer as base capability, but placed here just in case and because it's slightly more complex than normal operations

interface IBufferBulkReadCapability extends IBufferBaseReadCapability
{
    public function readBulk($maxReadCount = PHP_INT_MAX); # reads and removes up to maxReadCount data blocks from the buffer (socket side)
    public function peekBulk($maxPeekCount = PHP_INT_MAX); # peeks up to maxReadCount data blocks from the buffer (socket side)
    public function returnReadBulk($dataSet); # returns an array of data blocks to the buffer to be read, an opposite of readBulk(), the first element will be read first again (socket side)
}

trait TBufferBulkReadCapability
{
    public function readBulk($maxReadCount = PHP_INT_MAX)
    {
        if ($maxReadCount <= 0) return []; # nothing to read because of maximum read count being weird

        if ($this->skbCount == 0) {
            # empty buffer, request more data from the socket (this speeds up socket polling) and return nothing
            $this->skbRequestMoreData();
            return [];
        }

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

    public function peekBulk($maxPeekCount = PHP_INT_MAX)
    {
        if (($this->skbCount == 0) || ($maxPeekCount <= 0)) return []; # nothing to peek because of empty buffer or maximum read count, we do not request more data here because we don't need to, we are just peeking

        $peek = [];
        $peekCount = 0;
        foreach ($this->skbData as $data) {
            $peek[] = $data;
            if (++$peekCount >= $maxPeekCount) break;
        }
        return $peek;
    }

    public function returnReadBulk($dataSet)
    {
        $returnSize = 0;
        $data = end($dataSet);
        while ($data !== false) {
            $returnSize += $this->skbGetDataSize($data);
            $this->skbAddLeft($data, true, true);
            $data = prev($dataSet);
        }
        $this->skbSizeAdded(count($dataSet), $returnSize, false, $this::SKB_SIZE_OPERATION_ADD_LEFT);
        return true;
    }
}

########
# bulk write capability provides writeBulk()
# technically should be applicable for every socket write buffer as base capability, but placed here just in case and because it's slightly more complex than normal operations

interface IBufferBulkWriteCapability extends IBufferBaseWriteCapability
{
    public function writeBulk($dataSet); # writes an array of data blocks to the buffer (socket side)
}

trait TBufferBulkWriteCapability
{
    public function writeBulk($dataSet)
    {
        if (!$this->isWriteable()) return false; # writing to not open or closed buffer is a bad idea

        $writeSize = 0;
        foreach ($dataSet as $data) {
            $writeSize += $this->skbGetDataSize($data);
            $this->skbAddRight($data, true, true);
        }
        $this->skbSizeAdded(count($dataSet), $writeSize, false, $this::SKB_SIZE_OPERATION_ADD_RIGHT);
        return true;
    }
}

########
# bulk string read capability provides readBulkString() and peekBulkString() like bulk capability, reads normally return string (empty string if there is nothing to read), but can also return false if the read is impossible

interface IBufferBulkStringReadCapability extends IBufferBaseReadCapability, IBufferByteSizeCapability
{
    public function readBulkString($maxReadCount = PHP_INT_MAX, $throwOnImpossibleRead = false); # reads and removes up to maxReadCount data blocks from the buffer, returning result as concatenated string (socket side)
    public function peekBulkString($maxPeekCount = PHP_INT_MAX, $throwOnImpossibleRead = false); # peeks up to maxReadCount data blocks from the buffer, returning result as concatenated string (socket side)
}

trait TBufferBulkStringReadCapability
{
    public function readBulkString($maxReadCount = PHP_INT_MAX, $throwOnImpossibleRead = false)
    {
        if ($maxReadCount <= 0) return ''; # nothing to read because of maximum read count being weird

        if ($this->skbCount == 0) {
            # empty buffer, request more data from the socket (this speeds up socket polling) and return nothing
            $this->skbRequestMoreData();
            return '';
        }

        $read = [];
        $readSize = 0;
        for ($i = 0; $i < $maxReadCount; $i++) {
            if (($data = $this->skbPopLeft(true, true)) === false) break;
            if (!is_string($data) && !is_scalar($data)) {
                if (!($data instanceof \ATL\Sockets\StringableBufferObject)) {
                    # we reached an object that cannot be converted to string, return it back, if we didn't read anything at all, this is an impossible read
                    $this->skbAddLeft($data, true, true);
                    if (empty($read)) {
                        if ($throwOnImpossibleRead) throw new \LengthException("Cannot read anything in bulk as string because of special object present in the stream");
                        return false;
                    }
                    break;
                }
            }
            $readSize += $this->skbGetDataSize($data);
            $read[] = $data;
        }
        $this->skbSizeRemoved(count($read), $readSize, false, $this::SKB_SIZE_OPERATION_POP_LEFT);
        return implode('', $read);
    }

    public function peekBulkString($maxPeekCount = PHP_INT_MAX, $throwOnImpossibleRead = false)
    {
        if (($this->skbCount == 0) || ($maxPeekCount <= 0)) return ''; # nothing to peek because of empty buffer or maximum read count, we do not request more data here because we don't need to, we are just peeking

        $peek = [];
        $peekCount = 0;
        foreach ($this->skbData as $data) {
            if (!is_string($data) && !is_scalar($data)) {
                if (!($data instanceof \ATL\Sockets\StringableBufferObject)) {
                    # we reached an object that cannot be converted to string, if we didn't peek anything at all, this is an impossible peek
                    if (empty($peek)) {
                        if ($throwOnImpossibleRead) throw new \LengthException("Cannot peek anything in bulk as string because of special object present in the stream");
                        return false;
                    }
                    break;
                }
            }
            $peek[] = $data;
            if (++$peekCount >= $maxPeekCount) break;
        }
        return implode('', $peek);
    }
}

########
# byte read capability provides readBytes() and readBytesBulk(), requires buffer to be byte-sized, readBytes() normally returns some string (empty string if there is nothing to read), readBytesBulk() returns an array of readBytes() strings
# take care that when using mixed buffers (IBufferMixedStreamCapability), readBytes can either return false or throw a ReadException when not enough bytes are available until the first non-stringable / non-sizable object, depending on throwOnImpossibleRead flag

interface IBufferByteReadCapability extends IBufferBaseReadCapability, IBufferByteSizeCapability, IBufferBulkReadCapability
{
    public function readBytes($count, $exact = false, $throwOnImpossibleRead = false); # reads up to or exactly count bytes from the buffer, returns empty string if there is nothing to read or not enough bytes to read (socket side)
    public function readBytesBulk($count, $maxReadCount = PHP_INT_MAX, $exact = false, $throwOnImpossibleRead = false); # reads up to maxReadCount strings in the same flavor readBytes() does, last read depends on exact setting (socket side)

    # protected function readBytesBulkInternal($count = PHP_INT_MAX, $maxReadCount = PHP_INT_MAX, $exact = false, $throwOnImpossibleRead = false, $forceArray = false)
}

trait TBufferByteReadCapability
{
    public function readBytes($count, $exact = false, $throwOnImpossibleRead = false)
    {
        if ($count <= 0) return ''; # requested nothing to read, return empty string

        # readBytes() is just a bulk read with maxReadCount=1 and forceArray=false, the only thing we need to check is impossible read as we want different exception text here
        $result = $this->readBytesBulkInternal($count, 1, $exact, false, false);
        if ($result === false) {
            # encountered impossible read that does not allow us to read literally anything, we need to throw or return false as well
            if ($throwOnImpossibleRead) throw new \LengthException("Cannot read requested number of bytes because of special object present in the stream");
            return false;
        }
        return $result;
    }

    public function readBytesBulk($count, $maxReadCount = PHP_INT_MAX, $exact = false, $throwOnImpossibleRead = false)
    {
        # readBytesBulk() is a direct alias of readBytesBulkInternal() with forceArray=true
        return $this->readBytesBulkInternal($count, $maxReadCount, $exact, $throwOnImpossibleRead, true);
    }

    protected function readBytesBulkInternal($count, $maxReadCount = PHP_INT_MAX, $exact = false, $throwOnImpossibleRead = false, $forceArray = false)
    {
        if ($count <= 0) return $forceArray ? [] : ''; # requested nothing to read, return nothing
        if ($maxReadCount <= 0) return $forceArray ? [] : ''; # requested zero count to read, return nothing

        if ($this->skbSize == 0) {
            # nothing to read, request more data
            $this->skbRequestMoreData();
            return $forceArray ? [] : '';
        }

        if ($exact && ($this->skbSize < $count)) {
            # we cannot get enough bytes for exact read, check if we need to request more bytes
            if ($this->skbRealMaxSize < $count) $this->skbExtendMaxSize($count, $count); # request to temporarily extend the max buffer size to accomodate, also sett temporary high watermark to the target count to facilitate immediate notification when the required amount is reached
            $this->skbRequestMoreData(); # request more data to be read (this speeds up socket polling)
            return $forceArray ? [] : '';
        }

        $read = null; $readSize = 0; $readCount = 0;
        $stream = ''; $streamSize = 0; $streamPosition = 0;
        $blockCount = 0; $removeCount = 0; $lastBlockRemainder = 0;
        foreach ($this->skbData as $data) {
            # to process as bytes, the object must be sizable and stringable, it will be converted to string after the operation
            if (!is_string($data) && !is_scalar($data)) {
                if (!($data instanceof \ATL\Sockets\StringableBufferObject) || !($data instanceof \ATL\Sockets\ISizableByteBufferObject)) {
                    # we cannot, return the one we just read and end it here
                    $this->skbAddLeft($data, true, true);
                    goto endRead; # and out of the loop to check for impossible read and return the unread remainder if exact read is requested
                }
            }

            # add block to the currently running data set, to the block history on exact reads, increase block count and remember the last block size
            # this is a little optimization against copying the data for the first concatenation
            if ($streamSize == 0) {
                $stream = (string) $data;
            } else {
                $stream .= (string) $data;
            }
            $streamSize += strlen($data);
            $blockCount++;

            # process all byte blocks we can infer from the currently added block
            while (($streamSize - $streamPosition) >= $count) {
                # finally, the data length is equal to or over the count of bytes we requested, add bytes to the read
                # here it is a little optimization to make forceArray work optimally (works best for single-result readBytes)
                if ($read === null) {
                    $read = substr($stream, $streamPosition, $count);
                } elseif ($readCount != 1) {
                    $read[] = substr($stream, $streamPosition, $count);
                } else {
                    $read = [$read, substr($stream, $streamPosition, $count)];
                }
                $readSize += $count;
                $readCount++;
                $streamPosition += $count; # move to the next position
                $removeCount = $blockCount; # set block removal count to the current block count
                $lastBlockRemainder = $streamSize - $streamPosition; # remember remainder size of the last block to return down
                if ($readCount == $maxReadCount) goto endRead; # finish reading if we read enough
            }

            # now cut the active data remainder up to the data position, indicate we consumed all the blocks and continue the process
            # do not shrink the scan buffer if we cannot cut it at least in half though
            if (($streamPosition != 0) && ($streamPosition >= ($streamSize >> 1))) {
                $stream = ($streamPosition != $streamSize) ? substr($stream, $streamPosition) : '';
                $streamSize -= $streamPosition;
                $streamPosition = 0;
            }
        }

        # read ends there
endRead:
        $realRemoveCount = $removeCount; # remember count of blocks to be really removed as we may alter it during the process

        # if the read was not exact and there is something in the stream remaining, we may safely consume all the remainder as final read unless we have already reached the maximum count
        if (!$exact && ($streamSize != 0) && ($readCount != $maxReadCount)) {
            # the same optimization to make forceArray work optimally (works best for single-result readBytes) applies here
            if ($read === null) {
                $read = $stream;
            } elseif ($readCount != 1) {
                $read[] = $stream;
            } else {
                $read = [$read, $stream];
            }
            $readSize += $streamSize;
            $realRemoveCount = $removeCount = $blockCount; # make sure we remove all the read blocks
            goto finalizeRead; # go to the size adjustment directly, there is nothing more left to do as the read is not empty anymore and we have nothing to return
        }

        # if the read is empty, this can only be due to us encountering an impossible read before reading anything, handle the situation
        if ($read === null) {
            if ($throwOnImpossibleRead) throw new \LengthException("Cannot read any requested number of bytes in bulk because of special object present in the stream");
            return false;
        }

        # the read is non-empty, so we may have remaining data from the last block to return to the buffer instead of the last block, do it if so
        if ($lastBlockRemainder != 0) {
            # part of the last block left, return it instead of the last block
            for ($i = 0; $i < $realRemoveCount; $i++) $this->skbPopLeft(true, true); # remove all blocks necessary
            $realRemoveCount = 0; # we removed everything, so nothing to do later anymore
            $this->skbAddLeft(substr($stream, $streamPosition, $lastBlockRemainder), true, true); # return what is remaining back to the buffer
            $this->skbSizeAdded(1, 0, true, $this::SKB_SIZE_OPERATION_OTHER); # just add 1 new element to the buffer without changing the data size (as we remove exactly how much we read accounting for the new element), using 'other' operation here also makes all monitors reset their states
        }

finalizeRead:
        # here we have finally built the result and returned back everything we needed to return, now really remove the necessary block count and size from the buffer and return the real result down the drain
        for ($i = 0; $i < $realRemoveCount; $i++) $this->skbPopLeft(true, true); # really remove all blocks necessary
        if (($removeCount != 0) || ($readSize != 0)) # the condition may seem a bit tricky, but remember empty strings can appear in the buffer
            $this->skbSizeRemoved($removeCount, $readSize, false, $this::SKB_SIZE_OPERATION_POP_LEFT); # remove count and size from the read buffer state in bulk
        return (!$forceArray || is_array($read)) ? ($read ?? '') : (($read === null) ? [] : [$read]);
    }
}

########
# delimited read capability provides setDelimiter(), readDelimited(), readDelimitedBulk()
# take care it requires IBufferByteReadCapability, as it is meaningless for buffers that cannot support one and also relies on byte reads in corner cases
# it also relies on proper hints sent to skbSizeRemoved/skbSizeAdded to reset delimiter scan position as it needs unchanging buffer contents to fast-forward
# normally readDelimited() returns a string (empty if nothing to read), and readDelimitedBulk() returns an array (empty if nothing to read), but they can also return false if the read is impossible
# take care readDelimited() uses scan buffer that can grow up to the requested delimited read maximum line length, absolutely take care it does not use a lot of memory
# take care delimited reads normally return delimiter encountered at the end of each string, except if maximum read length is reached

# if the end of the data and maximum line length are both reached, the read routines may return the line below maximum line length by the size of delimiter - 1, to make sure the delimiter can potentially still be found in the next call
# passing strict=false can be used to override that, but this means line read can end mid-delimiter if the delimiter is larger than 1 byte and so delimiter will be split between 2 lines read in this not exactly obvious corner case
# also in case of strict=false, if some non-stringable object exists in the stream, delimited reads will consider it being a line end and will read all the data up to the object, while with strict=true it would be an impossible read
# take care that in the default case of strict=true delimited reads, the read may be reading nothing forever even if some data remains in the buffer, but is less than delimiter in length, and no new data comes to satisfy the read
# in case we are running in strict=false mode, impossible reads are only returned when we really have completely nothing to read due to the

# when using multiple delimiters, take care the shortest delimiter always matches first, so multiple delimiters can be suffixes of other delimiters, but not prefixes or inclusions
# i.e. when supplying \n and \r\n (B and AB), all is ok, but if supplying \r and \r\n (A and AB), \r\n (AB) will never match, if i.e. B and ABC is supplied, ABC will never match
# strict reads always try to leave space for the longest supplied delimiter when reading up to max line length

# the delimited read logic mostly follows the byte read logic, except it additionally scans for a delimiter instead of just checking length and also tracks scanning state in the buffer object

interface IBufferDelimitedReadCapability extends IBufferBaseReadCapability, IBufferByteSizeCapability, IBufferBulkReadCapability, IBufferByteReadCapability
{
    const SKB_SIZE_OPERATION_POP_LEFT_DELIMITED_READ_REMOVAL = 0x100; # additional operation hint code to indicate removing data that does not affect our own scan operation
    const SKB_SIZE_OPERATION_ADD_LEFT_DELIMITED_READ_REMAINDER = 0x0101; # additional operation hint code to indicate adding data remainder that does not affect our own scan operations

    public function setDelimiter($delimiter, $caseInsensitive = false); # sets the delimiter to split delimited reads from the buffer by (socket side)
    public function readDelimited($maxLineLength, $strict = true, $throwOnImpossibleRead = false); # reads next string up to delimiter or maxLineLength bytes, the delimiter is also returned in result if encountered (socket side)
    public function readDelimitedBulk($maxLineLength, $maxReadCount = PHP_INT_MAX, $strict = true, $stopOnEmptyLine = false, $throwOnImpossibleRead = false); # reads up to maxReadCount strings in the same flavor readDelimited() does (socket side)

    # protected function readDelimitedInternal($maxLineLength = PHP_INT_MAX, $maxReadCount = PHP_INT_MAX, $strict = true, $throwOnImpossibleRead = false, $forceArray = false)
    # protected function skbDelimitedReadScanReset(); # resets delimited reads scan position (internal handler)
}

trait TBufferDelimitedReadCapability
{
    public $skbDelimitedReadDelimiters;
    public $skbDelimitedReadDelimiterMatch;
    public $skbDelimitedMinReadDelimiterLength;
    public $skbDelimitedMaxReadDelimiterLength;
    public $skbDelimitedReadLastScanPosition;
    public $skbDelimitedReadScanBuffer;
    public $skbDelimitedReadScanBufferLength;
    public $skbDelimitedReadScanBufferPosition;

    public function setDelimiter($delimiters, $caseInsensitive = false)
    {
        if ($delimiters !== null) {
            $this->skbDelimitedReadDelimiters = is_array($delimiters) ? $delimiters : [$delimiters];
            usort($this->skbDelimitedReadDelimiters, function ($a, $b) { return strlen($a) <=> strlen($b); }); # sort delimiters by length as we need an ungreedy match
            $this->skbDelimitedMinReadDelimiterLength = strlen(reset($this->skbDelimitedReadDelimiters));
            $this->skbDelimitedMaxReadDelimiterLength = strlen(end($this->skbDelimitedReadDelimiters));
            $this->skbDelimitedReadDelimiterMatch = '#'.implode('|', array_map(function ($s) { return preg_quote($s, '#'); }, $this->skbDelimitedReadDelimiters)).'#UsS'.($caseInsensitive ? 'i' : '');
        } else {
            $this->skbDelimitedReadDelimiters = null;
            $this->skbDelimitedReadDelimiterMatch = null;
            $this->skbDelimitedMinReadDelimiterLength = 0;
            $this->skbDelimitedMaxReadDelimiterLength = 0;
        }
        $this->skbDelimitedReadScanReset();
    }

    protected function skbDelimitedReadScanReset()
    {
        $this->skbDelimitedReadLastScanPosition = 0; # basically no scan position, the very first block will be read in full
        $this->skbDelimitedReadScanBuffer = '';
        $this->skbDelimitedReadScanBufferLength = 0;
        $this->skbDelimitedReadScanBufferPosition = 0;
    }

    public function readDelimited($maxLineLength, $strict = true, $throwOnImpossibleRead = false)
    {
        # readDelimited() is just a bulk read with maxReadCount=1 and forceArray=false, the only thing we need to check is impossible read as we want different exception text here
        $result = $this->readDelimitedBulkInternal($maxLineLength, 1, $strict, false, false, false);
        if ($result === false) {
            # encountered impossible read that does not allow us to read literally anything, we need to throw or return false as well
            if ($throwOnImpossibleRead) throw new \LengthException("Cannot read delimited string because of special object present in the stream");
            return false;
        }
        return $result;
    }

    public function readDelimitedBulk($maxLineLength, $maxReadCount = PHP_INT_MAX, $strict = true, $stopOnEmptyLine = false, $throwOnImpossibleRead = false)
    {
        # readDelimitedBulk() is a direct alias of readDelimitedBulkInternal() with forceArray=true
        return $this->readDelimitedBulkInternal($maxLineLength, $maxReadCount, $strict, $stopOnEmptyLine, $throwOnImpossibleRead, true);
    }

    protected function readDelimitedBulkInternal($maxLineLength, $maxReadCount = PHP_INT_MAX, $strict = true, $stopOnEmptyLine = false, $throwOnImpossibleRead = false, $forceArray = false)
    {
        if ($maxReadCount <= 0) return $forceArray ? [] : ''; # requested zero count to read, return nothing
        if ($maxLineLength < $this->skbDelimitedMaxReadDelimiterLength) $maxLineLength = $this->skbDelimitedMaxReadDelimiterLength; # requested weird maximum line length to be read, reset it to delimiter length
        if ($this->skbDelimitedReadDelimiters === null) return $this->readBytesBulk(min(1, $maxLineLength), $maxReadCount, $strict, $throwOnImpossibleRead); # if no delimiter, we resort to readBytes and also avoid the situation where maxLineLength can be 0 under this condition

        if ($this->skbSize == 0) {
            # nothing to read, request more data
            $this->skbRequestMoreData();
            return $forceArray ? [] : '';
        }

        $read = null; $readSize = 0; $readCount = 0; $endRead = false;
        $blockCount = 0; $removeCount = 0; $lastBlockRemainder = 0;
        $impossibleReadEncountered = false; # this flag is necessary to correctly alter non-strict mode behavior after the read
        foreach ($this->skbData as $data) {
            # to process as bytes, the object must be sizable and stringable, it will be converted to string after the operation
            if (!is_string($data) && !is_scalar($data)) {
                if (!($data instanceof \ATL\Sockets\StringableBufferObject) || !($data instanceof \ATL\Sockets\ISizableByteBufferObject)) {
                    # we cannot, return the one we just read, set the impossible read flag and end it here
                    $this->skbAddLeft($data, true, true);
                    $impossibleReadEncountered = true;
                    goto endRead; # and out of the loop to check for impossible read and return the unread remainder if exact read is requested
                }
            }

            # increase block count, if we still have not reached the last scan position, just go to the next block
            $blockCount++;
            if ($blockCount < $this->skbDelimitedReadLastScanPosition) goto nextBlock;

            # the block read is skipped if the block being processed is the current scan block (so we do process remainder of the current block)
            if ($blockCount != $this->skbDelimitedReadLastScanPosition) {
                # this is a new block, add this block to the currently running scan data set
                # this is a little optimization against copying the data for the first concatenation
                if ($this->skbDelimitedReadScanBufferLength == 0) {
                    $this->skbDelimitedReadScanBuffer = (string) $data;
                } else {
                    $this->skbDelimitedReadScanBuffer .= (string) $data;
                }
                $this->skbDelimitedReadScanBufferLength += strlen($data);
                $this->skbDelimitedReadLastScanPosition = $blockCount; # remember the new scan position
            }

            # scan for the delimiter and check for reaching the byte count, this happens only if we really have enough bytes to accomodate at least the tiniest delimiter
            if (($this->skbDelimitedReadScanBufferLength - $this->skbDelimitedReadScanBufferPosition) >= $this->skbDelimitedMinReadDelimiterLength) {
                while (
                    ($delimiterFound = preg_match($this->skbDelimitedReadDelimiterMatch, $this->skbDelimitedReadScanBuffer, $matches, PREG_OFFSET_CAPTURE, $this->skbDelimitedReadScanBufferPosition))
                    || (($this->skbDelimitedReadScanBufferLength - $this->skbDelimitedReadScanBufferPosition) >= $maxLineLength)
                ) {
                    # finally, we found up our delimiter or reached the maximum line length supplied, now we need to calculate correct read length
                    if (!$delimiterFound) {
                        # if we are running in strict mode and cannot be sure we are not encountering the delimiter in the next byte, we still need the next block
                        # so we determine the remainder length and check it to be at least one byte larger than the delimiter in strict mode, in non-strict, we just read
                        $readLength = $strict ? min($maxLineLength, $this->skbDelimitedReadScanBufferLength - $this->skbDelimitedReadScanBufferPosition - $this->skbDelimitedMaxReadDelimiterLength + 1) : $maxLineLength;
                    } else {
                        # we have found our delimiter, but we may also be reaching maximum line length here
                        # if we do, in strict mode we check maximum read to include the delimiter, if it does not, it reads up to the delimiter, but skips the delimiter itself
                        $delimiterLength = strlen($matches[0][0]); # matches[0][0] is found delimiter, matches[0][1] is found delimiter position
                        $readLength = $matches[0][1] - $this->skbDelimitedReadScanBufferPosition + $delimiterLength;
                        if ($readLength > $maxLineLength) {
                            # whoops, we also reached the maximum line length, for strict mode, clamp it to minimum of the read length without delimiter and available maximum length
                            # non-strict mode may end in the middle of delimiter here, take care
                            $readLength = $strict ? min($maxLineLength, $readLength - $delimiterLength) : $maxLineLength;
                        }
                    }

                    # now that the correct read length has been calculated, add resulting line to the read
                    # here it is a little optimization to make forceArray work optimally (works best for single-result readBytes)
                    if ($read === null) {
                        $read = ($readLength != $this->skbDelimitedReadScanBufferLength) ? substr($this->skbDelimitedReadScanBuffer, $this->skbDelimitedReadScanBufferPosition, $readLength) : $this->skbDelimitedReadScanBuffer;
                    } elseif ($readCount != 1) {
                        $read[] = ($readLength != $this->skbDelimitedReadScanBufferLength) ? substr($this->skbDelimitedReadScanBuffer, $this->skbDelimitedReadScanBufferPosition, $readLength) : $this->skbDelimitedReadScanBuffer;
                    } else {
                        $read = [$read, ($readLength != $this->skbDelimitedReadScanBufferLength) ? substr($this->skbDelimitedReadScanBuffer, $this->skbDelimitedReadScanBufferPosition, $readLength) : $this->skbDelimitedReadScanBuffer];
                    }
                    $readSize += $readLength;
                    $readCount++;
                    $this->skbDelimitedReadScanBufferPosition += $readLength; # move to the next scan position
                    $removeCount = $blockCount; # set block removal count to the current block count
                    $lastBlockRemainder = $this->skbDelimitedReadScanBufferLength - $this->skbDelimitedReadScanBufferPosition; # remember remainder size of the last block to return down
                    if (($readCount == $maxReadCount) || ($delimiterFound && ($readLength == $delimiterLength))) {
                        # finish scanning if we read enough or when requested to stop at empty line and one is read, but do not skip the persistent buffer shrink phase
                        $endRead = true;
                        goto finishScan;
                    }
                }

finishScan:
                # now cut the active data remainder up to the data position and continue the process
                # do not shrink the scan buffer if we cannot cut it at least in half though
                if (($this->skbDelimitedReadScanBufferPosition != 0) && ($this->skbDelimitedReadLastScanPosition >= ($this->skbDelimitedReadScanBufferLength >> 1))) {
                    $this->skbDelimitedReadScanBuffer = ($this->skbDelimitedReadScanBufferPosition != $this->skbDelimitedReadScanBufferLength) ? substr($this->skbDelimitedReadScanBuffer, $this->skbDelimitedReadScanBufferPosition) : '';
                    $this->skbDelimitedReadScanBufferLength -= $this->skbDelimitedReadScanBufferPosition;
                    $this->skbDelimitedReadScanBufferPosition = 0;
                }

                # if we are requested to end the read, end the read
                if ($endRead) goto endRead;
            }

nextBlock:
        }

        # read ends there
endRead:
        $realRemoveCount = $removeCount; # remember count of blocks to be really removed as we may alter it during the process

        # if we encountered impossible read, the read was not strict and there is something in the stream remaining, we may consume all the remainder unless we have already reached the maximum count
        if ($impossibleReadEncountered && !$strict && ($readCount != $maxReadCount) && (($this->skbDelimitedReadScanBufferLength - $this->skbDelimitedReadScanBufferPosition) != 0)) {
            # the same optimization to make forceArray work optimally (works best for single-result readBytes) applies here
            if ($read === null) {
                $read = ($this->skbDelimitedReadScanBufferPosition != 0) ? substr($this->skbDelimitedReadScanBuffer, $this->skbDelimitedReadScanBufferPosition) : $this->skbDelimitedReadScanBuffer;
            } elseif ($readCount != 1) {
                $read[] = ($this->skbDelimitedReadScanBufferPosition != 0) ? substr($this->skbDelimitedReadScanBuffer, $this->skbDelimitedReadScanBufferPosition) : $this->skbDelimitedReadScanBuffer;
            } else {
                $read = [$read, ($this->skbDelimitedReadScanBufferPosition != 0) ? substr($this->skbDelimitedReadScanBuffer, $this->skbDelimitedReadScanBufferPosition) : $this->skbDelimitedReadScanBuffer];
            }
            $readSize += $this->skbDelimitedReadScanBufferLength - $this->skbDelimitedReadScanBufferPosition;
            $realRemoveCount = $removeCount = $blockCount; # make sure we remove all the read blocks
            $this->skbDelimitedReadScanReset(); # as we just consumed it in full, just reset the scan parameters and the next scan will begin anew
            goto finalizeRead; # go to the size adjustment directly, there is nothing more left to do as the read is not empty anymore and we have nothing to return
        }

        # if the read is empty here, we may be hitting impossible read on a strict mode, so we need to check for this condition
        if (($read === null) && $strict && $impossibleReadEncountered) {
            if ($throwOnImpossibleRead) throw new \LengthException("Cannot read any requested number of bytes in bulk because of special object present in the stream");
            return false;
        }

        # we may have remaining data from the last block to return to the buffer instead of the last block, do it if so
        if ($lastBlockRemainder != 0) {
            # part of the last block left, return it instead of the last block
            for ($i = 0; $i < $realRemoveCount; $i++) $this->skbPopLeft(true, true); # remove all blocks necessary
            $realRemoveCount = 0; # we removed everything, so nothing to do later anymore
            $this->skbAddLeft(substr($this->skbDelimitedReadScanBuffer, $this->skbDelimitedReadScanBufferPosition, $lastBlockRemainder), true, true); # return what is remaining
            $this->skbSizeAdded(1, 0, true, $this::SKB_SIZE_OPERATION_ADD_LEFT_DELIMITED_READ_REMAINDER); # just add 1 new element to the buffer without changing the data size (as we remove exactly how much we read accounting for the new element), make sure we do not hurt further scans by doing so
            $this->skbDelimitedReadLastScanPosition++; # compensate delimiter scan position
        }

finalizeRead:
        # here we have finally built the result and returned back everything we needed to return, now really remove the necessary block count and size from the buffer and return the real result down the drain
        for ($i = 0; $i < $realRemoveCount; $i++) $this->skbPopLeft(true, true); # really remove all blocks necessary
        if (($removeCount != 0) || ($readSize != 0)) { # the condition may seem a bit tricky, but remember empty strings can appear in the buffer
            $this->skbSizeRemoved($removeCount, $readSize, false, $this::SKB_SIZE_OPERATION_POP_LEFT_DELIMITED_READ_REMOVAL);
            $this->skbDelimitedReadLastScanPosition -= $removeCount; # do not forget to move remembered scan position down as well
        }
        return (!$forceArray || is_array($read)) ? ($read ?? '') : (($read === null) ? [] : [$read]);
    }

    # monitor socket data manipulation, we can only tolerate right side additions, the rest causes scan reset
    protected function skbSizeRemoved($count, $size, $silent, $operationHint = null)
    {
        switch ($operationHint) {
            case $this::SKB_SIZE_OPERATION_POP_LEFT_DELIMITED_READ_REMOVAL:
            # do nothing on these operations, these are not hurting our internal scan state
            break;

            default:
            # reset our scan state if it is not at its defaults
            if ($this->skbDelimitedReadLastScanPosition != 0) $this->skbDelimitedReadScanReset();
            break;
        }

        return parent::skbSizeRemoved($count, $size, $silent, $operationHint);
    }

    protected function skbSizeAdded($count, $size, $silent, $operationHint = null)
    {
        switch ($operationHint) {
            case $this::SKB_SIZE_OPERATION_ADD_RIGHT:
            case $this::SKB_SIZE_OPERATION_ADD_LEFT_DELIMITED_READ_REMAINDER:
            # do nothing on these operations, these are not hurting our internal scan state
            break;

            default:
            # reset our scan state if it is not at its defaults
            if ($this->skbDelimitedReadLastScanPosition != 0) $this->skbDelimitedReadScanReset();
            break;
        }

        return parent::skbSizeAdded($count, $size, $silent, $operationHint);
    }
}

########
# virtual capabilities (just indications and dependencies, no code)

interface IBufferMessageCapability { }; # indicates buffer is a general message buffer, null is valid read/write for such and false result is used when no read is avaialble
interface IBufferDatagramCapability { }; # indicates buffer is a byte datagram buffer, for these, false is normally returned from read operations when there is no data, and null is not a valid read/write
interface IBufferStreamCapability { }; # indicates buffer is a byte stream buffer, again, false is normally returned from read operations when there is no data, and null is not a valid read/write
interface IBufferMixedStreamCapability { }; # indicates byte buffer can have non-string messages occuring alongside normal stream, while much not different for normal reads, this may have severe implications i.e. byte/bulk string/delimited reads will always return false or throw exceptions if read cannot be fullfilled because next element cannot be read as string
