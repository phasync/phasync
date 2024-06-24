<?php

namespace phasync\Psr;

use phasync;
use phasync\Internal\ExceptionTool;
use phasync\TimeoutException;
use Psr\Http\Message\StreamInterface;

/**
 * Unbuffered streaming PSR-7 StreamInterface
 *
 * Designed for returning a response which has not been completed yet. A
 * coroutine can continue appending to the stream.
 *
 * Usage:
 *
 * ```php
 * $s = new UnbufferedStream();
 *
 * // Create a coroutine which appends to the stream
 * phasync::go(function() use ($s) {
 *     // Append chunks as much as you need
 *     $s->append("A chunk");
 *
 *     // Signal that the stream is complete
 *     $s->end();
 * });
 *
 * // Return the stream
 * return $response->withStream($s);
 * ```
 *
 * By default the stream will buffer up to 64 KB of data, to improve
 * performance and reduce context switching.
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

    public function __construct(int $bufferSize = 64 * 1024, float $deadlockTimeout = 60)
    {
        $this->bufferSize      = $bufferSize;
        $this->deadlockTimeout = $deadlockTimeout;
        $this->creator         = \WeakReference::create(\phasync::getFiber());
        $this->readFlag        = new \stdClass();
        $this->writeFlag       = new \stdClass();
    }

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

    public function seek(int $offset, int $whence = \SEEK_SET): void
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

    public function write(string $string): int
    {
        throw ExceptionTool::popTrace(new \RuntimeException('Stream is not writable'));
    }

    public function isReadable(): bool
    {
        return !$this->closed && !$this->detached;
    }

    public function read(int $length): string
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

    public function getMetadata(?string $key = null)
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
     * Append more data to the stream
     *
     * @throws \RuntimeException
     * @throws TimeoutException
     * @throws \Throwable
     * @throws \LogicException
     * @throws \FiberError
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
     * Inform that no more content will be appended to the stream,
     * effectively declaring the end-of-file position.
     *
     * @throws \LogicException
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
