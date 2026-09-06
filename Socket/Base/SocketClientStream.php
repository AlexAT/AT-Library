<?php

namespace ATL\Socket;

########
# Common class for stream_socket_client() based sockets that is reused in TCP/UDP socket types, provided to avoid code duplication between PHP stream-based sockets
# SocketClientStream class is byte-based stream class while SocketClientDatagramStream is corresponding datagram-based stream class

interface ISocketClientStream
{
    # constants for socketHostnameProtocol
    const socketHostnameUseIPv4 = 0;
    const socketHostnameUseIPv6 = 1;
    const socketHostnameSystemDefault = 2;
}

trait TSocketClientStream
{
    # public stream_socket_client() socket parameters
    public $socketHost;
    public $socketPort;
    public $socketHostnameProtocol = self::socketHostnameUseIPv4; # change this to change what is to be used for hostnames, defaults to socketHostnameUseIPv4

    public $skSocket; # internal socket resource (or Socket class for PHP 8) exported from stream, can be null if socket client is unable to export the socket

    # sets stream_socket_client() addresses, provides IPv4/IPv6 selection for hostnames, modifies contextOptions if necessary
    # protoPrefix is expected to be tcp, udp or any other protocol from <protocol>:// supported by stream_socket_client()
    protected function setSocketAddresses($protoPrefix, $host, $port, &$contextOptions)
    {
        $this->socketHost = $host;
        $this->socketPort = $port;
        if (filter_var($this->socketHost, FILTER_VALIDATE_IP) === false) {
            # there is a little problem here, if we have both IPv4 and IPv6, PHP prefers IPv6 and this often causes issues
            if (($contextOptions === null) || !isset($contextOptions['socket']['bindto'])) {
                switch ($this->socketHostnameProtocol) {
                    case $this::socketHostnameUseIPv4:
                    if ($contextOptions === null) $contextOptions = [];
                    $contextOptions['socket']['bindto'] = '0:0'; # set PHP to use IPv4
                    break;

                    case $this::socketHostnameUseIPv6:
                    if ($contextOptions === null) $contextOptions = [];
                    $contextOptions['socket']['bindto'] = '[0]:0'; # set PHP to use IPv6
                    break;
                }
            }
            $this->socketAddress =  "{$protoPrefix}://{$this->socketHost}:{$this->socketPort}"; # not an IP address
        } else {
            # in case of IP address, preferable protocol is determined automatically, do not bother, although we must not brace IPv4
            if (@strlen(inet_pton($this->socketHost)) == 4) {
                $this->socketAddress = "{$protoPrefix}://{$this->socketHost}:{$this->socketPort}"; # do not brace IPv4 addresses
            } else {
                $this->socketAddress = "{$protoPrefix}://[{$this->socketHost}]:{$this->socketPort}"; # always brace IPv6 addresses
            }
        }
    }

    # clears up skStream in socket setup
    protected function skSocketSetup()
    {
        parent::skSocketSetup();

        # reset skStream and endpoint names on socket setup, making socket reusable
        $this->skStream = null;
        $this->socketLocalName = null;
        $this->socketRemoteName = null;
    }

    # provides stream_socket_client() socket creation
    public function skSocketCreate()
    {
        # socket creation with context options
        $this->skLastErrorNo = $this->skLastError = null;
        \set_error_handler($this->skErrorCallback, E_WARNING | E_NOTICE);
        $lastErrorNo = $lastError = null;
        $this->skStream = @stream_socket_client(
            $this->socketAddress,
            $lastErrorNo, $lastError,
            $this->socketConnectTimeout,
            STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
            $this->skContextOptions
        );
        \restore_error_handler();
        if (!$this->skStream) {
            if ($lastErrorNo !== null) {
                # propagate retrieved error data instead of warning data
                $this->skLastErrorNo = $lastErrorNo;
                $this->skLastError = $lastError;
            }
            return false;
        }

        # attempt to export socket resource back to make setting socket options and other direct socket stuff possible
        if (function_exists('socket_import_stream')) {
            $this->skSocket = @socket_import_stream($this->skStream);
            if (!is_resource($this->skSocket) && !is_object($this->skSocket)) $this->skSocket = null;
        } else {
            $this->skSocket = null;
        }

        return true;
    }

    # provides retrieving socket names after stream_socket_client() socket is connected
    public function skSocketConnected()
    {
        # get socket names
        $this->socketLocalName = @stream_socket_get_name($this->skStream, false);
        $this->socketRemoteName = @stream_socket_get_name($this->skStream, true);
    }
}

class SocketClientStream extends \ATL\Socket\Stream implements \ATL\Socket\ISocketClientStream { use \ATL\Socket\TSocketClientStream; }
class SocketClientDatagramStream extends \ATL\Socket\DatagramStream implements \ATL\Socket\ISocketClientStream { use \ATL\Socket\TSocketClientStream; }
