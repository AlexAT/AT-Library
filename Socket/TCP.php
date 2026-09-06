<?php

namespace ATL\Socket;

# TCP socket which provides a single connection to single TCP endpoint
# Normally created as outbound connection oriented stream type socket, but is reused from inside TCPAcceptor socket to create individual sockets for incoming connections
# Can be self-polling task or Factory polled socket

# If you need TCP socket that can accept incoming connections or connect single outbound port to multiple destinations, look at TCPAcceptor socket instead

# Supported event handlers:
# - connect ($socket, $remoteAddress, $localAddress) - called when socket is connected
# - hasData ($socket, $readBufferSize) - called when there is more data available on the socket, called one extra time right after eof handler if some data is left to be read
# - writeEmpty ($socket) - called when write buffer becomes empty
# - eof ($socket, $readBufferSize) - called when remote side disconnects (so no more hasData events will be available), hasData handler is called once more right after eof event if some data is left to be read
# - disconnect ($socket) - called when socket completely disconnects, so no more reads/writes are possible
# - error ($socket, $errorCode, $errorText) - called when socket transitions to error state (usually coincides with disconnect by obvious reason)

class TCP extends \ATL\Socket\SocketClientStream
{
    public function __construct($host, $port, $connectTimeout = null, $contextOptions = null)
    {
        # TCP socket initialization
        $this->setSocketAddresses('tcp', $host, $port, $contextOptions);
        parent::__construct(null, $contextOptions, $connectTimeout); # this calls base Stream constructor
    }

    ########
    # Public Socket API
    # As Socket objects are not intended for extension for anything out of socket handling scope, public methods are not prepended by "socket" prefix

    # disconnects socket with sending RST to the connection
    # in case socket is in some error state, RST may still not be sent as socket already cannot send anything anywhere
    public function disconnectWithRST()
    {
        if ($this->skSocket && ($this->socketState >= $this::STATE_CONNECTED) && ($this->socketState < $this::STATE_DISCONNECTING)) {
            # close read and write, but not the real state
            $this->skSetWriteClosed();
            $this->skSetReadClosed();

            # really close read and write forcibly and send RST
            $rstLinger = ['l_linger' => 0, 'l_onoff' => 1];
            @socket_set_option($this->skSocket, SOL_SOCKET, SO_LINGER, $rstLinger);
            @stream_socket_shutdown($this->skStream, STREAM_SHUT_RDWR); # shutdown socket explicitly
            
            # now update real states
            $this->skSocketReadClosed = true;
            $this->skSocketWriteClosed = true;

            # reset write buffer so we do not write anything to closed socket
            $this->skWriteBuffer = new \SplDoublyLinkedList();
            $this->skWriteRemaining = 0;
            $this->skCurrentWrite = null;
        }

        # proceed to disconnect phase
        return $this->disconnect();
    }

    ########
    # TCP socket operation

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
        if (empty($wantWrite)) return null; # no 'write possible' select() flag, wait for it

        # having write flag raised means we *probably* connected, but we need to handle this *probably* thing
        # the problem is, we may not really be connected due to connection errors that are not raised by select()
        if (!is_string(@stream_socket_get_name($this->skStream, true))) {
            # some shit happened during connection, but we have totally no way to know what was that unless we use stream_socket_sendto() hack
            $this->skLastErrorNo = $this->skLastError = null;
            \set_error_handler($this->skErrorCallback, E_WARNING | E_NOTICE);
            stream_socket_sendto($this->skStream, ""); # this is totally a terrible hack that may cause real write in some weird case we connected but have no peer name, but I see no other way to retrieve connection error code
            \restore_error_handler();
            if ($this->skLastErrorNo === null) {
                # we still could not process what was wrong...
                $this->skLastErrorNo = $this::ERROR_CONNECTION_FAILURE;
                $this->skLastError = 'Connection failure';
            }
            return false;
        }

        # seems to be okay, open read and write
        $this->skSetReadOpen();
        $this->skSetWriteOpen();
        return true;
    }
}
