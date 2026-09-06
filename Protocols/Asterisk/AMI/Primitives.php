<?php

namespace ATL\Protocols\Asterisk\AMI;

# supplementary message class to store message data in

class Message
{
    public $id;
    public $parameters;
    public $callback;
    public $timeout;

    public function __construct($id, $parameters, $callback, $timeout)
    {
        $this->id = $id;
        $this->parameters = $parameters;
        $this->callback = $callback;
        $this->timeout = $timeout;
    }
}

# supplementary semi-synchronous task primitives to easily perform typical tasks without relying on callbacks

class Primitive extends \ATL\Task
{
    /** @var \ATL\Protocols\Asterisk\AMI */ protected $ami;
    protected $timeout;
    protected $pendingEvent = false;

    public function taskOnTerminate($taskObject)
    {
        $this->ami->removeEventHandlers($this); # remove our event handlers
    }

    public function amiEvent()
    {
        if (!$this->pendingEvent) $this->taskSchedule();
        $this->pendingEvent = true;
    }
}

# taskResult would be true (success), false (timeout), null (AMI disconnect or task termination), or a string containing AMI error message
class WaitForConnect extends \ATL\Protocols\Asterisk\AMI\Primitive
{
    public function __construct($ami, $timeout = null)
    {
        $this->ami = $ami;
        $this->timeout = $timeout ?? ($this->ami->connectTimeout + 1); # by default, allow AMI to time out itself
    }

    public function main()
    {
        # assign AMI event handlers and start waiting if not yet completed
        $this->pendingEvent = false;
        $this->ami->onConnect($this, [$this, 'amiEvent']);
        $this->ami->onDisconnect($this, [$this, 'amiEvent']);
        if (!$this->pendingEvent) yield $this->timeout; # wait till scheduled by any of the handlers
        if ($this->ami->amiState >= $this->ami::STATE_DISCONNECTED) return $this->ami->amiError;
        return $this->pendingEvent ? true : false;
    }
}

# taskResult would be true (success), false (timeout), null (task termination), or a string containing AMI error message
class Disconnect extends \ATL\Protocols\Asterisk\AMI\Primitive
{
    protected $callDisconnect;

    public function __construct($ami, $timeout, $callDisconnect = true)
    {
        $this->ami = $ami;
        $this->timeout = $timeout;
        $this->callDisconnect = $callDisconnect;
    }

    public function main()
    {
        # assign AMI event handler and start waiting if not yet completed
        $this->pendingEvent = false;
        $this->ami->onDisconnect($this, [$this, 'amiEvent']);
        if ($this->callDisconnect && ($this->ami->amiState < $this->ami::STATE_DISCONNECTED)) $this->ami->disconnect();
        if (!$this->pendingEvent) yield $this->timeout; # wait till scheduled by any of the handlers
        return $this->pendingEvent ? ($this->ami->amiError ?? true) : false;
    }
}

# taskResult would be array containing response (success), false (timeout), null (AMI disconnect or task termination) or string containing AMI error message
class Action extends \ATL\Protocols\Asterisk\AMI\Primitive
{
    protected $parameters;
    protected $response;

    public function __construct($ami, $action, $parameters = [], $timeout = null)
    {
        # convert parameters so Action goes first
        $params['Action'] = $action;
        foreach ($parameters as $k => $v) $params[$k] = $v;

        $this->ami = $ami;
        $this->parameters = $params;
        $this->timeout = $timeout ?? $this->ami->messageTimeout;
    }

    public function main()
    {
        # quickly abort if AMI is disconnected
        if ($this->ami->amiState >= $this->ami::STATE_DISCONNECTED) return $this->ami->amiError;

        # assign AMI disconnect event handler
        $this->pendingEvent = false;
        $this->ami->onDisconnect($this, [$this, 'amiEvent']);

        # send authentication message and wait for response
        $this->response = null;
        $msgId = $this->ami->sendMessage($this->parameters, [$this, 'amiMessageResponse'], $this->timeout);
        if (!$this->pendingEvent) yield false; # we have no internal timeout, allowing AMI to timeout message by itself
        if ($this->response !== null) return $this->response; # success
        return ($this->ami->amiState >= $this->ami::STATE_DISCONNECTED) ? $this->ami->amiError : false;
    }

    public function amiMessageResponse($response)
    {
        $this->pendingEvent = true;
        $this->response = $response;
        $this->taskSchedule();
    }
}

# taskResult would be array containing response (success), false (timeout), null (AMI disconnect or task termination) or string containing AMI error message
class Command extends Action
{
    public function __construct($ami, $command, $timeout = null)
    {
        $this->ami = $ami;
        $this->parameters = ['Action' => 'Command', 'Command' => $command];
        $this->timeout = $timeout ?? $this->ami->messageTimeout;
    }
}

# taskResult would be true (success), false (timeout), null (AMI disconnect or task termination), string containing AMI error message or array containing authentication failure response
class Authenticate extends \ATL\Task
{
    protected $ami;
    protected $username;
    protected $secret;
    protected $timeout;

    public function __construct($ami, $username, $secret, $timeout = null)
    {
        $this->ami = $ami;
        $this->username = $username;
        $this->secret = $secret;
        $this->timeout = $timeout ?? $this->ami->messageTimeout;
    }

    public function main()
    {
        # why repeat the code if we can just spawn Action task?
        if (!is_array($result = yield new \ATL\Protocols\Asterisk\AMI\Action($this->ami, 'Login', ['Username' => $this->username, 'Secret' => $this->secret], $this->timeout)))
            return $wait->taskResult;
        if (!strcasecmp($result['response'] ?? '', 'success')) return true; # success!
        return $result;
    }
}
