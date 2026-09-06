<?php

namespace ATL\Socket;

# Unix socket acceptor which waits for incoming connections and creates Unix socket objects on new connections, connecting them to your event handlers
# inbound connection oriented stream type sockets are created
# UnixAcceptor is a self-polled task which additionally acts as polling Factory for sockets created, but can optionally use external polling Factory
# UnixAcceptor always monitors created socket tasks for socket disconnections so it may remove sockets from its own list when they disconnect

class UnixAcceptor extends Factory
{
    
}
