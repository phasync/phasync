<?php

namespace phasync\Psr;

use Fiber;
use phasync\Internal\ExceptionTool;
use phasync\TimeoutException;
use Psr\Http\Message\StreamInterface;

/**
 * A seekable PSR-7 stream for a body that is still being produced, kept in memory and then in a temporary file.
 *
 * One coroutine appends with {@see BufferedStream::append()} and ends with {@see BufferedStream::end()}.
 * Content is kept in memory up to `$bufferSize` bytes, and then moved to a temporary file. Nothing
 * is discarded when it has been read, so memory or disk use grows with the content; use
 * {@see UnbufferedStream} for a body that is not needed after it was read. `getSize()` waits until
 * `end()`.
 *
 * ```php
 * $stream = new BufferedStream();
 *
 * phasync::go(function () use ($stream) {
 *     $stream->append('A chunk');
 *     $stream->end();
 * });
 *
 * $size = $stream->getSize();   // waits for end(): 7
 * ```
 *
 * @see UnbufferedStream
 * @see StringStream
 *
 * @topic streams-and-files
 *
 * @example examples/BufferedStream/1-read-while-it-grows.php Read while the content grows
 */
class BufferedStream implements StreamInterface
{
    /**
     * The string buffer
     */
    private string $buffer = '';

    /**
     * The file resource if the stream is file backed
     */
    private mixed $file = null;

    private int $readOffset  = 0;
    private int $writeOffset = 0;
    private ?int $endOffset  = null;
    private int $bufferSize;
    private float $deadlockTimeout;
    private bool $closed   = false;
    private bool $locked   = false;
    private bool $detached = false;

    /**
     * Creates an empty stream.
     *
     * @param int   $bufferSize      the content size in bytes above which it moves from memory to a temporary file
     * @param float $deadlockTimeout seconds that a read or `getSize()` waits for the writer before throwing a `TimeoutException`
     */
    public function __construct(int $bufferSize = 2 * 1024 * 1024, float $deadlockTimeout = 60)
    {
        $this->bufferSize      = $bufferSize;
        $this->deadlockTimeout = $deadlockTimeout;
    }

    /**
     * Returns the content from the start, or a message starting with "Stream Error" when that fails.
     */
    public function __toString(): string
    {
        try {
            $this->rewind();

            return $this->getContents();
        } catch (\Throwable $e) {
            return 'Stream Error: ' . (string) $e;
        }
    }

    /**
     * Waits for `end()`, then closes the stream.
     *
     * @throws TimeoutException when `end()` is not called within `$deadlockTimeout` seconds
     */
    public function close(): void
    {
        // Can't actually close this until the writer has ended
        $this->blockUntilEnded();
        if ($this->file) {
            \fclose($this->file);
        }
        $this->buffer = '';
        $this->file   = null;
        $this->closed = true;
    }

    /**
     * Waits for `end()`, then returns the content as a file resource and marks the stream detached.
     *
     * Reading a detached stream throws a `RuntimeException`.
     *
     * @throws TimeoutException when `end()` is not called within `$deadlockTimeout` seconds
     *
     * @return resource|null null when the stream was already detached
     */
    public function detach()
    {
        if ($this->detached) {
            return null;
        }

        // Can't actually detach until the writer has ended
        $this->blockUntilEnded();

        // Ensure there is a stream resource to detach
        if (null === $this->file) {
            $this->lock();
            try {
                $this->transitionToFile();
            } finally {
                $this->unlock();
            }
        }

        $result         = $this->file;
        $this->file     = null;
        $this->detached = true;

        return $result;
    }

    /**
     * Returns the size of the content, once `end()` has been called: it waits until then.
     *
     * @throws TimeoutException when `end()` is not called within `$deadlockTimeout` seconds
     *
     * @example examples/BufferedStream.getSize/1-waits-for-end.php The size is known at the end
     */
    public function getSize(): ?int
    {
        $this->blockUntilEnded();

        return $this->endOffset;
    }

    public function tell(): int
    {
        return $this->readOffset;
    }

    public function eof(): bool
    {
        // Can respond immediately if not at the eof
        return $this->readOffset === $this->endOffset;
    }

    public function isSeekable(): bool
    {
        return true;
    }

    public function seek($offset, $whence = \SEEK_SET): void
    {
        $this->lock();
        try {
            switch ($whence) {
                case \SEEK_CUR:
                    $this->readOffset = \max(0, \min($this->writeOffset, $this->readOffset + $offset));
                    break;
                case \SEEK_END:
                    $this->readOffset = \max(0, \min($this->writeOffset, $this->writeOffset + $offset));
                    break;
                default:
                case \SEEK_SET:
                    $this->readOffset = \max(0, \min($this->writeOffset, $offset));
                    break;
            }
        } finally {
            $this->unlock();
        }
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write($string): int
    {
        throw new \RuntimeException('Stream is not writable');
    }

    public function isReadable(): bool
    {
        return !$this->closed && !$this->detached;
    }

    /**
     * Returns up to `$length` bytes, waiting while the content is not yet appended and the stream has not ended.
     *
     * @throws TimeoutException  when nothing is appended and `end()` is not called within `$deadlockTimeout` seconds
     * @throws \RuntimeException when the stream is detached or closed
     */
    public function read($length): string
    {
        if ($this->detached) {
            throw new \RuntimeException('Stream is detached');
        }
        if ($this->closed) {
            throw new \RuntimeException('Stream is closed');
        }
        while ($this->readOffset === $this->writeOffset && null === $this->endOffset) {
            $this->waitForUpdate();
        }
        if ($this->eof()) {
            return '';
        }
        $this->lock();
        try {
            if (null === $this->file) {
                $chunk = \substr($this->buffer, $this->readOffset, $length);
                $this->readOffset += \strlen($chunk);

                return $chunk;
            }
            // Block Fiber until file is readable
            \phasync::readable($this->file);
            \fseek($this->file, $this->readOffset);
            $chunk = \fread($this->file, $length);
            if (false === $chunk) {
                throw new \RuntimeException('Read operation failed');
            }
            $this->readOffset += \strlen($chunk);

            return $chunk;
        } finally {
            $this->unlock();
        }
    }

    /**
     * Waits for `end()`, then returns the remaining content.
     *
     * @throws TimeoutException when `end()` is not called within `$deadlockTimeout` seconds
     */
    public function getContents(): string
    {
        $this->blockUntilEnded();
        $chunks = [];
        while (!$this->eof()) {
            $chunks[] = $this->read(65536);
        }

        return \implode('', $chunks);
    }

    public function getMetadata($key = null)
    {
        $this->lock();
        try {
            if ($this->file) {
                $data = \stream_get_meta_data($this->file);
            } else {
                $data = [
                    'timed_out'    => false,
                    'blocked'      => false,
                    'unread_bytes' => 0,
                    'stream_type'  => 'custom',
                    'wrapper_type' => '',
                    'wrapper_data' => null,
                    'mode'         => 'r',
                    'seekable'     => false,
                    'uri'          => 'resource',
                ];
            }
            $data['eof'] = $this->eof();
            if (null !== $key) {
                return $data[$key] ?? null;
            }

            return $data;
        } finally {
            $this->unlock();
        }
    }

    /**
     * Adds `$chunk` to the end of the stream, without waiting for a reader.
     *
     * The content moves from memory to a temporary file when it would pass `$bufferSize` bytes.
     *
     * @throws \LogicException after `end()`
     *
     * @see BufferedStream::end
     *
     * @example examples/BufferedStream.append/1-append-without-a-reader.php Append without waiting for a reader
     */
    public function append(string $chunk): void
    {
        $this->lock();
        try {
            if (null !== $this->endOffset) {
                throw new \LogicException("Stream can't be appended to after ending");
            }
            $length = \strlen($chunk);
            if ($this->writeOffset + $length > $this->bufferSize) {
                $this->transitionToFile();
            }

            if (null === $this->file) {
                $this->buffer .= $chunk;
            } else {
                // Block Fiber until file is writable
                \phasync::writable($this->file);
                \fseek($this->file, $this->writeOffset);
                \fwrite($this->file, $chunk);
            }
            $this->writeOffset += $length;
        } finally {
            $this->unlock();
        }
    }

    /**
     * Declares that nothing more will be appended, which fixes the size of the stream.
     *
     * @throws \LogicException when called twice
     *
     * @see BufferedStream::append
     */
    public function end(): void
    {
        $this->lock();
        try {
            if (null !== $this->endOffset) {
                throw new \LogicException('Stream was already ended');
            }
            $this->endOffset = $this->writeOffset;
        } finally {
            $this->locked = false;
            \phasync::raiseFlag($this);
        }
    }

    /**
     * Transition the in-memory buffer to a disk backed file. The caller holds the lock.
     */
    private function transitionToFile(): void
    {
        if (null !== $this->file) {
            return;
        }
        $this->file = \tmpfile();
        for ($i = 0; $i < $this->writeOffset;) {
            // Block Fiber until file is readable
            \phasync::writable($this->file);
            $written = \fwrite($this->file, \substr($this->buffer, $i, 32768));
            if (false === $written) {
                // Abort transition, failed writing so fall back to memory one more chunk
                $this->file = null;

                return;
            }
            $i += $written;
        }
        $this->buffer = '';
    }

    /**
     * Get a lock on the stream. This MUST be followed up by a call
     * to {@see self::unlock()}
     *
     * @throws TimeoutException
     * @throws \Throwable
     */
    private function lock(): void
    {
        while ($this->locked) {
            // Await notification that the lock is gone
            $this->waitForUpdate();
        }
        $this->locked = true;
    }

    /**
     * Release the lock taken with {@see self::lock()}, and wake those waiting for it or for content.
     */
    private function unlock(): void
    {
        $this->locked = false;
        \phasync::raiseFlag($this);
    }

    private function blockUntilEnded(): void
    {
        $timeout = \microtime(true) + $this->deadlockTimeout;
        while (null === $this->endOffset) {
            if ($timeout < \microtime(true)) {
                throw ExceptionTool::popTrace(new TimeoutException('Possible deadlock detected; timeout after ' . $this->deadlockTimeout . ' seconds'), __FILE__);
            }
            $this->waitForUpdate();
        }
    }

    /** Waits for an append, end() or the lock's release. */
    private function waitForUpdate(): void
    {
        try {
            \phasync::awaitFlag($this, $this->deadlockTimeout);
        } catch (TimeoutException) {
            throw ExceptionTool::popTrace(new TimeoutException('Possible deadlock detected, no update in ' . $this->deadlockTimeout . ' seconds'), __FILE__);
        }
    }
}
