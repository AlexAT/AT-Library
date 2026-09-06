<?php

namespace ATL\Socket;

# Unix socket which provides a single connection to single Unix socket endpoint
# normally created as outbound connection oriented stream type socket, but is reused from inside UnixAcceptor socket to create individual sockets for incoming connections
# can be self-polling task or Factory polled General socket

class Unix extends General
{
    
}
