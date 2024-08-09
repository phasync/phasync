<?php

namespace phasync\Util;

use phasync\SelectableInterface;

/**
 * A high performance string buffer for buffering streaming data that can be
 * parsed efficiently. Be aware that this string buffer offers no protection
 * against deadlocks, so you should always end the string buffer to signal
 * EOF with $sb->end();
 */
class StringBuffer implements SelectableInterface {
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

    public function __construct() {
        $this->queue     = new \SplDoublyLinkedList();
    }

    public function await(float $timeout = \PHP_FLOAT_MAX): void {
        $timesOut = \microtime(true) + \PHP_FLOAT_MAX;
        while (!$this->isReady()) {
            \phasync::awaitFlag($this->queue, $timesOut - \microtime(true));
        }
    }

    public function isReady(): bool {
        // If the StringBuffer was ended, reading will never block
        if ($this->ended) {
            return true;
        }

        return !$this->isEmpty();
    }

    /**
     * Returns true if there is no data currently available to read.
     */
    public function isEmpty(): bool {
        return $this->offset === $this->length && $this->queue->isEmpty();
    }

    /**
     * Write data to the buffer
     */
    public function write(string $chunk): void {
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
     */
    public function read(int $maxLength, float $timeout = \PHP_FLOAT_MAX): string {
        if ($maxLength < 0) {
            throw new \OutOfBoundsException("Can't read negative lengths");
        }

        $timesOut = \microtime(true) + $timeout;
        while (0 != $timeout && !$this->ended && !$this->fill(1)) {
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
    public function readFromResource($resource): \Fiber {
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
     */
    public function end(): void {
        if ($this->ended) {
            throw new \LogicException('StringBuffer already ended');
        }
        $this->ended = true;
        \phasync::raiseFlag($this->queue);
    }

    /**
     * True if the end of file has been reached.
     */
    public function eof(): bool {
        return $this->ended && $this->offset === $this->length && $this->queue->isEmpty();
    }

    /**
     * Read a fixed number of bytes from the buffer, and return null
     * if the buffer is or becomes ended.
     *
     * @param int<1,max> $length
     */
    public function readFixed(int $length, float $timeout = \PHP_FLOAT_MAX): ?string {
        if ($length < 0) {
            throw new \OutOfBoundsException("Can't read negative lengths");
        }

        $timesOut = \microtime(true) + $timeout;

        // Fill the buffer with enough data to read and optionally await more data if not ended
        while (!$this->fill($length) && 0 !== $timeout && !$this->ended) {
            $this->await($timesOut - \microtime(true));
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
    public function writeToResource($resource): \Fiber {
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
    public function unread(string $chunk): void {
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
    protected function fill(int $requiredLength): bool {
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
