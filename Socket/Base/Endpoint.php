<?php

namespace ATL\Socket;

# Socket endpoint is abstract non-Task based socket endpoint that emulates functions of regular socket
# Endpoint unique ID is supplied from its upstream multi-endpoint socket acceptor and is used for all calls towards one
# The point is, the data to be read from endpoint is to be provided from upstream acceptor (i.e. UDP acceptor) via addToReadBuffer()
# The data to be written is polled by the upstream acceptor, upstream acceptor is only informed about endpoint will to write data
# As endpoint is closely tied to the upstream acceptor data and state propagation methods, it requires no Task or polling at all
# Endpoint supports bulk read/write, byte read and delimited read capabilities, DatagramEndpoint supports only bulk read/write capabilities
# Take care endpoints cannot refuse data to be added to read buffer by ustream acceptor as it will stall the whole acceptor, so avoid buffer overgrowth
# Half-close operations technically work on endpoints
#  For read path half-close endpoint informs upstream acceptor and then discards all data after read is closed
#  For write path half-close, endpoint informs upstream acceptor and just stops accepting data from user code, returning false on write operations
#  It is not recommended to half-close endpoints for read as normal acceptor behavior is making new endpoint connection on new data for the same acceptor point while data written to old endpoint will still be sent
# Endpoint always starts in connected state, endpoint disconnect only happens after all read data is read and all write data is flushed to the upstream acceptor

interface IEndpoint extends \ATL\Socket
{
    # public API
    public function onEOF($owner, $callback, $runHandler = true, $addLast = false);
    
    # API for upstream socket acceptor
    public function endpointAddToReadBuffer();
    public function endpointPollWriteBuffer();
    public function endpointPartialWriteOccured($data, $length);
    public function endpointDisconnect();
    public function endpointAbort($errorText);
    public function endpointAcceptorAborted();
}

class EndpointBasePrototype extends \ATL\Socket implements \ATL\Socket\Capabilities\IBulk { use \ATL\Socket\Capabilities\TBulk; }
class EndpointBase extends \ATL\Socket\EndpointBasePrototype
{
    public $acceptor; # public to provide user code access to the public acceptor calls

    protected $skEndpointID; # internal endpoint ID assigned by the acceptor
    protected $skReaderWaitsForRead; # this requests reader hasData handler to be called on next data added to the read buffer
    protected $skEOFSent; # EOF state, can be set to true to prevent double-sending eof event in termination code, hasData may still be sent in duplicate
    
    public function __construct($acceptor, $endpointID, $socketAddress, $socketLocalName, $socketRemoteName, $connectEventArgs = null)
    {
        $this->acceptor = $acceptor;
        $this->skEndpointID = $endpointID;
        
        parent::__construct(); # calls base Socket constructor and socketSetup() code

        # these must be set late as skSocketSetup destroys them
        $this->socketAddress = $socketAddress;
        $this->socketLocalName = $socketLocalName;
        $this->socketRemoteName = $socketRemoteName;

        # we are starting in connected state and are invoking connect handlers on start
        $this->socketState = $this::STATE_CONNECTED;
        $this->skReaderWaitsForRead = true;
        if (!is_array($connectEventArgs)) {
            $this->ehInvokeEventHandlers('connect', $this, $this->socketRemoteName, $this->socketLocalName);
        } else {
            $this->ehInvokeEventHandlers('connect', $this, $this->socketRemoteName, $this->socketLocalName, $connectEventArgs);
        }
    }
    
    protected function skSocketSetup()
    {
        parent::skSocketSetup();

        $this->skEOFSent = false;
    }
    
    
    # public Socket API

    public function disconnect()
    {
        if ($this->socketState >= $this::STATE_DISCONNECTING) return false; # socket is already disconnecting or disconnected, nothing to do
        $this->socketState = $this::STATE_DISCONNECTING;
        $this->skSetWriteClosed();
        if ($this->skWriteBuffer->isEmpty())  {
            # nothing to write, we may transition to read flush state safely
            if (!$this->skReadBuffer->isEmpty()) {
                $this->socketState = $this::STATE_FLUSHING_READS;
            } else {
                # also nothing to read, we may safely disconnect from the acceptor
                $this->closeRead();
                if ($this->acceptor) $this->acceptor->endpointDisconnect($this->skEndpointID);
                return parent::disconnect();
            }
        }
        return true;
    }
    
    public function abort()
    {
        if ($this->acceptor) $this->acceptor->endpointDisconnect($this->skEndpointID);
        parent::abort();
    }
    
    public function closeRead()
    {
        if ($this->skReadOpen) {
            if ($this->acceptor) $this->acceptor->endpointReadClosed($this->skEndpointID);
            
            # invoke EOF handlers early
            $this->skEOFSent = true;
            $this->ehInvokeEventHandlers('eof', $this, $this->skReadRemaining); # invoke EOF handler for anyone who monitors socket input state explicitly
        }
        
        return parent::closeRead();
    }

    public function closeWrite()
    {
        if ($this->skWriteOpen)
            if ($this->acceptor) $this->acceptor->endpointWriteClosed($this->skEndpointID);
            
        return parent::closeWrite();
    }
    
    # internal Socket API
    
    protected function skReadResultNothing()
    {
        $this->skReaderWaitsForRead = true; # do not forget to call hasData on next read data added
        if ($this->socketState == $this::STATE_FLUSHING_READS) {
            # endpoint is in disconnecting state, just flushed the reads, finish the disconnect
            $this->closeRead();
            if ($this->acceptor) $this->acceptor->endpointDisconnect($this->skEndpointID);
            parent::disconnect();
        }
        return parent::skReadResultNothing();
    }

    protected function skReadResultSuccess($result)
    {
        if ($this->skReadBuffer->isEmpty()) {
            $this->skReaderWaitsForRead = true; # do not forget to call hasData on next read data added
            if ($this->socketState == $this::STATE_FLUSHING_READS) {
                # endpoint is in disconnecting state, just flushed the reads, finish the disconnect
                $this->closeRead();
                if ($this->acceptor) $this->acceptor->endpointDisconnect($this->skEndpointID);
                parent::disconnect();
            }
        }
        return parent::skReadResultSuccess($result);
    }

    protected function skPreWriteCheck($line)
    {
        if ($this->socketState >= $this::STATE_FLUSHING_READS) return false; # socket is disconnected and flushing reads, so no more writes are accepted
        if (($this->skCurrentWrite === null) && $this->skWriteBuffer->isEmpty())
            if ($this->acceptor) $this->acceptor->endpointWantsWrite($this->skEndpointID);
        return parent::skPreWriteCheck($line);
    }
    
    protected function skPreWriteBulkCheck($lines)
    {
        if ($this->socketState >= $this::STATE_FLUSHING_READS) return false; # socket is disconnected and flushing reads, so no more writes are accepted
        if (($this->skCurrentWrite === null) && $this->skWriteBuffer->isEmpty())
            if ($this->acceptor) $this->acceptor->endpointWantsWrite($this->skEndpointID);
        return parent::skPreWriteBulkCheck($lines);
    }

    # API for upstream socket acceptor

    public function endpointAddToReadBuffer()
    {
        if (!$this->skReadOpen)
            throw new \ErrorException("Acceptor attempted to add data to endpoint half-closed on read, new endpoint must be generated instead");
        $this->skReadBuffer->push($data);
        $this->skReadRemaining += strlen($data);
        if ($this->skReaderWaitsForRead) {
            $this->ehInvokeEventHandlers('hasData', $this, $this->skReadRemaining);
            $this->skReaderWaitsForRead = false;
        }
    }
    
    public function endpointPollWriteBuffer()
    {
        if ($this->skWriteBuffer->isEmpty()) {
            if ($this->socketState == $this::STATE_DISCONNECTING) {
                # endpoint is in disconnecting state, flushing writes, and we have nothing more to write
                if (!$this->skReadBuffer->isEmpty()) {
                    # transition to read flush state
                    $this->socketState = $this::STATE_FLUSHING_READS;
                } else {
                    # also nothing to read, we may safely disconnect from the acceptor
                    $this->closeRead();
                    if ($this->acceptor) $this->acceptor->endpointDisconnect($this->skEndpointID);
                    parent::disconnect();
                }
            }
            return null;
        }
        $result = $this->skWriteBuffer->shift();
        if (!is_object($result)) $this->skWriteRemaining -= strlen($result); # decrement skWriteRemaining for strings, objects are passed to acceptor as is
        return $result;
    }
    
    public function endpointPartialWriteOccured($data, $length)
    {
        throw new \ErrorException("Normal byte-based endpoint does not expect partial writes to occur");
    }
    
    # this one is graceful disconnect request from the acceptor, can only be called when we have nothing to be written
    public function endpointDisconnect()
    {
        if (!$this->skWriteBuffer->isEmpty())
            throw new \ErrorException("Endpoint cannot be disconnected by acceptor when it has outstanding writes");
        $this->acceptor = null;
        $this->closeRead();
        $this->disconnect();
    }
    
    # this one is called by acceptor when our endpoint is abruptly terminated due to some error or other reasons, error text can be provided by the acceptor
    public function endpointAbort($errorText)
    {
        $this->skLastErrorNo = $this::ERROR_GENERAL_FAILURE;
        $this->skLastError = $errorText ?? 'Socket acceptor has aborted this endpoint connection';
        $this->acceptor = null;
        $this->closeRead();
        $this->disconnect();
    }
    
    # this one is called by acceptor when acceptor is aborted (forced to terminate without waiting for endpoints to terminate)
    public function endpointAcceptorAborted()
    {
        $this->skLastErrorNo = $this::ERROR_GENERAL_FAILURE;
        $this->skLastError = 'Socket acceptor handling this endpoint has been aborted';
        $this->acceptor = null;
        $this->closeRead();
        $this->disconnect();
    }

    # simplified event handler addition support
    public function onEOF($owner, $callback, $runHandler = true, $addLast = false) { $this->addEventHandler($owner, 'eof', $callback, $runHandler, $addLast); }
}

class EndpointPrototype extends \ATL\Socket\EndpointBase implements \ATL\Socket\Capabilities\IReadBytes, \ATL\Socket\Capabilities\IDelimitedReads
{
    use \ATL\Socket\Capabilities\TReadBytes; # bring in readBytes capability
    use \ATL\Socket\Capabilities\TDelimitedReads; # bring in delimited reads capability
}

class Endpoint extends \ATL\Socket\EndpointPrototype { }

class DatagramEndpoint extends \ATL\Socket\EndpointBase
{
    public function endpointPartialWriteOccured($data, $length)
    {
        $this->ehInvokeEventHandlers('partialWrite', $this, $data, $length); # call our partialWrite handler if set so software knows partial write happened
    }

    public function onPartialWrite($owner, $callback, $runHandler = true, $addLast = false) { $this->addEventHandler($owner, 'partialWrite', $callback, $runHandler, $addLast); }
}
