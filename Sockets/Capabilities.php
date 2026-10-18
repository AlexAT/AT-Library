<?php

namespace ATL\Sockets;

# Sockets capabilities provide user-side API and additional handling plus internal API that can be used to build final usable sockets over generic socket class
# When composing capabilities, compose them one by one into a chain of classes as capabilities can overwrite methods and properties incrementally
# When composing capabilities, compose them in the interface dependency order listed here, as otherwise you may overwrite it a wrong way and end with unexpected result
# Sockets capabilities provided here are mostly corresponding to the socket buffers capabilities provided by socket buffers as sockets act as intermediary between users and buffers

# For capabilities defined here, check Buffers/Capabilities.php for a precise explanation of what methods do
# The normal translation between buffer capabilities and socket capabilities is as follows:
  # A corresponding generic method to call specific buffer capability method, prefixed by "buffer", i.e. bufferRead(), bufferWrite(), bufferReadDelimitedBulk(), etc.
  # A method that calls the generic method for default read or write buffer, named after the buffer capability method, so read(), write(), readDelimitedBulk(), etc.
  # The signature of the generic method includes $id first which is a corresponding buffer ID to operate on
  # The signature of default buffer alias method corresponds to the buffer capability method signature

# A short list of provided capabilities, their corresponding buffer capabilities and methods follows

# SocketBaseCapability
  # BufferBaseReadCapability
    # bufferRead($readBufferID) / read()
    # bufferPeek($readBufferID) / peek()
    # bufferReturnRead($readBufferID, $data) / returnRead($data)
  # BufferBaseWriteCapability
    # bufferWrite($writeBufferID, $data) / write($data)

# SocketByteSizeCapability
  # BufferByteSizeCapability

# SocketFlushCapability
  # BufferFlushCapability
    # bufferFlush($writeBufferID) / flush()

# SocketBulkCapability
  # BufferBulkReadCapability
    # bufferReadBulk($readBufferID, $maxReadCount = infinite) / readBulk($maxReadCount = infinite)
    # bufferPeekBulk($readBufferID, $maxPeekCount = infinite) / peekBulk($maxPeekCount = infinite)
    # bufferReturnReadBulk($readBufferID, array $dataSet) / returnReadBulk(array $dataSet)
  # BufferBulkWriteCapability
    # bufferWriteBulk($writeBufferID, array $dataSet) / writeBulk(array $dataSet)

# SocketBulkStringReadCapability
  # BufferBulkStringReadCapability
    # bufferReadBulkString($readBufferID, $maxReadCount = infinite, $throwOnImpossibleRead = false) / readBulkString($maxReadCount = infinite, $throwOnImpossibleRead = false)
    # bufferPeekBulkString($readBufferID, $maxPeekCount = infinite, $throwOnImpossibleRead = false) / readBulkString($maxPeekCount = infinite, $throwOnImpossibleRead = false)

# SocketByteReadCapability
  # BufferByteReadCapability
    # bufferReadBytes($readBufferID, $count, $exact = false, $throwOnImpossibleRead = false) / readBytes($count, $exact = false, $throwOnImpossibleRead = false)
    # bufferReadBytesBulk($readBufferID, $count, $maxReadCount = infinite, $exact = false, $throwOnImpossibleRead = false) / readBytesBulk($count, $maxReadCount = infinite, $exact = false, $throwOnImpossibleRead = false)

# SocketDelimitedReadCapability
  # BufferDelimitedReadCapability
    # bufferSetDelimiter($readBufferID, $delimiter, $caseInsensitive = false) / setDelimiter($delimiter, $caseInsensitive = false)
    # bufferReadDelimited($readBufferID, $maxLineLength, $strict = true, $throwOnImpossibleRead = false) / readDelimited($maxLineLength, $strict = true, $throwOnImpossibleRead = false)
    # bufferReadDelimitedBulk($maxLineLength, $maxReadCount = infinite, $strict = true, $stopOnEmptyLine = false, $throwOnImpossibleRead = false) / readDelimitedBulk($maxLineLength, $maxReadCount = infinite, $strict = true, $stopOnEmptyLine = false, $throwOnImpossibleRead = false)

# Like buffer capabilities, there are indicative capabilities that do not provide any extensions to the buffer but are informative about the socket buffers type
# Take care these only may be used if both read and write default socket buffers follow or are subset of these capabilities, understating capabilities is ok, but overstating is not
# SocketMessageCapability
  # indicates default read/write buffers both conform to BufferMessageCapability, containing indivisible messages that cannot be read, sent or processed in parts
# SocketDatagramCapability
  # indicates default read/write buffers both conform to BufferDatagramCapability, containing byte-sized datagrams that are read, sent or processed as whole and not in parts
  # partial writes on such socket transport are normally reported via error event handlers, with the datagram considered written
# SocketStreamCapability
  # indicates default read/write buffers both conform to BufferStreamCapability, containing byte streams that can be read in arbitrary chunks, and contains no non-sizeable objects
  # normally means that socket transmits/receives the data in whatever size blocks transport uses, so data blocks sent and received will not match in size with the remote socket end
# SocketMixedStreamCapability
  # indicates default read/write buffers both conform to BufferMixedStreamCapability and also can contain some non-sizeable objects that need special handling

########
# SocketBaseCapability

interface ISocketBaseCapability
{
    public function read();
    public function write($data);
    public function peek();
    public function returnRead($data);

    public function bufferRead($id);
    public function bufferWrite($id, $data);
    public function bufferPeek($id);
    public function bufferReturnRead($id, $data);
}

trait TSocketBaseCapability
{
    public function bufferRead($id)
    {
        if (($buffer = $this->skReadBuffers[$id]) === null) throw new \LogicException("Attempted to access socket read buffer `{$id}` that does not exist");
        return $buffer->read();
    }
    public function read() { return $this->bufferRead($this::SK_DEFAULT_READ_BUFFER); }

    public function bufferWrite($id, $data)
    {
        if (($buffer = $this->skWriteBuffers[$id]) === null) throw new \LogicException("Attempted to access socket write buffer `{$id}` that does not exist");
        return $buffer->write($data);
    }
    public function write($data) { return $this->bufferWrite($this::SK_DEFAULT_WRITE_BUFFER, $data); }

    public function bufferPeek($id)
    {
        if (($buffer = $this->skReadBuffers[$id]) === null) throw new \LogicException("Attempted to access socket read buffer `{$id}` that does not exist");
        return $buffer->peek();
    }
    public function peek() { return $this->bufferPeek($this::SK_DEFAULT_READ_BUFFER); }

    public function bufferReturnRead($id, $data)
    {
        if (($buffer = $this->skReadBuffers[$id]) === null) throw new \LogicException("Attempted to access socket read buffer `{$id}` that does not exist");
        return $buffer->returnRead($data);
    }
    public function returnRead($data) { return $this->bufferReturnRead($this::SK_DEFAULT_READ_BUFFER); }
}

########
# SocketByteSizeCapability

interface ISocketByteSizeCapability extends ISocketBaseCapability
{
    const SK_READ_BUFFER_TYPE = '\\ATL\\Sockets\\ByteBuffer';
    const SK_WRITE_BUFFER_TYPE = '\\ATL\\Sockets\\ByteBuffer';
}

trait TSocketByteSizeCapability { }

########
# SocketFlushCapability

interface ISocketFlushCapability
{
    public function flush();
    public function bufferFlush($id);

    public function allFlush();
    public function royalFlush(); # why? just because it's fun, an alias of allFlush()
}

trait TSocketFlushCapability
{
    public function bufferFlush($id)
    {
        if (($buffer = $this->skWriteBuffers[$id]) === null) throw new \LogicException("Attempted to access socket write buffer `{$id}` that does not exist");
        return $buffer->flush();
    }
    public function flush() { return $this->bufferFlush($this::SK_DEFAULT_WRITE_BUFFER); }

    public function allFlush()
    {
        foreach ($this->skWriteBuffers as $id => $buffer) $buffer->flush();
    }
    public function royalFlush() { return $this->allFlush(); }
}

########
# SocketBulkCapability

interface ISocketBulkCapability extends ISocketBaseCapability
{
    public function readBulk($maxReadCount = PHP_INT_MAX);
    public function writeBulk($dataSet);
    public function peekBulk($maxPeekCount = PHP_INT_MAX);
    public function returnReadBulk($dataSet);

    public function bufferReadBulk($id, $maxReadCount = PHP_INT_MAX);
    public function bufferWriteBulk($id, $dataSet);
    public function bufferPeekBulk($id, $maxPeekcount = PHP_INT_MAX);
    public function bufferReturnReadBulk($id, $dataSet);
}

trait TSocketBulkCapability
{
    public function bufferReadBulk($id, $maxReadCount = PHP_INT_MAX)
    {
        if (($buffer = $this->skReadBuffers[$id]) === null) throw new \LogicException("Attempted to access socket read buffer `{$id}` that does not exist");
        return $buffer->readBulk($maxReadCount);
    }
    public function readBulk($maxReadCount = PHP_INT_MAX) { return $this->bufferReadBulk($this::SK_DEFAULT_READ_BUFFER, $maxReadCount); }

    public function bufferWriteBulk($id, $dataSet)
    {
        if (($buffer = $this->skWriteBuffers[$id]) === null) throw new \LogicException("Attempted to access socket write buffer `{$id}` that does not exist");
        return $buffer->writeBulk($dataSet);
    }
    public function writeBulk($dataSet) { return $this->bufferWriteBulk($this::SK_DEFAULT_WRITE_BUFFER, $dataSet); }

    public function bufferPeekBulk($id, $maxPeekCount = PHP_INT_MAX)
    {
        if (($buffer = $this->skReadBuffers[$id]) === null) throw new \LogicException("Attempted to access socket read buffer `{$id}` that does not exist");
        return $buffer->peekBulk($maxPeekCount);
    }
    public function peekBulk($maxPeekCount = PHP_INT_MAX) { return $this->bufferPeekBulk($this::SK_DEFAULT_READ_BUFFER, $maxPeekCount); }

    public function bufferReturnReadBulk($id, $dataSet)
    {
        if (($buffer = $this->skReadBuffers[$id]) === null) throw new \LogicException("Attempted to access socket read buffer `{$id}` that does not exist");
        return $buffer->returnReadBulk($dataSet);
    }
    public function returnReadBulk($dataSet) { return $this->bufferReturnReadBulk($this::SK_DEFAULT_READ_BUFFER, $dataSet); }
}

########
# SocketBulkStringReadCapability

interface ISocketBulkStringReadCapability extends ISocketBaseCapability, ISocketBulkCapability
{
    public function readBulkString($maxReadCount = PHP_INT_MAX, $throwOnImpossibleRead = false);
    public function peekBulkString($maxPeekCount = PHP_INT_MAX, $throwOnImpossibleRead = false);

    public function bufferReadBulkString($id, $maxReadCount = PHP_INT_MAX, $throwOnImpossibleRead = false);
    public function bufferPeekBulkString($id, $maxPeekCount = PHP_INT_MAX, $throwOnImpossibleRead = false);
}

trait TSocketBulkStringReadCapability
{
    public function bufferReadBulkString($id, $maxReadCount = PHP_INT_MAX, $throwOnImpossibleRead = false)
    {
        if (($buffer = $this->skReadBuffers[$id]) === null) throw new \LogicException("Attempted to access socket read buffer `{$id}` that does not exist");
        return $buffer->readBulkString($maxReadCount, $throwOnImpossibleRead);
    }
    public function readBulkString($maxReadCount = PHP_INT_MAX, $throwOnImpossibleRead = false) { return $this->bufferReadBulkString($this::SK_DEFAULT_READ_BUFFER, $maxReadCount, $throwOnImpossibleRead); }

    public function bufferPeekBulkString($id, $maxPeekCount = PHP_INT_MAX, $throwOnImpossibleRead = false)
    {
        if (($buffer = $this->skReadBuffers[$id]) === null) throw new \LogicException("Attempted to access socket read buffer `{$id}` that does not exist");
        return $buffer->peekBulkString($maxPeekCount, $throwOnImpossibleRead);
    }
    public function peekBulkString($maxPeekCount = PHP_INT_MAX, $throwOnImpossibleRead = false) { return $this->bufferPeekBulkString($this::SK_DEFAULT_READ_BUFFER, $maxPeekCount, $throwOnImpossibleRead); }
}

########
# SocketByteReadCapability

interface ISocketByteReadCapability extends ISocketBaseCapability, ISocketByteSizeCapability
{
    public function readBytes($count, $exact = false, $throwOnImpossibleRead = false);
    public function readBytesBulk($count, $maxReadCount = PHP_INT_MAX, $exact = false, $throwOnImpossibleRead = false);

    public function bufferReadBytes($id, $count, $exact = false, $throwOnImpossibleRead = false);
    public function bufferReadBytesBulk($id, $count, $maxReadCount = PHP_INT_MAX, $exact = false, $throwOnImpossibleRead = false);
}

trait TSocketByteReadCapability
{
    public function bufferReadBytes($id, $count, $exact = false, $throwOnImpossibleRead = false)
    {
        if (($buffer = $this->skReadBuffers[$id]) === null) throw new \LogicException("Attempted to access socket read buffer `{$id}` that does not exist");
        return $buffer->readBytes($count, $exact, $throwOnImpossibleRead);
    }
    public function readBytes($count, $exact = false, $throwOnImpossibleRead = false) { return $this->bufferReadBytes($this::SK_DEFAULT_READ_BUFFER, $count, $exact, $throwOnImpossibleRead); }

    public function bufferReadBytesBulk($id, $count, $maxReadCount = PHP_INT_MAX, $exact = false, $throwOnImpossibleRead = false)
    {
        if (($buffer = $this->skReadBuffers[$id]) === null) throw new \LogicException("Attempted to access socket read buffer `{$id}` that does not exist");
        return $buffer->readBytesBulk($count, $maxReadCount, $exact, $throwOnImpossibleRead);
    }
    public function readBytesBulk($count, $maxReadCount = PHP_INT_MAX, $exact = false, $throwOnImpossibleRead = false) { return $this->bufferReadBytesBulk($this::SK_DEFAULT_READ_BUFFER, $count, $maxReadCount, $exact, $throwOnImpossibleRead); }
}

########
# SocketDelimitedReadCapability

interface ISocketDelimitedReadCapability extends ISocketBaseCapability, ISocketBulkCapability, ISocketByteReadCapability
{
    public function setDelimiter($delimiter, $caseInsensitive = false);
    public function readDelimited($maxLineLength, $strict = true, $throwOnImpossibleRead = false);
    public function readDelimitedBulk($maxLineLength, $maxReadCount = PHP_INT_MAX, $strict = true, $stopOnEmptyLine = false, $throwOnImpossibleRead = false);

    public function bufferSetDelimiter($id, $delimiter, $caseInsensitive = false);
    public function bufferReadDelimited($id, $maxLineLength, $strict = true, $throwOnImpossibleRead = false);
    public function bufferReadDelimitedBulk($id, $maxLineLength, $maxReadCount = PHP_INT_MAX, $strict = true, $stopOnEmptyLine = false, $throwOnImpossibleRead = false);
}

trait TSocketDelimitedReadCapability
{
    public function bufferSetDelimiter($id, $delimiter, $caseInsensitive = false)
    {
        if (($buffer = $this->skReadBuffers[$id]) === null) throw new \LogicException("Attempted to access socket read buffer `{$id}` that does not exist");
        return $buffer->setDelimiter($delimiter, $caseInsensitive);
    }
    public function setDelimiter($delimiter, $caseInsensitive = false) { return $this->bufferSetDelimiter($this::SK_DEFAULT_READ_BUFFER, $delimiter, $caseInsensitive); }

    public function bufferReadDelimited($id, $maxLineLength, $strict = true, $throwOnImpossibleRead = false)
    {
        if (($buffer = $this->skReadBuffers[$id]) === null) throw new \LogicException("Attempted to access socket read buffer `{$id}` that does not exist");
        return $buffer->readDelimited($maxLineLength, $strict, $throwOnImpossibleRead);
    }
    public function readDelimited($maxLineLength, $strict = true, $throwOnImpossibleRead = false) { return $this->bufferReadDelimited($this::SK_DEFAULT_READ_BUFFER, $maxLineLength, $strict, $throwOnImpossibleRead); }

    public function bufferReadDelimitedBulk($id, $maxLineLength, $maxReadCount = PHP_INT_MAX, $strict = true, $stopOnEmptyLine = false, $throwOnImpossibleRead = false)
    {
        if (($buffer = $this->skReadBuffers[$id]) === null) throw new \LogicException("Attempted to access socket read buffer `{$id}` that does not exist");
        return $buffer->readDelimitedBulk($maxLineLength, $maxReadCount, $strict, $stopOnEmptyLine, $throwOnImpossibleRead);
    }
    public function readDelimitedBulk($maxLineLength, $maxReadCount = PHP_INT_MAX, $strict = true, $stopOnEmptyLine = false, $throwOnImpossibleRead = false) { return $this->bufferReadDelimitedBulk($this::SK_DEFAULT_READ_BUFFER, $maxLineLength, $maxReadCount, $strict, $stopOnEmptyLine, $throwOnImpossibleRead); }
}

########
# Virtual capabilities (just indications and dependencies, no code)

interface ISocketMessageCapability { }; # indicates socket is a general message socket, null is valid read/write for such and false result is used when no read is avaialble
interface ISocketDatagramCapability { }; # indicates socket is a byte datagram based socket, for these, null is normally returned from read operations when there is no data, and null is not a valid read/write
interface ISocketStreamCapability { }; # indicates socket is a byte stream based socket, again, null is normally returned from read operations when there is no data, and null is not a valid read/write
interface ISocketMixedStreamCapability { }; # indicates socket buffers can have non-string messages occuring alongside normal stream, while much not different for normal reads, this may have severe implications i.e. byte/bulk string/delimited reads will always return null or throw exceptions if read cannot be fullfilled because next element cannot be read as string
