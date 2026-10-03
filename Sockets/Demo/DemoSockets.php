<?php

# demonstration sockets that have no function but are good to test the base socket code functions
# NullSocket - a very base stream socket that trashes each write to virtual /dev/null and has no reads
# LoopSocket - another very base stream socket that just inserts everything written into read buffer
# PairSocket - a stream socket that connects to another socket of its kin, grabbing all its read buffer data and injecting all writes to it
# BusSocket/SocketBus - a bus-connectible stream socket that writes everything to every other socket read buffer that is on the socket bus

# take care all these sockets belong to \ATL\Sockets\Demo namespace and this file must be included explicitly (not autoloaded) to use
namespace ATL\Sockets\Demo;

class NullSocket extends \ATL\Sockets\ByteStreamSocket
{
    protected function skOnAllBuffersOpen()
    {
        # when buffers are open, attach dummy data processing event handler to write buffer and enable event read mode, making it like /dev/null
        $this->skWriteBuffers[0]->onDataRead($this->splID, [$this, 'skDemoWriteHandler']);
        $this->skWriteBuffers[0]->setEventReadMode(true);
        parent::skOnAllBuffersOpen();
    }

    # data read handler for the write buffer, passes everything to the vast /dev/null-like space

    public function skDemoWriteHandler($buffer, $data)
    {
        return false; # allow no more events after our handler
    }

    # handle buffer events to provide immediate connection on request

    public function skOnReadBufferOpening(/** @var \ATL\Sockets\Buffer */ $buffer)
    {
        parent::skOnReadBufferOpening($buffer);
        $buffer->skbTransportOpen();
    }

    public function skOnReadBufferClosing(/** @var \ATL\Sockets\Buffer */ $buffer)
    {
        parent::skOnReadBufferClosing($buffer);
        $buffer->skbTransportClose();
    }

    public function skOnWriteBufferOpening(/** @var \ATL\Sockets\Buffer */ $buffer)
    {
        parent::skOnWriteBufferOpening($buffer);
        $buffer->skbTransportOpen();
    }

    public function skOnWriteBufferClosing(/** @var \ATL\Sockets\Buffer */ $buffer)
    {
        parent::skOnWriteBufferClosing($buffer);
        if (!$buffer->isEmpty()) return;
        $buffer->skbTransportClose();
    }
}

class LoopSocket extends NullSocket
{
    # data read handler for the write buffer, injects everything written into our own read buffer

    public function skDemoWriteHandler($buffer, $data)
    {
        if ($this->skReadBuffers[0]->isOpen()) $this->skReadBuffers[0]->skbAddRight($data);
        return false; # allow no more events after our handler
   }
}