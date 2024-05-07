<?php
namespace phasync\Internal;

use phasync;
use phasync\ChannelException;
use phasync\ReadChannelInterface;
use Serializable;

final class ReadChannel implements ReadChannelInterface {
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

    public function read(): Serializable|array|string|float|int|bool|null {
        if ($this->state === null || $this->state->closed) {
            return null;
        }
        $this->state->assertValidFiber();
        while ($this->willBlock()) {
            phasync::awaitFlag($this->state);
        }
        if ($this->state->readOffset !== $this->state->writeOffset) {
            $result = $this->state->buffer[$this->state->readOffset];
            unset($this->state->buffer[$this->state->readOffset++]);
            phasync::raiseFlag($this->state);
            return $result;
        }
        return null;
    }

    public function isReadable(): bool {
        return $this->state !== null && ($this->state->readOffset !== $this->state->writeOffset || !$this->state->closed);
    }

    public function willBlock(): bool {
        return $this->state !== null && $this->state->readOffset === $this->state->writeOffset && !$this->state->closed;
    }

}