<?php

namespace ATL;

interface IMessaging
{
    public function subscribe($room, $subscriberId, $callback, $throwOnDuplicate = true);
    public function unsubscribe($room, $subscriberId, $throwIfNotExists = true);
    public function getRooms();
    public function getSubscribers($room);
    public function getAllRoomsAndSubscribers();
    public function sendMessage($room, $message, $collectExceptions = false, $selectiveSubscribers = null);
}

trait TMessaging
{
    protected $rooms = [];
    protected $roomsCallbacksConverted = [];

    public function subscribe($room, $callback, $subscriberId = null, $throwOnDuplicate = true)
    {
        if ($subscriberId === null)
            $subscriberId = is_object($callback) ? \ATL\Routines::getCacheableObjectID($callback) : serialize($callback); # create subscriber ID if not supplied
        if ($throwOnDuplicate && isset($this->rooms[$room][$subscriberId]))
            throw new \ATL\MessagingException("Attempted to subscribe already existing subscriber with ID `{$subscriberId}` to room `{$room}`");
        $this->rooms[$room][$subscriberId] = $callback;
        if (!($callback instanceof \Closure)) $this->roomsCallbacksToConvert[$room][$subscriberId] = $subscriberId; # mark this callback to be converted on first message sent to room
        return $subscriberId;
    }

    public function unsubscribe($room, $subscriberId, $throwIfNotExists = true)
    {
        if ($throwIfNotExists && !isset($this->rooms[$room][$subscriberId]))
            throw new \ATL\MessagingException("Attempted to unsubscribe non-existent subscriber with ID `{$subscriberId}` from room `{$room}`");
        unset($this->rooms[$room][$subscriberId]);
        if (count($this->rooms[$room]) == 0) unset($this->rooms[$room]);
    }

    public function getRooms()
    {
        return array_keys($this->rooms);
    }

    public function getSubscribers($room)
    {
        return $this->rooms[$room] ?? [];
    }

    public function getAllRoomsAndSubscribers()
    {
        return $this->rooms;
    }

    public function sendMessage($room, $message, $collectExceptions = false, $selectiveSubscribers = null, $throwOnNoSubscribers = false)
    {
        if (!isset($this->rooms[$room])) return []; # fast path for empty rooms sending
        if ($message === null) throw new \ATL\MessagingException("Cannot send null message to room `{$room}`"); # null messages sending is disallowed explicitly, preventing certain range of programming errors
        if ($throwOnNoSubscribers && !isset($this->rooms[$room])) throw new \ATL\MessagingException("Attempted to send message to room `{$room}` with no subscribers");

        if ($collectExceptions) {
            $exception = new \ATL\MessagingException("Message sending attempt to room `{$room}` caused exceptions on receiver side");
            $exception->messageReplies = [];
            $exception->messageExceptions = [];
        }

        # check if this room needs some callbacks to be converted to closures
        # why late conversion here and not in subscribe()? because callback conversion may cause class autoloading and instantiation
        if (isset($this->roomsCallbacksToConvert[$room])) {
            # yes, convert all pending callbacks
            foreach ($this->roomsCallbacksToConvert[$room] as $subscriberId)
                $this->rooms[$room][$subscriberId] = Routines::callableToClosure($this->rooms[$room][$subscriberId], true); # convert callback to closure (may be left as is if closure is inoptimal)
            unset($this->roomsCallbacksToConvert[$room]);
        }

        $recipients = $this->rooms[$room];
        if ($selectiveSubscribers !== null) {
            if (!is_array($selectiveSubscribers)) $selectiveSubscribers = [$selectiveSubscribers];
            if (count($selectiveSubscribers) == 0) return []; # fast path for no subscribers selected
            $recipients = array_intersect_key($recipients, array_fill_keys($selectiveSubscribers, null));
            if (count($recipients) == 0) return []; # fast path for no recipients
        }

        $replies = [];
        foreach ($recipients as $subscriberId => $callback) {
            try {
                # allow replacing callbacks on the fly
                while (($replies[$subscriberId] = $callback($message, $room, $this)) instanceof MessagingCallback) {
                    # we got new messaging callback replacement, replace and retry
                    $callback = $this->rooms[$room][$subscriberId] = Routines::callableToClosure($replies[$subscriberId]->callback, true);
                    unset($replies[$subscriberId]);
                }
            } catch (\Exception $e) {
                if (!$collectExceptions) throw $e;
                $exception->messageExceptions[$subscriberId] = $e;
            }
        }

        if ($collectExceptions && (count($exception->messageExceptions) != 0)) {
            $exception->messageReplies = $replies;
            throw $exception;
        }

        return $replies;
    }
}

class Messaging implements \ATL\IMessaging { use \ATL\TMessaging; }

# this small supplementary class can be used to change callbacks dynamically or dynamically instantiate classes and move actual callbacks to them
# return an instance of this class from Messaging callback to replace existing callback and retry the message send operation

class MessagingCallback
{
    public $callback;
    public function __construct($callback) { $this->callback = $callback; }
}

# Exception classes

class MessagingException extends \Exception { }
