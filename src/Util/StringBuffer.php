<?php

namespace phasync\Util;

use phasync\Internal\SelectableTrait;
use phasync\SelectableInterface;

/**
 * A flexible string buffer which can be used to manage streaming bytes
 * of data, optionally writing the data to a stream resource and/or reading
 * the data from a stream resource.
 */
class StringBuffer implements SelectableInterface
{
    use SelectableTrait;

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
    public \SplDoublyLinkedList $queue;

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

    private bool $ended = false;

    public function __construct()
    {
        $this->queue     = new \SplDoublyLinkedList();
    }

    public function selectWillBlock(): bool
    {
        // If the StringBuffer was ended, reading will never block
        if ($this->ended) {
            return false;
        }

        return $this->isEmpty();
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
        $this->getSelectManager()->notify();
    }

    /**
     * Read up to $maxLength bytes from the buffer.
     *
     * @param bool $await If true, the read result will always return data or an empty string at eof
     *
     * @throws \OutOfBoundsException
     */
    public function read(int $maxLength, bool $await=false): string
    {
        if ($maxLength < 0) {
            throw new \OutOfBoundsException("Can't read negative lengths");
        }

        while ($await && !$this->ended && !$this->fill(1)) {
            $this->await();
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
    public function writeFromResource($resource): \Fiber
    {
        if (!\is_resource($resource) || 'stream' !== \get_resource_type($resource)) {
            throw new \InvalidArgumentException('Expected stream resource');
        }
        $bufferSize = 1024 * 1024;
        \stream_set_blocking($resource, false);

        return \phasync::go(function () use ($bufferSize, $resource) {
            while (!\feof($resource) && !$this->ended) {
                \phasync::readable($resource);
                $chunk = \fread($resource, 65536);
                if (false === $chunk) {
                    throw new \RuntimeException("Can't read from the stream resource");
                }
                $this->write($chunk);

                while ($this->totalWritten - $this->totalRead > $bufferSize && 'stream' === \get_resource_type($resource)) {
                    $this->await();
                }
            }
            $this->end();
        });
    }

    /**
     * Signal the equivalent of an end of file. No further writes
     * will be accepted.
     */
    public function end(): void
    {
        if ($this->ended) {
            throw new \LogicException('StringBuffer already ended');
        }
        $this->ended = true;
        $this->getSelectManager()->notify();
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
     * if the amount of data is not available.
     *
     * @param int<1,max> $length
     */
    public function readFixed(int $length, bool $await=false): ?string
    {
        if ($length < 0) {
            throw new \OutOfBoundsException("Can't read negative lengths");
        }

        while (!$this->fill($length) && $await && !$this->ended) {
            $this->await();
        }

        if ($length < $this->length - $this->offset) {
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
    public function readToResource($resource): \Fiber
    {
        if (!\is_resource($resource) || 'stream' !== \get_resource_type($resource)) {
            throw new \InvalidArgumentException('Expected stream resource');
        }

        return \phasync::go(function () use ($resource) {
            $totalBytes = 0;
            while (!$this->eof() && \is_resource($resource) && 'stream' === \get_resource_type($resource)) {
                $chunk       = $this->read(65536, true);
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
        $this->getSelectManager()->notify();
    }

    /**
     * Function that grows the string buffer until it can be
     * used to read at least $chunkLength bytes.
     *
     * @return bool True if able to provide enough data
     */
    protected function fill(int $chunkLength): bool
    {
        // Time to clear the buffer?
        if ($this->offset > self::BUFFER_WASTE_LIMIT) {
            $this->buffer = \substr($this->buffer, $this->offset);
            $this->length -= $this->offset;
            $this->offset = 0;
        }

        while ($this->length < $this->offset + $chunkLength && !$this->queue->isEmpty()) {
            $chunk = $this->queue->shift();
            $this->buffer .= $chunk;
            $this->length += \strlen($chunk);
        }

        return $this->length >= $this->offset + $chunkLength;
    }
}
