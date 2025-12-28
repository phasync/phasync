<?php

namespace phasync\Util;

use phasync\DeadmanException;
use phasync\DeadmanSwitchTrait;
use phasync\SelectableInterface;

/**
 * A high performance string buffer for buffering streaming data that can be
 * parsed efficiently. Designed for protocol parsing in servers (HTTP, FastCGI,
 * WebSocket) where you need to read fixed-size frames from a byte stream.
 *
 * For safe coroutine-to-coroutine communication, use Channels instead.
 *
 * To prevent readers from waiting forever if the writer crashes, use the
 * deadman switch feature:
 *
 * ```php
 * phasync::go(function() use ($sb, $socket) {
 *     $deadman = $sb->getDeadmanSwitch();
 *     while ($data = fread($socket, 8192)) {
 *         $sb->write($data);
 *     }
 *     $sb->end(); // Always end properly - deadman is just a safety net
 * });
 * ```
 *
 * If the writer exits without calling end(), the deadman switch triggers and
 * any blocking read will throw DeadmanException. Buffered data can still be
 * read before the exception is thrown.
 */
class StringBuffer implements SelectableInterface
{
    use DeadmanSwitchTrait;
    /**
     * How much unusable string data can the string buffer hold
     * before we must perform substr to remove data already returned.
     */
    public const BUFFER_WASTE_LIMIT = 4096;

    /**
     * Contains the chunks of binary data appended or prepended
     *
     * @var \SplDoublyLinkedList<string>
     */
    protected \SplDoublyLinkedList $queue;

    /**
     * Contains unread bytes, for situations where data was consumed
     * and not used.
     */
    protected string $buffer    = '';

    /**
     * The length of the unread bytes buffer
     */
    protected int $length       = 0;
    /**
     * The read offset in the unread bytes buffer, to reduce the number
     * of string trimming operations.
     */
    protected int $offset       = 0;

    /**
     * The total number of bytes that have been read from the string
     * buffer. This number includes "unread" bytes as well.
     */
    private int $totalRead      = 0;

    /**
     * The total number of bytes that have been written to the buffer.
     */
    private int $totalWritten   = 0;

    /**
     * Has the end of data been signalled?
     */
    private bool $ended = false;

    /**
     * True if the writer terminated unexpectedly (deadman switch triggered).
     */
    private bool $failed = false;

    /**
     * Create a new StringBuffer instance.
     */
    public function __construct()
    {
        $this->queue     = new \SplDoublyLinkedList();
    }

    /**
     * Wait until the buffer has data available to read or has been ended.
     * Returns when data is available, buffer is ended, or timeout expires.
     *
     * @param float $timeout Maximum time to wait in seconds (default: infinity)
     */
    public function await(float $timeout = \PHP_FLOAT_MAX): void
    {
        $timesOut = \microtime(true) + $timeout;
        while (!$this->isReady()) {
            $remaining = $timesOut - \microtime(true);
            if ($remaining <= 0) {
                return;
            }
            try {
                \phasync::awaitFlag($this->queue, $remaining);
            } catch (\phasync\TimeoutException) {
                return;
            }
        }
    }

    /**
     * Check if a read operation would not block.
     *
     * Returns true if:
     * - The buffer has data available to read, OR
     * - The buffer has been ended (reading returns empty string or data), OR
     * - The buffer has failed (reading will throw DeadmanException)
     *
     * @return bool True if reading would not block
     */
    public function isReady(): bool
    {
        if ($this->ended || $this->failed) {
            return true;
        }

        return !$this->isEmpty();
    }

    /**
     * Returns true if there is no data currently available to read.
     */
    public function isEmpty(): bool
    {
        return $this->offset === $this->length && $this->queue->isEmpty();
    }

    /**
     * Write data to the buffer
     */
    public function write(string $chunk): void
    {
        if ($this->ended) {
            throw new \RuntimeException('Buffer has been ended');
        }
        $this->totalWritten += \strlen($chunk);
        $this->queue->push($chunk);
        \phasync::raiseFlag($this->queue);
        \phasync::preempt();
    }

    /**
     * Read up to $maxLength bytes from the buffer.
     *
     * @throws \OutOfBoundsException
     * @throws DeadmanException      If would block and the writer terminated unexpectedly
     */
    public function read(int $maxLength, float $timeout = \PHP_FLOAT_MAX): string
    {
        if ($maxLength < 0) {
            throw new \OutOfBoundsException("Can't read negative lengths");
        }

        $timesOut = \microtime(true) + $timeout;
        while ($timeout > 0 && !$this->ended && !$this->fill(1)) {
            if ($this->failed) {
                throw new DeadmanException('Writer terminated unexpectedly');
            }
            $this->await($timesOut - \microtime(true));
        }
        $this->fill($maxLength);

        $chunk  = \substr($this->buffer, $this->offset, $maxLength);
        $length = \strlen($chunk);
        $this->offset += $length;

        return $chunk;
    }

    /**
     * Asynchronously read data from the stream resource into the
     * buffer.
     */
    public function readFromResource($resource): \Fiber
    {
        if (!\is_resource($resource) || 'stream' !== \get_resource_type($resource)) {
            throw new \InvalidArgumentException('Expected stream resource');
        }
        $bufferSize = 1024 * 1024;
        \stream_set_blocking($resource, false);

        return \phasync::go(function () use ($bufferSize, $resource) {
            try {
                while (!\feof($resource) && !$this->ended) {
                    $chunk = \fread(\phasync::readable($resource), 65536);
                    if (false === $chunk) {
                        throw new \RuntimeException("Can't read from the stream resource");
                    }
                    $this->write($chunk);

                    // Avoid reading from the resource if the buffer isn't being drained.
                    while ($this->totalWritten - $this->totalRead > $bufferSize && 'stream' === \get_resource_type($resource)) {
                        $this->await();
                    }
                }
            } finally {
                $this->end();
            }
        });
    }

    /**
     * Signal the equivalent of an end of file. No further writes
     * will be accepted.
     *
     * @throws \LogicException If the buffer has already been ended
     */
    public function end(): void
    {
        if ($this->ended) {
            throw new \LogicException('StringBuffer already ended');
        }
        $this->ended = true;
        \phasync::raiseFlag($this->queue);
    }

    /**
     * Called when the deadman switch is triggered.
     * Marks the buffer as failed and wakes any waiting readers.
     */
    protected function deadmanSwitchTriggered(): void
    {
        $this->failed = true;
        \phasync::raiseFlag($this->queue);
    }

    /**
     * True if the end of file has been reached.
     */
    public function eof(): bool
    {
        return $this->ended && $this->offset === $this->length && $this->queue->isEmpty();
    }

    /**
     * Read a fixed number of bytes from the buffer, and return null
     * if the buffer is or becomes ended, or if timeout expires.
     *
     * @param int<1,max> $length
     *
     * @throws DeadmanException If the deadman switch was triggered
     */
    public function readFixed(int $length, float $timeout = \PHP_FLOAT_MAX): ?string
    {
        if ($length < 0) {
            throw new \OutOfBoundsException("Can't read negative lengths");
        }

        $timesOut = \microtime(true) + $timeout;

        // Fill the buffer with enough data to read and optionally await more data if not ended
        while (!$this->fill($length) && !$this->ended) {
            if ($this->failed) {
                throw new DeadmanException('Writer terminated unexpectedly');
            }
            $remaining = $timesOut - \microtime(true);
            if ($remaining <= 0) {
                break;
            }
            // Wait directly on the flag - don't use isReady() which may return true
            // when there's some data but not enough for our fixed length requirement
            try {
                \phasync::awaitFlag($this->queue, $remaining);
            } catch (\phasync\TimeoutException) {
                // Timeout expired, exit the loop
                break;
            }
        }

        if ($length > $this->length - $this->offset) {
            return null;
        }

        $chunk = \substr($this->buffer, $this->offset, $length);
        $this->offset += $length;

        return $chunk;
    }

    /**
     * Data that enters the buffer will be written asynchronously to the
     * stream resource. If reading to multiple resources, there is no way
     * to control which data is routed to which resource.
     */
    public function writeToResource($resource): \Fiber
    {
        if (!\is_resource($resource) || 'stream' !== \get_resource_type($resource)) {
            throw new \InvalidArgumentException('Expected stream resource');
        }

        return \phasync::go(function () use ($resource) {
            $totalBytes = 0;
            while (!$this->eof() && \is_resource($resource) && 'stream' === \get_resource_type($resource)) {
                $chunk       = $this->read(65536);
                $chunkLength = \strlen($chunk);
                \phasync::writable($resource);
                $written = \fwrite($resource, $chunk);
                if (false === $written) {
                    throw new \RuntimeException('Unable to write to resource');
                }
                if ($written < $chunkLength) {
                    $this->unread(\substr($chunk, $written));
                }
                $totalBytes += $written;
            }

            return $totalBytes;
        });
    }

    /**
     * Prepend data to the buffer.
     */
    public function unread(string $chunk): void
    {
        if ($this->ended && $this->offset === $this->length && $this->queue->isEmpty()) {
            throw new \LogicException("Can't unread to an ended and empty StringBuffer");
        }
        $chunkLength = \strlen($chunk);
        $this->totalRead -= $chunkLength;
        if ($this->length === $this->offset) {
            $this->queue->unshift($chunk);
        } else {
            $this->buffer = $chunk . \substr($this->buffer, $this->offset);
            $this->length += $chunkLength - $this->offset;
            $this->offset = 0;
        }
        \phasync::raiseFlag($this->queue);
    }

    /**
     * Function that grows the string buffer until it can be
     * used to read at least $chunkLength bytes.
     *
     * @return bool True if able to provide enough data
     */
    protected function fill(int $requiredLength): bool
    {
        // Clear buffer if necessary
        if ($this->offset > self::BUFFER_WASTE_LIMIT) {
            $this->buffer = \substr($this->buffer, $this->offset);
            $this->length -= $this->offset;
            $this->offset = 0;
        }

        while ($this->length < $this->offset + $requiredLength && !$this->queue->isEmpty()) {
            $chunk       = $this->queue->shift();
            $chunkLength = \strlen($chunk);

            if ($this->length === $this->offset) {
                $this->buffer = $chunk;
                $this->length = $chunkLength;
                $this->offset = 0;
            } else {
                $this->buffer .= $chunk;
                $this->length += $chunkLength;
            }
        }

        return $this->length >= $this->offset + $requiredLength;
    }
}
