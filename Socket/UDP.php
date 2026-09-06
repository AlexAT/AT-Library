<?php

namespace ATL\Socket;

# UDP socket which provides a single connection to single UDP endpoint and ignores any packets coming from any other endpoint
# This is 'outbound' created socket that can either send or accept connectionless datagram traffic (delivery is not guaranteed)

# Take care this socket will never disconnect by itself unless it catches some error or is disconnected manually
# Take care only basic and bulk read and write functions are available for datagram sockets that operate on entire datagram at once, no bytewise or delimited reads are supported
# Take care UDP type sockets do not provide datagram source addresses, they are expected to match connection address
# Can be self-polling task or Factory polled socket

# If you need UDP socket that can accept traffic from multiple sources or send traffic to multiple destinations, look at UDPAcceptor socket instead

# Supported event handlers:
# - connect ($socket, $remoteAddress, $localAddress) - called when socket is established (UDP sockets have no connection mechanism), before any traffic is sent or received
# - hasData ($socket, $readBufferSize) - called when there is some datagram(s) available on the socket, called one extra time right after eof handler if some data is left to be read
# - writeEmpty ($socket) - called when write buffer becomes empty
# - disconnect ($socket) - called when socket completely disconnects, so no more reads/writes are possible
# - error ($socket, $errorCode, $errorText) - called when socket transitions to error state (usually coincides with disconnect by obvious reason)
# - partialWrite ($socket, $datagramData, $bytesWritten) - called when partial datagram write occurs, supplies original datagram and number of bytes written

class UDP extends \ATL\Socket\SocketClientDatagramStream
{
    public function __construct($host, $port, $connectTimeout = null, $contextOptions = null)
    {
        # UDP socket initialization
        $this->setSocketAddresses('udp', $host, $port, $contextOptions);
        parent::__construct(null, $contextOptions, $connectTimeout); # this calls base Stream constructor
    }

    ########
    # UDP socket operation

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

        # having write flag raised means we connected, alas, for UDP socket this is all we can do
        # with UDP sockets we have no way to check if there are any errors and what errors are present after select(), TCP socket hack with writing empty line is unusable as it will cause empty datagram to be sent
        # the problem is, we may not really be connected due to connection errors that are not raised by select()
        # so we resort just to getting peer name that will always be filled by the OS
        if (!is_string(@stream_socket_get_name($this->skStream, true))) {
            $this->skLastErrorNo = $this::ERROR_CONNECTION_FAILURE;
            $this->skLastError = 'Connection failure';
            return false;
        }

        # seems to be okay, open read and write
        $this->skSetReadOpen();
        $this->skSetWriteOpen();
        return true;
    }
}
