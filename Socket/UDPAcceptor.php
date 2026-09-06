<?php

namespace ATL\Socket;

# UDP socket acceptor which waits for incoming packets and creates UDP pseudo-socket objects (UDPEndpoint) on any new endpoints detected, connecting them to your event handlers
# UDPAcceptor optionally has a timeout after which endpoints are destroyed if no more traffic follows from them
# Inbound connectionless datagram type pseudo-sockets are created, these are not real socket objects and are not added to TaskLoop or polling Factory on creation, they are not Task objects as well
# UDPAcceptor is normally created as self-polled task which performs UDP socket polling itself, although it optionally can resort to external polling Factory for the inbound socket polling if needed
# Does not implement write() method, but implements createEndpoint() method instead which allows you to create and connect UDPEndpoint object destined towards some arbitrary address that may be used for sending
# In case UDPEndpoint for target address exists, createEndpoint() will return false, refusing to create duplicate endpoint, getEndpoint() may be used instead then to get existing endpoint object for an address
# In some cases like broadcast/multicast or passive port scanning it may be not desirable to create endpoints for the target address, to handle such cases sendTo() method is provided, possibly with a callback to handle result
# When multiple endpoints are sending, send fairness can be regulated using maxEndpointSendBulk property, the default value is 16000, meaning only up to 16000 bytes (or single larger datagram) will be allowed to be sent at once
# The recommended alternative values for maxEndpointSendBulk are 0 (send everything in one go, ensuring max performance) and 1 (send one datagram at most, ensuring fair distribution at the cost of higher overhead)

class UDPAcceptor extends \ATL\Socket\SocketClientAcceptor
{

}

class UDPEndpoint extends \ATL\Socket\DatagramEndpoint
{

}
