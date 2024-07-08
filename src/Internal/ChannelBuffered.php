<?php

namespace phasync\Internal;

use Fiber;
use phasync;
use phasync\ChannelException;
use stdClass;

/**
 * This is a highly optimized implementation of a bi-directional channel
 * for communication between coroutines running in the same memory space
 * without threading. It is not meant to be used directly since it has
 * no protection against deadlocks. Instead channels should be created
 * via {@see phasync::channel()}.
 *
 * The implementation reaches around 500 000 reads and 500 000 writes
 * between a pair of coroutines on an AMD Ryzen 7 2700X.
 *
 * @internal
 */
final class ChannelBuffered implements ChannelBackendInterface, \IteratorAggregate
{

    public const READY           = 0;
    public const BLOCKING_READS  = 1;
    public const BLOCKING_WRITES = 2;

    /**
     * Waiting readers must be stored here, because if the Channel becomes
     * garbage collected then the Fiber it is referencing will be destroyed.
     * Instead we will enqueue the suspended reader in the destructor.
     *
     * @var array<int, \Fiber[]>
     */
    private static array $waiting = [];

    private int $id;
    private bool $closed  = false;
    private array $buffer = [];
    private ?\Fiber $creatingFiber;
    private int $state      = self::READY;
    private int $firstQueue = 0;
    private int $lastQueue  = 0;
    private int $bufferSize;
    private int $firstBuffer = 0;
    private int $lastBuffer  = 0;
    private ?\Fiber $receiver;
    private object $flag;

    public function __construct(int $bufferSize)
    {
        $this->id                 = \spl_object_id($this);
        $this->bufferSize         = $bufferSize;
        self::$waiting[$this->id] = [];
        $this->creatingFiber      = \phasync::getFiber();
        $this->flag = new stdClass;
    }

    public function __destruct()
    {
        $this->closed = true;
    }

    public function activate(): void
    {
        $this->creatingFiber = null;
    }

    public function isReady(): bool
    {
        return $this->isReadyForRead() && $this->isReadyForWrite();
    }

    public function await(): void {
        while (!$this->isReady()) {
            phasync::awaitFlag($this->flag);
        }
    }

    public function isReadyForRead(): bool
    {
        if ($this->closed) {
            return true;
        }
        if ($this->firstBuffer < $this->lastBuffer) {
            return true;
        }

        return self::BLOCKING_READS !== $this->state;
    }

    public function awaitReadable(): void {
        while (!$this->isReadyForRead()) {
            phasync::awaitFlag($this->flag);
        }
    }

    public function isReadyForWrite(): bool
    {
        if ($this->closed) {
            return true;
        }
        if ($this->lastBuffer - $this->firstBuffer < $this->bufferSize) {
            return true;
        }

        return self::BLOCKING_WRITES !== $this->state;
    }

    public function awaitWritable(): void {
        while (!$this->isReadyForWrite()) {
            phasync::awaitFlag($this->flag);
        }
    }

    private function enqueue(\Fiber $fiber): void
    {
        self::$waiting[$this->id][$this->lastQueue++] = $fiber;
    }

    private function dequeue(): \Fiber
    {
        $result = self::$waiting[$this->id][$this->firstQueue];
        unset(self::$waiting[$this->id][$this->firstQueue++]);
        if ($this->firstQueue === $this->lastQueue) {
            $this->state = self::READY;
        }

        return $result;
    }

    public function getIterator(): \Traversable
    {
        while (!$this->isClosed()) {
            yield $this->read();
        }
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        while (self::BLOCKING_READS === $this->state) {
            $fiber = $this->dequeue();
            \phasync::enqueue($fiber);
        }
        while (self::BLOCKING_WRITES === $this->state) {
            \phasync::enqueueWithException($this->dequeue(), new ChannelException('Channel was closed'));
        }
        unset(self::$waiting[$this->id]);
        phasync::raiseFlag($this->flag);
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function write(\Serializable|array|string|float|int|bool|null $value): void
    {
        if ($this->closed) {
            throw new ChannelException('Channel is closed');
        }

        $bufferSize = $this->lastBuffer - $this->firstBuffer;

        phasync::raiseFlag($this->flag);

        if ($bufferSize < $this->bufferSize && self::BLOCKING_READS !== $this->state) {
            $this->buffer[$this->lastBuffer++] = $value;

            return;
        }

        $fiber = \phasync::getFiber();

        if ($this->creatingFiber) {
            if ($this->creatingFiber === $fiber) {
                throw new ChannelException("Can't open a channel from the same coroutine that created it");
            }
            $this->creatingFiber = null;
        }

        if (self::BLOCKING_WRITES === $this->state || self::READY === $this->state) {
            $this->state = self::BLOCKING_WRITES;
            /*
             * I am a waiting writer.
             */
            $this->enqueue($fiber);
            \Fiber::suspend();
            $reader         = $this->receiver;
            $this->receiver = null;
        } else {
            /**
             * Using a waiting reader.
             */
            $reader = $this->dequeue();
        }
        $this->buffer[$this->lastBuffer++] = $value;
        $value                             = null;
        \phasync::enqueue($fiber);
        \Fiber::suspend($reader);
        if ($this->bufferSize > 1) {
            \phasync::sleep();
        }
    }

    public function read(): \Serializable|array|string|float|int|bool|null
    {
        if ($this->closed) {
            return null;
        }

        phasync::raiseFlag($this->flag);

        if ($this->firstBuffer < $this->lastBuffer && self::BLOCKING_WRITES !== $this->state) {
            $value = $this->buffer[$this->firstBuffer];
            unset($this->buffer[$this->firstBuffer++]);

            return $value;
        }

        $fiber = \phasync::getFiber();

        if ($this->bufferSize > 1) {
            \phasync::sleep();
        }

        if ($this->creatingFiber) {
            if ($this->creatingFiber === $fiber) {
                throw new ChannelException("Can't open a channel from the same coroutine that created it");
            }
            $this->creatingFiber = null;
        }

        if (self::BLOCKING_READS === $this->state || self::READY === $this->state) {
            $this->state = self::BLOCKING_READS;
            /*
             * I am a waiting reader. I will be resumed with the result, and the
             * writer is responsible for allowing us to continue.
             */
            $this->enqueue($fiber);
            \Fiber::suspend();
        } else {
            /**
             * I'm using a waiting writer.
             */
            $writer         = $this->dequeue();
            $this->receiver = $fiber;
            \Fiber::suspend($writer);
        }
        if ($this->firstBuffer === $this->lastBuffer) {
            // channel closed
            return null;
        }
        $result = $this->buffer[$this->firstBuffer];
        unset($this->buffer[$this->firstBuffer++]);

        return $result;
    }

    public function isReadable(): bool
    {
        return !$this->closed;
    }

    public function isWritable(): bool
    {
        return !$this->closed;
    }

}
