<?php

namespace phasync\Psr;

use phasync;
use phasync\Internal\ExceptionTool;
use phasync\TimeoutException;
use Psr\Http\Message\StreamInterface;

/**
 * A PSR-7 stream for a body that is still being produced, read once and in order.
 *
 * One coroutine appends with {@see UnbufferedStream::append()} and ends with {@see UnbufferedStream::end()};
 * another reads. `read()` waits while there is nothing to read and the stream is not ended, and
 * `append()` waits while more than `$bufferSize` bytes are unread. The stream is not seekable or writable,
 * and `getSize()` is null.
 *
 * Both sides wait with `phasync::awaitFlag()`: after `$deadlockTimeout` seconds without the other side
 * making progress, the waiting call throws a `TimeoutException`.
 *
 * The coroutine that created the stream cannot convert it to a string: `__toString()` returns an
 * error message instead of waiting for itself.
 *
 * ```php
 * $stream = new UnbufferedStream();
 *
 * phasync::go(function () use ($stream) {
 *     foreach (['one', 'two', 'three'] as $chunk) {
 *         $stream->append($chunk . "\n");
 *         phasync::sleep(0.1);
 *     }
 *     $stream->end();
 * });
 *
 * // A server sends this body to the client, reading it from another coroutine
 * $response = $response->withBody($stream);
 * ```
 *
 * @see BufferedStream when the body must be seekable or have a size
 * @see StringStream for a body that is complete
 */
class UnbufferedStream implements StreamInterface
{
    /**
     * The string buffer
     */
    private string $buffer = '';

    private int $bufferSize;
    private int $readOffset = 0;
    private float $deadlockTimeout;
    private bool $closed   = false;
    private bool $ended    = false;
    private bool $locked   = false;
    private bool $detached = false;
    private \WeakReference $creator;
    private object $readFlag;
    private object $writeFlag;

    /**
     * Creates an empty stream, owned by the current coroutine.
     *
     * @param int   $bufferSize      the number of unread bytes after which `append()` waits for the reader
     * @param float $deadlockTimeout seconds that `read()` and `append()` wait for the other side before throwing a `TimeoutException`
     */
    public function __construct(int $bufferSize = 64 * 1024, float $deadlockTimeout = 60)
    {
        $this->bufferSize      = $bufferSize;
        $this->deadlockTimeout = $deadlockTimeout;
        $this->creator         = \WeakReference::create(\phasync::getFiber());
        $this->readFlag        = new \stdClass();
        $this->writeFlag       = new \stdClass();
    }

    /**
     * Returns the rest of the stream, or a message starting with "Stream Error" when it cannot: it was
     * closed or detached, or this is the coroutine that created the stream.
     */
    public function __toString(): string
    {
        if ($this->creator->get() === \phasync::getFiber()) {
            return 'Stream Error: Can\'t access stream from the coroutine that created it';
        }
        if ($this->detached) {
            return 'Stream Error: Detached';
        }
        if ($this->closed) {
            return 'Stream Error: Closed';
        }

        return $this->getContents();
    }

    /**
     * Discards the unread content.
     *
     * Reads until `end()` has been called, so that the appending coroutine is not left waiting, and then
     * releases the buffer. It waits as long as the writer takes.
     */
    public function close(): void
    {
        // Ensure the writer is not blocked indefinitely
        while (!$this->ended) {
            $this->read(\PHP_INT_MAX);
        }
        $this->buffer = '';
        $this->closed = true;
    }

    public function detach()
    {
        $this->close();
        $this->detached = true;

        return null;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function tell(): int
    {
        return $this->readOffset;
    }

    public function eof(): bool
    {
        return '' === $this->buffer && $this->ended;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek($offset, $whence = \SEEK_SET): void
    {
        throw ExceptionTool::popTrace(new \RuntimeException('Stream is not seekable'));
    }

    public function rewind(): void
    {
        throw ExceptionTool::popTrace(new \RuntimeException('Stream is not seekable'));
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write($string): int
    {
        throw ExceptionTool::popTrace(new \RuntimeException('Stream is not writable'));
    }

    public function isReadable(): bool
    {
        return !$this->closed && !$this->detached;
    }

    public function read($length): string
    {
        if ($this->closed || $this->detached) {
            throw ExceptionTool::popTrace(new \RuntimeException('Stream is not valid'));
        }

        // If the buffer is empty we'll block until some data is available
        $timeout = \microtime(true) + $this->deadlockTimeout;
        while ('' === $this->buffer && !$this->ended) {
            \phasync::awaitFlag($this->writeFlag, $timeout - \microtime(true));
        }

        $chunk        = \substr($this->buffer, 0, $length);
        $chunkLength  = \strlen($chunk);
        $this->buffer = \substr($this->buffer, $chunkLength);
        $this->readOffset += $chunkLength;
        \phasync::raiseFlag($this->readFlag);

        return $chunk;
    }

    public function getContents(): string
    {
        $result = '';
        while (!$this->eof()) {
            $result .= $this->read(\PHP_INT_MAX);
        }

        return $result;
    }

    public function getMetadata($key = null)
    {
        $data = [
            'timed_out'    => false,
            'blocked'      => false,
            'unread_bytes' => \strlen($this->buffer),
            'stream_type'  => 'custom',
            'wrapper_type' => '',
            'wrapper_data' => null,
            'mode'         => 'r',
            'seekable'     => false,
            'uri'          => 'resource',
            'eof'          => $this->eof(),
        ];

        if (null !== $key) {
            return $data[$key] ?? null;
        }

        return $data;
    }

    /**
     * Adds `$chunk` to the end of the stream.
     *
     * Returns at once while no more than `$bufferSize` bytes are unread, and otherwise waits until the
     * reader has read enough.
     *
     * @throws \RuntimeException after `end()`
     * @throws TimeoutException  when the reader makes no progress for `$deadlockTimeout` seconds
     *
     * @see UnbufferedStream::end
     */
    public function append(string $chunk): void
    {
        if ($this->ended) {
            throw ExceptionTool::popTrace(new \RuntimeException("Can't append to the stream after ending it"));
        }

        $this->buffer .= $chunk;
        \phasync::raiseFlag($this->writeFlag);

        // If the buffer is longer than permitted, we must block until it's read
        $timeout = \microtime(true) + $this->deadlockTimeout;
        while (\strlen($this->buffer) > $this->bufferSize) {
            \phasync::awaitFlag($this->readFlag, $timeout - \microtime(true));
        }
    }

    /**
     * Declares that nothing more will be appended, which lets a reader reach the end of the stream.
     *
     * @throws \RuntimeException when called twice
     *
     * @see UnbufferedStream::append
     */
    public function end(): void
    {
        if ($this->ended) {
            throw ExceptionTool::popTrace(new \RuntimeException('Stream has already been ended'));
        }
        $this->ended = true;
        \phasync::raiseFlag($this->writeFlag);
    }
}
