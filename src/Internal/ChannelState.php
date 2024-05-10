<?php
namespace phasync\Internal;

use Fiber;
use phasync;
use phasync\ChannelException;
use phasync\Debug;
use Serializable;
use SplQueue;

final class ChannelState {
    use ObjectPoolTrait;

    public int $bufferSize = 0;
    public SplQueue $buffer;
    public bool $closed = false;
    public int $refCount = 2;
    public ?Fiber $creatingFiber;
    public ?ChannelMessage $first = null;
    public ?ChannelMessage $last = null;
    public int $messageCount = 0;

    public static function create(int $bufferSize, Fiber $creatingFiber): ChannelState {
        if ($instance = self::popInstance()) {
            $instance->bufferSize = $bufferSize;
            $instance->creatingFiber = $creatingFiber;
            return $instance;
        } else {
            return new ChannelState($bufferSize, $creatingFiber);
        }
    }

    public function write(Serializable|array|string|float|int|bool $value): void {
        if ($this->closed) {
            throw new ChannelException("Channel is closed");
        }
        $this->creatingFiber && $this->assertValidFiber();
        while ($this->buffer->count() > $this->bufferSize && !$this->closed) {
            phasync::awaitFlag($this);
        }
        if ($this->closed) {
            throw new ChannelException("Channel was closed before write could be delivered");
        }
        $this->buffer->enqueue($value);
        phasync::raiseFlag($this);
    }

    public function read(): Serializable|array|string|float|int|bool|null {
        !$this->closed && $this->creatingFiber && $this->assertValidFiber();
        while ($this->buffer->isEmpty()) {
            if ($this->closed) {
                return null;
            }
            phasync::awaitFlag($this);
        }
        phasync::raiseFlag($this);
        return $this->buffer->dequeue();
    }

    public function isWritable(): bool {
        return !$this->closed;
    }

    public function isReadable(): bool {
        if (!$this->buffer->isEmpty()) {
            return true;
        }
        return !$this->closed;
    }

    public function willWriteBlock(): bool {
        $this->creatingFiber && $this->assertValidFiber();
        return !$this->closed && $this->buffer->count() > $this->bufferSize;    
    }

    public function willReadBlock(): bool {
        $this->creatingFiber && $this->assertValidFiber();
        if ($this->closed && $this->buffer->isEmpty()) {
            return false;
        }
        return $this->buffer->isEmpty();
    }

    public function close(): void {
        if (!$this->closed) {
            $this->closed = true;
            phasync::raiseFlag($this);    
        }
    }


    public function returnToPool(): void {
        if (!$this->buffer->isEmpty()) {
            //var_dump($this->buffer->count());
            throw new ChannelException("Buffer is not empty");
        }
        $this->closed = false;
        $this->refCount = 2;
        $this->pushInstance();
    }

    public function __construct(int $bufferSize, Fiber $creatingFiber) {
        $this->buffer = new SplQueue();
        $this->bufferSize = $bufferSize;
        $this->creatingFiber = $creatingFiber;
    }

    public function assertValidFiber(): void {
        if ($this->creatingFiber !== null && $this->creatingFiber === phasync::getFiber()) {
            throw new ChannelException("Channels can't be activated from the coroutine that created it");
        }
        $this->creatingFiber = null;
    }
}

