<?php

namespace ATL\Socket;

# TCP socket acceptor which waits for incoming connections and creates TCP socket objects on new connections, connecting them to your event handlers

# TCPAcceptor is a self-polled task that accepts TCP connections and creates TCPIncoming sockets that are compatible with TCP socket type but do not have means of initiating connection
# TCPAcceptor implements PollingFactory API and by default assigns newly created sockets to internal polling factory, polling all created sockets in its own main loop
# socketPollingFactory property controls the behavior, if you want the default behavior, set it to null (this will not reassign already created sockets)
# If you do not want created sockets to be assigned to internal polling factory by default and remain self-polled, set socketPollingFactory to false (this will not reassign already created sockets)
# If you want created sockets to be assigned to custom polling factory, set socketPollingFactory property to polling factory object (this will not reassign already created sockets)
# To traverse active sockets created by TCPAcceptor, use socketList property that is an array of TCPIncoming (incoming)/TCP (outgoing) socket objects that still have their Task running
# TCPAcceptor always installs task termination event handlers and monitors created socket tasks so it may remove sockets from its own socket list when they disconnect
# Take care that terminating TCPAcceptor does not terminate running sockets, use disconnectAll() method after calling terminate() method if you need it
# Take care using terminate() method along with internal polling factory (the default option) keeps TCPAcceptor Task running until no sockets remain in its internal polling factory
# Take care that after calling terminate() task is bound to be terminated and listening cannot be restarted, to control listening or pause accepting connections, use start()/stop() and pause()/unpause() methods
# Terminating TCPAcceptor Task when it has some internally polled sockets will induce Socket polling change for all its sockets to become self-polling

# TCPAcceptor can also spawn outbound connections and handle them, like datagram acceptors, use connectTo() method and then handle socketCreated events to attune Socket parameters and install Socket event handlers
# This way you can create applications that do not differentiate between incoming and outgoing sockets if your protocol allows it or that use TCPAcceptor as connectivity centrale without creating sockets explicitly

# Supported event handlers
# listen ($acceptor, $socketServer) - called after listening socket starts accepting connections
# accept ($acceptor, $socket, $peerName) - called when new connection is accepted, before incoming Socket object is created, can be used to filter what is accepted or do some other preparatory work, this is the only place where connection may be rejected
#                                          return null or true to accept connection, return false to send RST (reject connection), return something else to accept connection and pass returned value to socketCreated() and accepted() events
#                                          peerName is passed directly from stream_socket_accept() call
# acceptError ($acceptor, $socket, $peerName) - called when there is some failure accepting connection happens, take care peerName may be null or some invalid value as it is passed directly from stream_socket_accept() call
# socketCreated ($acceptor, $socket) - this is the place where you may install your own Socket event handlers and tune socket parameters, called after Socket object is created but when its Task is not yet running
#                                      this method is called for both incoming and outgoing sockets, they can be differentiated by their class, incoming sockets would be of TCPIncoming class while outgoing will be just of normal TCP class
# socketTerminated ($acceptor, $socket) - called when TCPAcceptor detects some socket Task has been terminated, can be used for cleanup operations, called for both incoming and outgoing sockets, they can be differentiated by their class
# accepted ($acceptor, $socket, $peerName) - called after incoming Socket has been created and its Task has been started, take care Socket may already call its own connect event handler (and possibly other handlers if something happens) before this one
# unlisten ($acceptor, $socketServer) - called after listening socket stops accepting connections, socketServer is already closed and not valid anymore resource but can be used to match one previously appearing on listen()
# error ($acceptor, $socket, $errorCode, $errorText) - called when listening socket transitions to error state (usually coincides with disconnect by obvious reason)
#                                                      after error() is called, no more listening operations are possible but Task may remain until all internally polled sockets disconnect

interface ITCPAcceptor
{
    const acceptorDefaultBindIPv4 = 0;
    const acceptorDefaultBindIPv6 = 1;
}

trait TTCPAcceptor
{
    public $maxConnections = PHP_INT_MAX; # by default set to an ultra large value, you can set this to limit number of connections accepted, take care outgoing connections do count against this number
    public $acceptBackLogSize = 1000; # limit backlog of connections not yet accepted but pending to when paused or max connections reached, set this before starting acceptor, cannot be changed at runtime
    public $acceptErrorRetries = 10; # retry this much before giving up and throwing error exception
    public $socketPollingInterval = 0; # by default, the TaskLoop polling interval is used for both listening socket and assigned sockets polling, although you can set this to something else to reduce polling overhead or decrease latency

    public $defaultBindType = self::acceptorDefaultBindIPv4; # what type of binding to use if bindAddress is not explicitly specified, acceptorDefaultBindIPv4 dictates 0.0.0.0, acceptorDefaultBindIPv6 dictates [::]

    public $socketPollingFactory = true; # false to not assign sockets to any factory, null/true to use internal polling factory, object to assign sockets to custom polling factory
    /** @var \ATL\Socket\TCP[] */ public $socketList = []; # list of created and not yet disconnected socket objects

    public $listenPort;
    public $bindAddress;
    public $socketLocalName;

    public $saStream;
    public $saSocket;
    public $saContextOptions;

    protected $saScheduled;
    protected $saErrorCallback;
    protected $saLastErrorNo;
    protected $saLastError;
    protected $saListening;
    protected $saPaused;
    protected $saTerminateRequested;
    protected $saErrorRetriesLeft;

    protected $saSocketTerminateCallback;

    public function __construct($port, $bindAddress = null, $contextOptions = null)
    {
        $this->listenPort = $port; # port can be zero to select a random port
        $this->bindAddress = $bindAddress;

        $this->saListening = false;
        $this->saPaused = false;
        $this->pfTerminated = false;
        $this->saTerminateRequested = false;

        $this->saScheduled = false;
        $this->pfScheduled = false;
        $this->saErrorCallback = \ATL\Routines::callableToClosure([$this, 'saErrorHandler'], true);
        $this->pfErrorCallback = \ATL\Routines::callableToClosure([$this, 'pfErrorHandler'], true);

        if ($contextOptions === null) $contextOptions = [];
        if (!isset($contextOptions['socket']['backlog'])) $contextOptions['socket']['backlog'] = $this->acceptBackLogSize;
        $this->saContextOptions = stream_context_create($contextOptions ?? []);

        $this->saSocketTerminateCallback = \ATL\Routines::callableToClosure([$this, 'saOnSocketTerminate']);
    }

    public function start()
    {
        if ($this->saTerminateRequested) throw new \ErrorException("Cannot start accepting connections for terminating acceptor");
        if ($this->pfTerminated) throw new \ErrorException("Cannot start accepting connections for terminated acceptor");
        if (!$this->taskRunning()) throw new \ErrorException("Cannot start accepting connections for not running acceptor");
        if ($this->saListening) return; # already listening
        $this->saCreateListenSocket();
    }

    public function stop()
    {
        if ($this->saTerminateRequested || $this->pfTerminated) return; # already asked to terminate or terminated
        if (!$this->taskRunning()) throw new \ErrorException("Cannot stop accepting connections for not running acceptor");
        if (!$this->saListening) return; # already stopped listening
        $this->saDestroyListenSocket();
    }

    public function isListening()
    {
        return $this->saListening;
    }

    public function pause()
    {
        if ($this->saTerminateRequested) throw new \ErrorException("Cannot pause accepting connections for terminating acceptor");
        if ($this->pfTerminated) throw new \ErrorException("Cannot pause accepting connections for terminated acceptor");
        if (!$this->taskRunning()) throw new \ErrorException("Cannot pause accepting connections for not running acceptor");
        if ($this->saPaused || !$this->saListening) return; # already paused or not listening
        $this->saPaused = true;
    }

    public function unpause()
    {
        if ($this->saTerminateRequested) throw new \ErrorException("Cannot unpause accepting connections for terminating acceptor");
        if ($this->pfTerminated) throw new \ErrorException("Cannot unpause accepting connections for terminated acceptor");
        if (!$this->taskRunning()) throw new \ErrorException("Cannot unpause accepting connections for not running acceptor");
        if (!$this->saPaused || !$this->saListening) return; # already not paused or not listening
        $this->saPaused = false;
    }

    public function isPaused()
    {
        return $this->saPaused;
    }

    public function isAccepting()
    {
        return $this->saListening && !$this->saPaused && (count($this->socketList) < $this->maxConnections);
    }

    public function terminate()
    {
        if ($this->saTerminateRequested || $this->pfTerminated) return; # already asked to terminate or terminated
        if (!$this->taskRunning()) throw new \ErrorException("Cannot terminate not running acceptor");
        $this->saTerminateRequested = true;
        $this->saDestroyListenSocket();
    }

    public function disconnectAll()
    {
        foreach ($this->socketList as $socket)
            $socket->disconnect();
    }

    public function disconnectAllWithRST()
    {
        foreach ($this->socketList as $socket)
            $socket->disconnectWithRST();
    }

    public function connectTo()
    {
        if ($this->saTerminateRequested || $this->pfTerminated) throw new \ErrorException("Cannot establish connections when acceptor is terminated");
        if (!$this->taskRunning()) throw new \ErrorException("Cannot establish connections when acceptor is not running");

    }

    public function main()
    {
        # this is a general accept and poll loop, polls accept socket, polls all the sockets we need to poll and sleeps
        yield true;

        # create listening socket
        $this->saCreateListenSocket();

        $this->saScheduled = false;
        $this->pfScheduled = false;
        $this->saErrorRetriesLeft = $this->acceptErrorRetries;
        while (true) {
            $acceptResult = ($this->saListening && !$this->saPaused && (count($this->socketList) < $this->maxConnections)) ? $this->saDoAccept() : false;
            $pollResult = (!empty($this->pfCallbacks)) ? $this->doPolling() : false;
            if ($acceptResult !== false) {
                yield $acceptResult ?? $this->socketPollingInterval; # accept is the driver
            } elseif ($pollResult !== false) {
                yield $pollResult ?? $this->socketPollingInterval; # polling is the driver
            } else {
                $this->saScheduled = false;
                $this->pfScheduled = false;
                if ($this->saTerminateRequested && empty($this->pfCallbacks)) return; # we were asked to terminate and nobody is left to poll, do it
                yield false; # we are going to be explicitly rescheduled on accept state change or polling event
            }
        }
    }

    protected function saDoAccept()
    {
        $peerName = null;
        $this->saLastErrorNo = $this->saLastError = null;
        \set_error_handler($this->saErrorCallback, E_WARNING | E_NOTICE);
        $result = stream_socket_accept($this->saStream, 0, $peerName);
        \restore_error_handler();
        if ($result === false) {
            # accept() error handling (we may get EINTR or EAGAIN here which we do not consider to be an error)
            if (!preg_match('#interrupted\\s+system\\s+call|resource\\s+temporarily\\s+unavailable#iS', $this->pfLastError ?? '')) {
                # on real error, attempt to retry but terminate with ErrorException if it fails for too much
                $this->saErrorRetriesLeft--;
                if ($this->saErrorRetriesLeft == 0) throw new \ErrorException("Acceptor failed to perform polling on accept socket");
            } else {
                # EINTR/EGAIN should be re-attempted as soon as possible
                return true;
            }

            # we got nothing to accept, sleep for the next polling interval
            $this->saErrorRetriesLeft = $this->acceptErrorRetries;
            return null;
        }
        $this->saErrorRetriesLeft = $this->acceptErrorRetries;

        # we got us some accepted socket stream, create TCPIncoming socket from it
        $socket = new TCPIncoming($result);
        $socket->taskAddOnTerminateHandler($this->saSocketTerminateCallback);
        $this->socketList[$socket->socketId] = $socket;
        if ($this->socketPollingFactory !== false)
            $socket->assignToPollingFactory(is_object($this->socketPollingFactory) ? $this->socketPollingFactory : $this); # assign socket to custom polling factory or us
        $this->taskLoop->addTask($socket); # we do not create child tasks because we do not want sockets to terminate if we are stopped forcibly or fail somewhere
        return true; # we may have another connection waiting, so reexecute as fast as possible
    }

    protected function saCreateListenSocket()
    {
        if ($this->saListening) return; # nothing to do

        # socket creation with context options
        $this->saLastErrorNo = $this->saLastError = null;
        \set_error_handler($this->saErrorCallback, E_WARNING | E_NOTICE);
        $lastErrorNo = $lastError = null;
        if ($this->bindAddress === null) {
            $socketAddress = ($this->defaultBindType == self::acceptorDefaultBindIPv6) ? '[::]' : '0.0.0.0'; # select IPv4 or IPv6 'bind all' address according to default setting
        } else {
            if (filter_var($this->socketHost, FILTER_VALIDATE_IP) === false) {
                if (@strlen(inet_pton($this->socketHost)) == 4) {
                    $socketAddress = $this->bindAddress; # IPv4 address, use as is
                } else {
                    $socketAddress = '['.$this->bindAddress.']'; # IPv6 address, enclose in brackets
                }
            } else {
                $socketAddress = $this->bindAddress; # hostname, probably, or bracketed IPv6
            }
        }
        $this->saStream = @stream_socket_server(
            "tcp://{$socketAddress}:{$this->listenPort}",
            $lastErrorNo, $lastError,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $this->saContextOptions
        );
        \restore_error_handler();
        if (!$this->saStream) {
            if ($lastErrorNo !== null) {
                # propagate retrieved error data instead of warning data
                $this->saLastErrorNo = $lastErrorNo;
                $this->saLastError = $lastError;
            }

            # failed creating listening socket, transition to terminating phase
            $this->terminate();
            return;
        }

        # get socket name back
        $this->socketLocalName = @stream_socket_get_name($this->saStream, false);

        # attempt to export socket resource back to make setting socket options and other direct socket stuff possible
        if (function_exists('socket_import_stream')) {
            $this->saSocket = @socket_import_stream($this->saStream);
            if (!is_resource($this->saSocket) && !is_object($this->saSocket)) $this->saSocket = null;
        } else {
            $this->skSocket = null;
        }

        $this->saListening = true;
        $this->saPaused = false;
    }

    protected function saDestroyListenSocket()
    {
        if (!$this->saListening) return; # nothing to do

        if ($this->saStream) fclose($this->saStream);
        $this->saStream = null;

        $this->saListening = false;
        $this->saPaused = false;
    }

    public function saErrorHandler($errno, $errstr)
    {
        $this->saLastErrorNo = $errno;
        $this->saLastError = trim($errstr);
        #return true;
        return false; # temporary for debugging
    }

    public function saSocketTerminateCallback($socket)
    {
        unset($this->socketList[$socket->socketId]);
        $this->removeFromPolling($socket);
        $this->saScheduled = true;
        $this->taskSchedule();
    }

    # simplified event handler addition support
    public function onListen($owner, $callback, $runHandler = true, $addLast = false) { $this->addEventHandler($owner, 'listen', $callback, $runHandler, $addLast); }
    public function onAccept($owner, $callback, $runHandler = true, $addLast = false) { $this->addEventHandler($owner, 'accept', $callback, $runHandler, $addLast); }
    public function onAcceptError($owner, $callback, $runHandler = true, $addLast = false) { $this->addEventHandler($owner, 'acceptError', $callback, $runHandler, $addLast); }
    public function onSocketCreated($owner, $callback, $runHandler = true, $addLast = false) { $this->addEventHandler($owner, 'socketCreated', $callback, $runHandler, $addLast); }
    public function onSocketTerminated($owner, $callback, $runHandler = true, $addLast = false) { $this->addEventHandler($owner, 'socketTerminated', $callback, $runHandler, $addLast); }
    public function onAccepted($owner, $callback, $runHandler = true, $addLast = false) { $this->addEventHandler($owner, 'accepted', $callback, $runHandler, $addLast); }
    public function onUnlisten($owner, $callback, $runHandler = true, $addLast = false) { $this->addEventHandler($owner, 'unlisten', $callback, $runHandler, $addLast); }
    public function onError($owner, $callback, $runHandler = true, $addLast = false) { $this->addEventHandler($owner, 'error', $callback, $runHandler, $addLast); }
}

class TCPAcceptor extends \ATL\Socket\PollingFactory implements ITCPAcceptor { use TTCPAcceptor; }

# Incoming TCP socket
# Not much different from normal TCP socket except it skips socket creation and uses passed stream to initialize
class TCPIncoming extends \ATL\Socket\TCP
{
    public function __construct($stream)
    {
        # use Stream constructor to initialize on existing stream
        Stream::__construct($stream);
    }

    protected function skSocketSetup()
    {
        # use Stream socket setup to initialize on existing stream
        return Stream::skSocketSetup();
    }

    public function skSocketCreate()
    {
        # we are using already created stream so not much left to do there

        # attempt to export socket resource back to make setting socket options and other direct socket stuff possible
        if (function_exists('socket_import_stream')) {
            $this->skSocket = @socket_import_stream($this->skStream);
            if (!is_resource($this->skSocket) && !is_object($this->skSocket)) $this->skSocket = null;
        } else {
            $this->skSocket = null;
        }

        return true;
    }
}
