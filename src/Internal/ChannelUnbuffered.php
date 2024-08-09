<?php

namespace phasync\Internal;

use Fiber;
use phasync;
use phasync\ChannelException;

/**
 * This is a highly optimized implementation of a bi-directional channel
 * for communication between coroutines running in the same memory space
 * without threading. It is not meant to be used directly since it has
 * no protection against deadlocks. Instead channels should be created
 * via {@see phasync::channel()}.
 *
 * The implementation reaches around 500 000 reads and 500 000 writes
 * between a pair of coroutines on an AMD Ryzen 7 2700X.
 */
final class ChannelUnbuffered implements ChannelBackendInterface, \IteratorAggregate
{
    public const READY           = 0;
    public const BLOCKING_READS  = 1;
    public const BLOCKING_WRITES = 2;

    /**
     * Waiting fibers in all channels are stored in a static array, because
     * if the instances themselves reference the fiber - the waiting fiber will
     * also be garbage collected. When the channel is closed, all blocked fibers
     * must be resumed.
     *
     * @var array<int, \SplQueue<\Fiber>>
     */
    private static array $waiting = [];

    private int $id;
    private bool $closed = false;
    private mixed $value = null;
    private ?\Fiber $creatingFiber;
    private object $flag;

    /**
     * Channels are either blocking reads or writes. When the channel is in
     * the {@see self::READY} state, both reads and writes would block.
     */
    private int $state = self::READY;

    /**
     * When a channel reads a message from a blocked writer, it temporarily
     * registers itself as the receiving fiber. The writer is resumed and
     * writes the value to {@see self::$value} and then immediately resumes
     * the reader.
     */
    private ?\Fiber $receiver;

    public function __construct()
    {
        $this->id                 = \spl_object_id($this);
        self::$waiting[$this->id] = new \SplQueue();
        $this->creatingFiber      = \phasync::getFiber();
        $this->flag               = new \stdClass();
    }

    public function __destruct()
    {
        $this->close();
        unset(self::$waiting[$this->id]);
    }

    public function activate(): void
    {
        $this->creatingFiber = null;
    }

    public function getIterator(): \Traversable
    {
        while (!$this->isClosed()) {
            yield $this->read();
        }
    }

    public function isReady(): bool
    {
        return !($this->isReadyForRead() || $this->isReadyForWrite());
    }

    public function await(float $timeout = \PHP_FLOAT_MAX): void
    {
        $timesOut = \microtime(true) + $timeout;
        if (!$this->isReady()) {
            \phasync::awaitFlag($this->flag, $timesOut - \microtime(true));
        }
    }

    public function awaitReadable(float $timeout = \PHP_FLOAT_MAX): void
    {
        $timesOut = \microtime(true) + $timeout;
        while (!$this->isReadyForRead()) {
            \phasync::awaitFlag($this->flag, $timesOut - \microtime(true));
        }
    }

    public function isReadyForWrite(): bool
    {
        if ($this->creatingFiber) {
            if ($this->creatingFiber === \phasync::getFiber()) {
                throw new ChannelException("Can't open a channel from the same coroutine that created it");
            }
            $this->creatingFiber = null;
        }

        return self::BLOCKING_READS === $this->state;
    }

    public function awaitWritable(float $timeout = \PHP_FLOAT_MAX): void
    {
        $timesOut = \microtime(true) + $timeout;
        while (!$this->isReadyForWrite()) {
            \phasync::awaitFlag($this->flag, $timesOut - \microtime(true));
        }
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        if (self::BLOCKING_READS === $this->state) {
            while (!self::$waiting[$this->id]->isEmpty()) {
                \phasync::enqueue(self::$waiting[$this->id]->dequeue());
            }
        } else {
            while (!self::$waiting[$this->id]->isEmpty()) {
                \phasync::enqueueWithException(self::$waiting[$this->id]->dequeue(), new ChannelException('Channel was closed'));
            }
        }
        \phasync::raiseFlag($this->flag);
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function write(\Serializable|array|string|float|int|bool|null $value, float $timeout = \PHP_FLOAT_MAX): void
    {
        if ($this->closed) {
            throw new ChannelException('Channel is closed');
        }

        $this->awaitWritable($timeout);

        \phasync::raiseFlag($this->flag);

        $fiber = \phasync::getFiber();

        if ($this->creatingFiber) {
            if ($this->creatingFiber === $fiber) {
                $e = new ChannelException("Can't open a channel from the same coroutine that created it");
                $e->rebuildStackTrace();
                throw $e;
            }
            $this->creatingFiber = null;
        }

        if (self::$waiting[$this->id]->isEmpty()) {
            $this->state = self::BLOCKING_WRITES;
        }
        if (self::BLOCKING_WRITES === $this->state) {
            /*
             * I am a waiting writer.
             */
            self::$waiting[$this->id]->enqueue($fiber);
            \Fiber::suspend();
            $reader         = $this->receiver;
            $this->receiver = null;
        } else {
            /**
             * Using a waiting reader.
             */
            $reader = self::$waiting[$this->id]->dequeue();
        }
        $this->value = $value;
        $value       = null;
        \phasync::enqueue($fiber);
        \Fiber::suspend($reader);
    }

    public function read(float $timeout = \PHP_FLOAT_MAX): \Serializable|array|string|float|int|bool|null
    {
        if ($this->closed) {
            return null;
        }

        $this->awaitReadable($timeout);

        \phasync::raiseFlag($this->flag);

        $fiber = \phasync::getFiber();

        if ($this->creatingFiber) {
            if ($this->creatingFiber === $fiber) {
                throw new ChannelException("Can't open a channel from the same coroutine that created it");
            }
            $this->creatingFiber = null;
        }

        if (self::$waiting[$this->id]->isEmpty()) {
            $this->state = self::BLOCKING_READS;
        }
        if (self::BLOCKING_READS === $this->state) {
            /*
             * I am a waiting reader. I will be resumed with the result, and the
             * writer is responsible for allowing us to continue.
             */
            self::$waiting[$this->id]->enqueue($fiber);
            \Fiber::suspend();
        } else {
            /**
             * I'm using a waiting writer.
             */
            $writer         = self::$waiting[$this->id]->dequeue();
            $this->receiver = $fiber;
            \Fiber::suspend($writer);
        }
        $result      = $this->value;
        $this->value = null;

        return $result;
    }

    public function isReadable(): bool
    {
        return !$this->closed && self::BLOCKING_WRITES !== $this->state;
    }

    public function isWritable(): bool
    {
        return !$this->closed && self::BLOCKING_READS !== $this->state;
    }

    public function isReadyForRead(): bool
    {
        if ($this->creatingFiber) {
            if ($this->creatingFiber === \phasync::getFiber()) {
                throw new ChannelException("Can't open a channel from the same coroutine that created it");
            }
            $this->creatingFiber = null;
        }

        return self::BLOCKING_WRITES === $this->state;
    }
}
