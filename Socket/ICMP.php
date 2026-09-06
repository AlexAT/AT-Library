<?php

namespace ATL\Socket;

# ICMP socket which provides a single connection to single ICMP endpoint and ignores any packets coming from any other endpoint
# This is 'outbound' created socket that can either send or accept connectionless datagram traffic (delivery is not guaranteed)

# Take care this socket will never disconnect by itself unless it catches some error or is disconnected manually
# Take care only basic and bulk read and write functions are available for datagram sockets that operate on entire datagram at once, no bytewise or delimited reads are supported
# Take care ICMP type sockets do not provide datagram source addresses, they are expected to match connection address
# Can be self-polling task or Factory polled socket

# If you need ICMP socket that can accept traffic from multiple sources or send traffic to multiple destinations, look at ICMPAcceptor socket instead

# Supported event handlers:
# - connect ($socket, $remoteAddress, $localAddress) - called when socket is established (ICMP sockets have no connection mechanism), before any traffic is sent or received
# - hasData ($socket, $readBufferSize) - called when there is some datagram(s) available on the socket, called one extra time right after eof handler if some data is left to be read
# - writeEmpty ($socket) - called when write buffer becomes empty
# - disconnect ($socket) - called when socket completely disconnects, so no more reads/writes are possible
# - error ($socket, $errorCode, $errorText) - called when socket transitions to error state (usually coincides with disconnect by obvious reason)
# - partialWrite ($socket, $datagramData, $bytesWritten) - called when partial datagram write occurs, supplies original datagram and number of bytes written

# ICMP sockets are tricky, we first create ICMP type PHP socket from Sockets extension, then encapsulate it into stream via socket_export_stream() operation
# ICMP sockets do not provide any port to connect to, so socketPort is not available for ICMP sockets
# For IPv4/IPv6 address selection with hostnames, socketHostnameProtocol is still provided but lacks socketHostnameSystemDefault option, use either socketHostnameUseIPv4 or socketHostnameUseIPv6 directly
# ICMP sockets can set arbitrary socket options on creation, provide socketOptions argument as array of [level][option] => value compatible with socket_set_option to set options on socket creation
# Take care not all socket options and not all context options may be supported with ICMP type exported PHP socket
# Take care root or specific capability privileges are required on Linux to create raw sockets (ICMP is a subtype of raw socket)

# contextOptions [socket][bindto] option can be used to bind socket to specific address, this option is removed on stream options setup

class ICMP extends \ATL\Socket\DatagramStream
{
    # constants for socketHostnameProtocol
    const socketHostnameUseIPv4 = 0;
    const socketHostnameUseIPv6 = 1;

    const skLateStreamInitialization = true; # ICMP socket uses late skStream initialization in skSocketAfterConnect

    # public ICMP socket parameters
    public $socketHost;
    public $socketHostnameProtocol = self::socketHostnameUseIPv4; # change this to change what is to be used for hostnames, defaults to socketHostnameUseIPv4

    public $skSocket; # internal socket resource (or Socket class for PHP 8) for ICMP socket

    protected $skSocketOptions;
    protected $skSocketFamily; # set based on IPv4 or IPv6 socket address

    public function __construct($host, $connectTimeout = null, $contextOptions = null, $socketOptions = null)
    {
        # ICMP socket initialization
        $this->socketHost = $host;
        $this->skSocketOptions = $socketOptions;
        if (filter_var($this->socketHost, FILTER_VALIDATE_IP) === false) {
            # there is a little problem here, we have both IPv4 and IPv6 and so must set socket class accordingly
            switch ($this->socketHostnameProtocol) {
                case $this::socketHostnameUseIPv4:
                $this->skSocketFamily = AF_INET;
                break;

                case $this::socketHostnameUseIPv6:
                $this->skSocketFamily = AF_INET6;
                break;
            }
            $this->socketAddress =  "icmp://{$this->socketHost}"; # not an IP address
        } else {
            # in case of IP address, preferable protocol is determined automatically, do not bother, although we must not brace IPv4
            if (@strlen(inet_pton($this->socketHost)) == 4) {
                $this->socketAddress = "icmp://{$this->socketHost}"; # do not brace IPv4 addresses
                $this->skSocketFamily = AF_INET;
            } else {
                $this->socketAddress = "icmp://[{$this->socketHost}]"; # always brace IPv6 addresses
                $this->skSocketFamily = AF_INET6;
            }
        }

        parent::__construct(null, $contextOptions, $connectTimeout); # this calls base Stream constructor
    }

    protected function skSocketSetup()
    {
        parent::skSocketSetup();

        # take care: we inherit from DatagramStream directly and not SocketClientStream that handles this for us
        # reset skStream, skSocket and endpoint names on socket setup, making socket reusable
        $this->skStream = null;
        $this->skSocket = null;
        $this->socketLocalName = null;
        $this->socketRemoteName = null;
    }

    ########
    # Stream socket Task set

    # provides socket creation, take care skStream is not really filled in there, only skSocket is, skStream is initialized late in skSocketConnected
    public function skSocketCreate()
    {
        # get ICMP protocol ID
        $this->skLastErrorNo = $this->skLastError = null;
        \set_error_handler($this->skErrorCallback, E_WARNING | E_NOTICE);
        $icmp = getprotobyname('icmp');
        \restore_error_handler();
        if ($icmp === false) return false; # failed to retrieve ICMP protocol number

        # create PHP socket, set its options and attempt to connect
        $this->skLastErrorNo = $this->skLastError = null;
        \set_error_handler($this->skErrorCallback, E_WARNING | E_NOTICE);
        $this->skSocket = socket_create($this->skSocketFamily, SOCK_RAW, $icmp);
        \restore_error_handler();
        if (!$this->skSocket) {
            if ($this->skLastErrorNo === null) {
                $this->skLastErrorNo = $this::ERROR_CONNECTION_FAILURE;
                $this->skLastError = 'Failed to retrieve ICMP protocol number';
            }
            return false;
        }

        # set socket options
        if (is_array($this->skSocketOptions))
            foreach ($this->skSocketOptions as $level => $options)
                if (is_array($options))
                    foreach ($options as $option => $value)
                        socket_set_option($this->skSocket, $level, $option, $value);

        # set socket to non-blocking mode
        $this->skLastErrorNo = $this->skLastError = null;
        \set_error_handler($this->skErrorCallback, E_WARNING | E_NOTICE);
        $result = socket_set_nonblock($this->skSocket);
        \restore_error_handler();
        if ($result === false) return false; # on error, transition to finishing phase

        # bind the socket if necessary
        if (is_array($this->skContextOptions) && isset($this->skContextOptions['socket']['bindto'])) {
            $this->skLastErrorNo = $this->skLastError = null;
            \set_error_handler($this->skErrorCallback, E_WARNING | E_NOTICE);
            $result = socket_bind($this->skSocket, $this->skContextOptions['socket']['bindto']);
            \restore_error_handler();
            if (!$result) {
                if ($this->skLastErrorNo === null) {
                    $this->skLastErrorNo = $this::ERROR_CONNECTION_FAILURE;
                    $this->skLastError = 'Failed to bind the socket';
                }
                return false;
            }
        }

        # attempt to connect the socket
        $this->skLastErrorNo = $this->skLastError = null;
        \set_error_handler($this->skErrorCallback, E_WARNING | E_NOTICE);
        $result = socket_connect($this->skSocket, $this->socketHost, 0);
        \restore_error_handler();
        if (!$result) {
            # we may get "Operation now in progress" here indicating that connection operation is running in the background
            if (!preg_match('#now\\s+in\\s+progress#iS', $this->skLastError ?? '')) return false; # on real error, transition to finishing phase
            $this->skLastErrorNo = $this->skLastError = null; # reset error state
        }

        return true;
    }

    public function skSocketCheckForConnection()
    {
        $wantRead = $wantExcept = []; $wantWrite = [$this->skSocket];
        $this->skLastErrorNo = $this->skLastError = null;
        \set_error_handler($this->skErrorCallback, E_WARNING | E_NOTICE);
        $result = socket_select($wantRead, $wantWrite, $wantExcept, 0, 0);
        \restore_error_handler();
        if ($result === false) {
            # select() error handling (we may get EINTR here which we do not consider an error)
            if (!preg_match('#interrupted\\s+system\\s+call#iS', $this->skLastError ?? '')) return false; # on real error, transition to finishing phase
            $this->skLastErrorNo = $this->skLastError = null; # reset error state
            return null; # we have to wait
        }
        if (empty($wantWrite)) return null; # no 'write possible' select() flag, wait for it

        # our PHP socket has been connected, translate it into stream
        $this->skLastErrorNo = $this->skLastError = null;
        \set_error_handler($this->skErrorCallback, E_WARNING | E_NOTICE);
        $result = $this->skStream = socket_export_stream($this->skSocket);
        \restore_error_handler();
        if ($result === false) {
            if ($this->skLastErrorNo === null) {
                $this->skLastErrorNo = $this::ERROR_GENERAL_FAILURE;
                $this->skLastError = 'Failed to export PHP socket to stream';
            }
            return false;
        }

        # set stream to non-blocking mode of operation
        $this->skLastErrorNo = $this->skLastError = null;
        \set_error_handler($this->skErrorCallback, E_WARNING | E_NOTICE);
        $result = stream_set_blocking($this->skStream, false);
        \restore_error_handler();
        if ($result === false) return false; # on error, transition to finishing phase

        # set stream options (better late than never)
        if (is_array($this->skContextOptions)) {
            # we need to remove [socket][bindto]
            $options = $this->skContextOptions;
            unset($options['socket']['bindto']);
            if (!empty($options)) stream_context_set_option($this->skStream, $options);
        }

        # having write flag raised means we connected, alas, for ICMP socket this is all we can do
        # with ICMP sockets we have no way to check if there are any errors and what errors are present after select(), TCP socket hack with writing empty line is unusable as it will cause empty packet to be sent
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

    # exports skSocket to skStream and retrieves socket names after PHP socket is connected
    public function skSocketConnected()
    {
        # get socket names
        $this->socketLocalName = @stream_socket_get_name($this->skStream, false);
        $this->socketRemoteName = @stream_socket_get_name($this->skStream, true);
    }

    # we override skSocketEnd to also close and destroy our own skSocket
    public function skSocketEnd()
    {
        parent::skSocketEnd(); # close socket operations and destroy stream counterpart (if allowed)

        # now do the same with our own skSocket
        if ($this->skCloseStreamNormally && $this->skSocket) {
            @socket_close($this->skSocket);
            $this->skSocket = null;
        }
    }
}
