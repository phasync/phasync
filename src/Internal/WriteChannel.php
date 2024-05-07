<?php
namespace phasync\Internal;

use phasync;
use phasync\ChannelException;
use phasync\WriteChannelInterface;
use Serializable;

final class WriteChannel implements WriteChannelInterface {
    private ?ChannelState $state;
    public function __construct(ChannelState $state) {
        $this->state = $state;
    }

    public function __destruct() {
        $this->close();
        if (--$this->state->refCount === 0) {
            ObjectPool::push($this->state);
            $this->state = null;   
        }
    }

    public function close(): void {
        if ($this->state !== null && !$this->state->closed) {
            $this->state->closed = true;
            phasync::raiseFlag($this->state);    
        }
    }

    public function write(Serializable|array|string|float|int|bool $value): void {
        if ($this->state === null || $this->state->closed) {
            throw new ChannelException("Channel is closed");
        }
        $this->state->assertValidFiber();
        $writeOffset = $this->state->writeOffset++;
        $this->state->buffer[$writeOffset] = $value;
        phasync::raiseFlag($this->state);
        while ($this->state->refCount === 2 && $this->state->readOffset <= $writeOffset - $this->state->bufferSize) {
            phasync::awaitFlag($this->state);
        }
        if ($this->state->readOffset <= $writeOffset - $this->state->bufferSize) {
            throw new ChannelException("Channel was closed before the message could be delivered");
        }
    }

    public function isWritable(): bool {
        return $this->state !== null && !$this->state->closed;
    }

    public function willBlock(): bool {
        if ($this->state === null || $this->state->closed) {
            return false;
        }
        return $this->state->writeOffset - $this->state->readOffset >= $this->state->bufferSize;
    }

}