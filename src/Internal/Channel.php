<?php

namespace phasync\Internal;

use phasync\ChannelException;
use phasync\TimeoutException;

/**
 * @internal not part of the public API; may change in any release
 */
final class Channel implements ChannelBackendInterface, \IteratorAggregate
{
    private static int $blockedCount = 0;
    private int $id;
    private \SplQueue $buffer;
    private int $capacity;
    private object $flag;
    private bool $closed          = false;
    private bool $readingClosed   = false;
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
            // Ready to accept a NEW write when there is no unconsumed pending value --
            // i.e. once a reader has taken the last one (or none has been written yet).
            // (Previously returned $this->hasPendingWrite, the inverse of this -- which
            // made write() return the instant it queued a value instead of waiting for a
            // reader to consume it, and made isReady() report "ready" exactly while a
            // write is unconsumed. See CHN-1 in docs/SEMANTICS.md.)
            return !$this->hasPendingWrite;
        }

        return $this->buffer->count() < $this->capacity;
    }

    public function isReady(): bool
    {
        return $this->isReadable() || $this->isWritable();
    }

    public function awaitReadable(float $timeout = \PHP_FLOAT_MAX): void
    {
        $timesOut = \microtime(true) + $timeout;
        while (!$this->isReadyForRead()) {
            $this->awaitFlag($timesOut);
        }
    }

    public function awaitWritable(float $timeout = \PHP_FLOAT_MAX): void
    {
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

    public function read(float $timeout = \PHP_FLOAT_MAX, ?bool &$eof = null): mixed
    {
        $eof      = false;
        $timesOut = \microtime(true) + $timeout;

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
                $eof = true;

                return null;
            }

            $remainingTimeout = self::calculateRemainingTimeout($timesOut);

            if ($remainingTimeout <= 0) {
                throw new TimeoutException('Channel read operation timed out');
            }

            try {
                $this->awaitFlag($timesOut);
            } catch (TimeoutException $e) {
                throw new TimeoutException('Channel read operation timed out', 0, $e);
            }
        }
    }

    public function write(mixed $value, float $timeout = \PHP_FLOAT_MAX): void
    {
        if ($this->closed) {
            throw new ChannelException('Channel is closed');
        }

        $timesOut = \microtime(true) + $timeout;

        while (true) {
            if ($this->closed) {
                // Writes are never permitted on a closed channel
                throw new ChannelException('Channel is closed');
            } elseif (0 === $this->capacity && !$this->hasPendingWrite) {
                // Unbuffered channel can be written to, but always blocks
                \phasync::raiseFlag($this->flag);
                $this->hasPendingWrite = true;
                $this->pendingWrite    = $value;
                try {
                    // Block until a reader has consumed the value (making the channel writable again)
                    $this->awaitWritable(self::calculateRemainingTimeout($timesOut));
                    if ($this->readingClosed && $this->hasPendingWrite) {
                        $this->hasPendingWrite = false;
                        $this->pendingWrite    = null;
                        throw new ChannelException('Channel is closed');
                    }

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

    public function closeReading(): void
    {
        $this->readingClosed = true;
        $this->close();
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

    private static function calculateRemainingTimeout(float $timesOut): float
    {
        return \max(0, $timesOut - \microtime(true));
    }
}
