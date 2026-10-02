<?php

namespace phasync\Util;

use phasync\Internal\DeadmanException;
use phasync\Internal\DeadmanSwitchTrait;
use phasync\SelectableInterface;
use phasync\TimeoutException;

/**
 * A buffer of streamed bytes that one coroutine fills and another reads, for parsing protocols that arrive in frames.
 *
 * Designed for servers (HTTP, FastCGI, WebSocket) that read fixed-size frames from a byte stream. A reader waits for data, `readFixed()` waits for a whole frame, and `end()` marks the end of the stream. Unbounded by default: a writer that outpaces its reader grows the buffer without limit. With `$maxSize`, `write()` waits until the reader has made room. For passing values between coroutines, use a channel.
 *
 * If the writing coroutine exits without calling `end()`, readers must not wait forever. Ask for `getDeadmanSwitch()` in the writer and keep the object until it ends: when it is destroyed without `end()` having been called, a reader that has read the buffered data gets an exception instead of waiting.
 *
 * ```php
 * phasync::run(function () {
 *     $buffer = new phasync\Util\StringBuffer();
 *
 *     phasync::go(function () use ($buffer) {
 *         $buffer->write("he");
 *         phasync::sleep(0.01);
 *         $buffer->write("llo\n");
 *         $buffer->end();
 *     });
 *
 *     var_dump($buffer->readFixed(5));   // string(5) "hello", after both writes
 *     var_dump($buffer->read(10));       // string(1) "\n"
 *     var_dump($buffer->read(10));       // string(0) "": the buffer has ended
 * });
 * ```
 *
 * @see phasync::channel
 * @see phasync\SelectableInterface
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
     * The most bytes write() will let accumulate unread before it blocks the
     * writer, or null for no limit (the default). Measured the same way
     * readFromResource() already measured it: total bytes written minus
     * total bytes actually consumed by read()/readFixed().
     */
    private ?int $maxSize = null;

    /**
     * Creates an empty buffer.
     *
     * @param int<1,max>|null $maxSize the most bytes `write()` lets accumulate unread before it waits; null: no limit
     *
     * @throws \OutOfBoundsException if `$maxSize` is given and below 1
     */
    public function __construct(?int $maxSize = null)
    {
        if (null !== $maxSize && $maxSize < 1) {
            throw new \OutOfBoundsException('$maxSize must be at least 1, or null for unbounded');
        }
        $this->maxSize   = $maxSize;
        $this->queue     = new \SplDoublyLinkedList();
    }

    /**
     * Waits until the buffer has data to read or has ended.
     *
     * Returns when data is available, the buffer has ended or failed, or the timeout has passed: it does not throw on a timeout, so check `isReady()` after it.
     *
     * @param float $timeout seconds to wait at most
     *
     * @see StringBuffer::isReady
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
            } catch (TimeoutException) {
                return;
            }
        }
    }

    /**
     * Returns true if a read would not wait: data is buffered, or the buffer has ended or failed.
     *
     * @see StringBuffer::await
     */
    public function isReady(): bool
    {
        if ($this->ended || $this->failed) {
            return true;
        }

        return !$this->isEmpty();
    }

    /**
     * Returns true if no data is buffered for reading.
     */
    public function isEmpty(): bool
    {
        return $this->offset === $this->length && $this->queue->isEmpty();
    }

    /**
     * Appends `$chunk` to the buffer, and wakes the reader.
     *
     * With a `$maxSize` and that much data unread, it waits until the reader has consumed enough. A `$timeout` of 0 never waits and writes past `$maxSize`, so the limit is backpressure and not a ceiling.
     *
     * @param string $chunk   the bytes to append
     * @param float  $timeout seconds to wait for room at most
     *
     * @throws \RuntimeException if the buffer has ended
     * @throws TimeoutException  if there was no room in time
     *
     * @see StringBuffer::end
     */
    public function write(string $chunk, float $timeout = \PHP_FLOAT_MAX): void
    {
        if ($this->ended) {
            throw new \RuntimeException('Buffer has been ended');
        }
        // $timeout > 0 matches read()/readFixed(): a timeout of exactly 0 never blocks and
        // never throws here either, so it writes past $maxSize rather than fail the write --
        // the cap is backpressure (make a fast writer wait for its reader), not a hard ceiling.
        if (null !== $this->maxSize && $timeout > 0) {
            $timesOut = \microtime(true) + $timeout;
            while ($this->totalWritten - $this->totalRead >= $this->maxSize) {
                $remaining = $timesOut - \microtime(true);
                if ($remaining <= 0) {
                    throw new TimeoutException('StringBuffer write timed out waiting for buffer space');
                }
                try {
                    \phasync::awaitFlag($this->queue, $remaining);
                } catch (TimeoutException $e) {
                    throw new TimeoutException('StringBuffer write timed out waiting for buffer space', 0, $e);
                }
            }
        }
        $this->totalWritten += \strlen($chunk);
        $this->queue->push($chunk);
        \phasync::raiseFlag($this->queue);
    }

    /**
     * Returns up to `$maxLength` bytes, and waits while the buffer is empty and not ended.
     *
     * Returns what is buffered when there is any, which may be less than `$maxLength`, and an empty string once the buffer has ended and is empty. A `$timeout` of 0 never waits: it returns what is buffered, possibly an empty string.
     *
     * @param int   $maxLength the most bytes to return
     * @param float $timeout   seconds to wait for data at most
     *
     * @return string the bytes read
     *
     * @throws \OutOfBoundsException if `$maxLength` is negative
     * @throws TimeoutException      if no data arrived in time
     * @throws DeadmanException      if the writer exited without ending the buffer, once the buffered data is read
     *
     * @see StringBuffer::readFixed
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
            $remaining = $timesOut - \microtime(true);
            if ($remaining <= 0) {
                throw new TimeoutException('StringBuffer read timed out');
            }
            $this->await($remaining);
        }
        $this->fill($maxLength);

        $chunk  = \substr($this->buffer, $this->offset, $maxLength);
        $length = \strlen($chunk);
        $this->offset += $length;
        $this->totalRead += $length;
        if ($length > 0) {
            // Wakes a write() blocked on $maxSize backpressure, and readFromResource()'s own
            // backpressure wait below -- both need to know when consumption, not just new data
            // or new state, changes anything, and nothing else in this class signals that.
            \phasync::raiseFlag($this->queue);
        }

        return $chunk;
    }

    /**
     * Starts a coroutine that reads the stream into the buffer, and ends the buffer when the stream ends.
     *
     * Sets the stream to non-blocking. The coroutine stops reading while more than 1 MiB is unread.
     *
     * @param resource $resource a stream to read from
     *
     * @return \Fiber the coroutine; await it to see a read error
     *
     * @throws \InvalidArgumentException if `$resource` is not a stream
     *
     * @see StringBuffer::writeToResource
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

                    // Avoid reading from the resource if the buffer isn't being drained. Waits
                    // directly on the flag, not await()/isReady() -- isReady() only asks "is
                    // there anything to read", which is already true here (that's the whole
                    // reason this loop is running), so it would return at once without ever
                    // actually suspending this fiber, spinning forever instead of giving the
                    // reader coroutine a chance to run and drain the buffer.
                    while ($this->totalWritten - $this->totalRead > $bufferSize && 'stream' === \get_resource_type($resource)) {
                        \phasync::awaitFlag($this->queue);
                    }
                }
            } finally {
                $this->end();
            }
        });
    }

    /**
     * Marks the end of the stream: no more writes are accepted, and readers get the data left and then the end.
     *
     * @throws \LogicException if the buffer has ended already
     *
     * @see StringBuffer::eof
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
     * Returns true if the buffer has ended and all its data has been read.
     *
     * @see StringBuffer::end
     */
    public function eof(): bool
    {
        return $this->ended && $this->offset === $this->length && $this->queue->isEmpty();
    }

    /**
     * Returns exactly `$length` bytes, and waits until that many are buffered.
     *
     * Returns null if the buffer ends with fewer than `$length` bytes left; they stay in the buffer. A `$timeout` of 0 never waits: it returns null when `$length` bytes are not there yet.
     *
     * @param int<1,max> $length  the number of bytes
     * @param float      $timeout seconds to wait at most
     *
     * @return string|null the bytes, or null at the end of the buffer
     *
     * @throws TimeoutException if `$length` bytes were not there in time
     * @throws DeadmanException if the writer exited without ending the buffer
     *
     * @see StringBuffer::read
     * @see StringBuffer::unread
     */
    public function readFixed(int $length, float $timeout = \PHP_FLOAT_MAX): ?string
    {
        if ($length < 0) {
            throw new \OutOfBoundsException("Can't read negative lengths");
        }

        $timesOut = \microtime(true) + $timeout;

        // Fill the buffer with enough data to read and optionally await more data if not ended.
        // A timeout of exactly 0 is a non-blocking poll, as in read(): it takes what has been
        // written so far and never throws. It still fills first, or data written but not yet
        // moved into the buffer would be missed.
        while (!$this->fill($length) && !$this->ended) {
            if ($timeout <= 0) {
                break;
            }
            if ($this->failed) {
                throw new DeadmanException('Writer terminated unexpectedly');
            }
            $remaining = $timesOut - \microtime(true);
            if ($remaining <= 0) {
                throw new TimeoutException('StringBuffer readFixed timed out');
            }
            // Wait directly on the flag - don't use isReady() which may return true
            // when there's some data but not enough for our fixed length requirement
            try {
                \phasync::awaitFlag($this->queue, $remaining);
            } catch (TimeoutException $e) {
                throw new TimeoutException('StringBuffer readFixed timed out', 0, $e);
            }
        }

        // Only reachable via $this->ended, or via $timeout <= 0, never via a real timeout
        // expiring: a genuine "not enough data right now", distinct from a real timeout.
        if ($length > $this->length - $this->offset) {
            return null;
        }

        $chunk = \substr($this->buffer, $this->offset, $length);
        $this->offset += $length;
        $this->totalRead += $length;
        if ($length > 0) {
            \phasync::raiseFlag($this->queue);
        }

        return $chunk;
    }

    /**
     * Starts a coroutine that writes the buffer to the stream until the buffer has ended and been read.
     *
     * With several streams on one buffer, there is no control over which data goes where.
     *
     * @param resource $resource a stream to write to
     *
     * @return \Fiber the coroutine; its result is the number of bytes written
     *
     * @throws \InvalidArgumentException if `$resource` is not a stream
     *
     * @see StringBuffer::readFromResource
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
     * Puts `$chunk` back at the front of the buffer, to be read first.
     *
     * @param string $chunk bytes that were read and not used
     *
     * @throws \LogicException if the buffer has ended and is empty
     *
     * @see StringBuffer::readFixed
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
