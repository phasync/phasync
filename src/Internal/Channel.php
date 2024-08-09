<?php

namespace phasync\Internal;

use phasync\ChannelException;
use phasync\Debug;
use phasync\TimeoutException;

final class Channel implements ChannelBackendInterface, \IteratorAggregate
{
    private static int $blockedCount = 0;
    private int $id;
    private \SplQueue $buffer;
    private int $capacity;
    private object $flag;
    private bool $closed = false;
    private ?\Fiber $creatorFiber;
    private mixed $pendingWrite   = null;
    private bool $hasPendingWrite = false;

    public function __construct(int $capacity = 0)
    {
        $this->id = \spl_object_id($this);
        if ($capacity > 0) {
            $this->buffer = new \SplQueue();
        }
        $this->capacity     = $capacity;
        $this->flag         = new \stdClass();
        $this->creatorFiber = \phasync::getFiber();
    }

    public function isReadyForRead(): bool
    {
        if ($this->closed) {
            return true;
        } elseif (0 === $this->capacity) {
            return $this->hasPendingWrite;
        }

        return !$this->buffer->isEmpty();
    }

    public function isReadyForWrite(): bool
    {
        if ($this->closed) {
            return true;
        } elseif (0 === $this->capacity) {
            return $this->hasPendingWrite;
        }

        return $this->buffer->count() < $this->capacity;
    }

    public function isReady(): bool
    {
        return $this->isReadable() || $this->isWritable();
    }

    public function awaitReadable(float $timeout = \PHP_FLOAT_MAX): void
    {
        $this->ensureNotCreatorFiber();
        $timesOut = \microtime(true) + $timeout;
        while (!$this->isReadyForRead()) {
            $this->awaitFlag($timesOut);
        }
    }

    public function awaitWritable(float $timeout = \PHP_FLOAT_MAX): void
    {
        $this->ensureNotCreatorFiber();
        $timesOut = \microtime(true) + $timeout;
        while (!$this->isReadyForWrite()) {
            $this->awaitFlag($timesOut);
        }
    }

    public function await(float $timeout = \PHP_FLOAT_MAX): void
    {
        $timesOut = \microtime(true) + $timeout;
        while (!$this->isReady()) {
            $this->awaitFlag($timesOut);
        }
    }

    public function activate(): void
    {
        $this->creatorFiber = null;
    }

    public function isReadable(): bool
    {
        return !$this->closed || $this->hasReadableValue();
    }

    public function isWritable(): bool
    {
        return !$this->closed;
    }

    public function getIterator(): \Traversable
    {
        if ($this->closed && !$this->hasReadableValue()) {
            return;
        }
        do {
            yield $this->read();
        } while (!$this->closed || $this->hasReadableValue());
    }

    public function read(float $timeout = \PHP_FLOAT_MAX): \Serializable|array|string|float|int|bool|null
    {
        $timesOut = \microtime(true) + $timeout;

        // This sleep prevents some deadlocks that may be unexpected by developers
        \phasync::sleep();

        $checked = false;

        while (true) {
            if (0 === $this->capacity && $this->hasPendingWrite) {
                // Unbuffered channel. If a value is available, we return it even if the channel is closed.
                $result                = $this->pendingWrite;
                $this->hasPendingWrite = false;
                \phasync::raiseFlag($this->flag);

                return $result;
            } elseif ($this->capacity > 0 && !$this->buffer->isEmpty()) {
                // Buffered channel. If values are available we keep returning even if the channel is closed.
                \phasync::raiseFlag($this->flag);

                return $this->buffer->dequeue();
            } elseif ($this->closed) {
                // Channel is closed and there is no data.
                return null;
            }

            $remainingTimeout = self::calculateRemainingTimeout($timesOut);

            if ($remainingTimeout <= 0) {
                throw new TimeoutException('Channel read operation timed out');
            }

            if (!$checked) {
                $this->ensureNotCreatorFiber();
                $checked = true;
                continue;
            }

            try {
                $this->awaitFlag($timesOut);
            } catch (TimeoutException $e) {
                throw new TimeoutException('Channel read operation timed out', 0, $e);
            }
        }
    }

    public function write($value, float $timeout = \PHP_FLOAT_MAX): void
    {
        if ($this->closed) {
            throw new ChannelException('Channel is closed');
        }

        $timesOut = \microtime(true) + $timeout;

        // This sleep prevents some deadlocks that may be unexpected by developers
        \phasync::sleep();

        $checked = false;

        while (true) {
            if ($this->closed) {
                // Writes are never permitted on a closed channel
                throw new ChannelException('Channel is closed');
            } elseif (0 === $this->capacity && !$this->hasPendingWrite) {
                // Unbuffered channel can be written to, but always blocks
                if (!$checked) {
                    $this->ensureNotCreatorFiber();
                    $checked = true;
                }

                \phasync::raiseFlag($this->flag);
                $this->hasPendingWrite = true;
                $this->pendingWrite    = $value;
                try {
                    // Block until a reader has consumed the value (making the channel writable again)
                    $this->awaitWritable(self::calculateRemainingTimeout($timesOut));

                    return;
                } catch (TimeoutException $e) {
                    $this->hasPendingWrite = false;
                    $this->pendingWrite    = null;
                    throw new TimeoutException('Timeout during write (never read)', 0, $e);
                }
            } elseif ($this->capacity > 0 && $this->buffer->count() < $this->capacity) {
                // Buffered channel has capacity
                $this->buffer->enqueue($value);
                \phasync::raiseFlag($this->flag);

                return;
            }

            // Keep waiting
            $remainingTimeout = self::calculateRemainingTimeout($timesOut);
            if ($remainingTimeout <= 0) {
                throw new TimeoutException('Channel write operation timed out');
            }

            if (!$checked) {
                $this->ensureNotCreatorFiber();
                $checked = true;
                continue;
            }

            try {
                $this->awaitFlag($timesOut);
            } catch (TimeoutException $e) {
                throw new TimeoutException('Channel write operation timed out', 0, $e);
            }
        }
    }

    public function close(): void
    {
        $this->closed = true;
        \phasync::raiseFlag($this->flag);
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    private function awaitFlag(float $timesOut): void
    {
        try {
            ++self::$blockedCount;
            \phasync::awaitFlag($this->flag, self::calculateRemainingTimeout($timesOut));
        } finally {
            --self::$blockedCount;
        }
    }

    private function hasReadableValue(): bool
    {
        return $this->hasPendingWrite || ($this->capacity > 0 && !$this->buffer->isEmpty());
    }

    private function ensureNotCreatorFiber(): void
    {
        if ($this->creatorFiber?->isRunning()) {
            // Avoid a bunch of problems by allowing other coroutines to do some stuff
            // and we'll check again
            while (self::$blockedCount > 0) {
                \phasync::sleep();
                \phasync::sleep();
                if (!$this->creatorFiber?->isRunning()) {
                    return;
                }
            }
            throw new ChannelException('Cannot perform blocking operations on a channel from its creator fiber ' . Debug::getDebugInfo($this));
        }
        $this->creatorFiber = null;
    }

    private static function calculateRemainingTimeout(float $timesOut): float
    {
        return \max(0, $timesOut - \microtime(true));
    }
}
