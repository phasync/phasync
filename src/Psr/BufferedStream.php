<?php

namespace phasync\Psr;

use Fiber;
use phasync\TimeoutException;
use Psr\Http\Message\StreamInterface;

/**
 * This StreamInterface implementation uses an in memory string up to
 * 2 MB of size. If the size is exceeded, the data will be written
 * to disk.
 *
 * The StreamInterface has an {@see BufferedStream::append()} method
 * to facilitate writing more data to the stream, and an
 * {@see BufferedStream::end()} to signal that no more data will be
 * added.
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
    private \WeakReference $creator;

    public function __construct(int $bufferSize = 2 * 1024 * 1024, float $deadlockTimeout = 60)
    {
        $this->bufferSize      = $bufferSize;
        $this->deadlockTimeout = $deadlockTimeout;
        $this->creator         = \WeakReference::create(\phasync::getFiber());
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
        $this->rewind();

        return $this->getContents();
    }

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

    public function detach()
    {
        // Can't actually detach until the writer has ended
        $this->blockUntilEnded();

        // Ensure there is a stream resource to detach
        if (null === $this->file) {
            $this->transitionToFile();
        }

        $result       = $this->file;
        $this->file   = null;
        $this->closed = true;

        return $result;
    }

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

    public function seek(int $offset, int $whence = \SEEK_SET): void
    {
        $this->assertNotCreator();
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

    public function write(string $string): int
    {
        throw new \RuntimeException('Stream is not writable');
    }

    public function isReadable(): bool
    {
        return !$this->closed && !$this->detached;
    }

    public function read(int $length): string
    {
        $this->assertNotCreator();
        if ($this->detached) {
            throw new \RuntimeException('Stream is detached');
        }
        if ($this->closed) {
            throw new \RuntimeException('Stream is closed');
        }
        if ($this->eof()) {
            return '';
        }
        if ($this->readOffset === $this->writeOffset) {
            $this->waitForUpdate();
        }
        $this->lock();
        try {
            if (null === $this->file) {
                $chunk = \mb_substr($this->buffer, $this->readOffset, $length);
                $this->readOffset += \mb_strlen($chunk);

                return $chunk;
            }
            // Block Fiber until file is readable
            \phasync::readable($this->file);
            \fseek($this->file, $this->readOffset);
            $chunk = \fread($this->file, $length);
            if (false === $chunk) {
                throw new \RuntimeException('Read operation failed');
            }
            $this->readOffset += \mb_strlen($chunk);

            return $chunk;
        } finally {
            $this->unlock();
        }
    }

    public function getContents(): string
    {
        $this->lock();
        try {
            $this->blockUntilEnded();

            if (null === $this->file) {
                $chunk            = \mb_substr($this->buffer, $this->readOffset);
                $this->readOffset = $this->writeOffset;

                return $chunk;
            }

            $chunks = [];
            while (!$this->eof()) {
                $chunks[] = $this->read(65536);
            }

            return \implode('', $chunks);
        } finally {
            $this->unlock();
        }
    }

    public function getMetadata(?string $key = null)
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
        $this->lock();
        try {
            if (null !== $this->endOffset) {
                throw new \RuntimeException("Stream can't be appended to after ending");
            }
            $length = \mb_strlen($chunk);
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
            $this->notifyUpdate();
        } finally {
            $this->unlock();
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
     * Transition the in-memory buffer to a disk backed file.
     *
     * @throws TimeoutException
     * @throws \Throwable
     * @throws \LogicException
     * @throws \FiberError
     */
    private function transitionToFile(): void
    {
        $this->lock();
        try {
            if (null !== $this->file) {
                return;
            }
            $this->file = \tmpfile();
            for ($i = 0; $i < $this->writeOffset;) {
                // Block Fiber until file is readable
                \phasync::writable($this->file);
                $written = \fwrite($this->file, \mb_substr($this->buffer, $i, 32768));
                if (false === $written) {
                    // Abort transition, failed writing so fall back to memory one more chunk
                    $this->file = null;

                    return;
                }
                $i += $written;
            }
            $this->buffer = '';
        } finally {
            $this->unlock();
        }
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
     * Release the lock on the stream. This should only be called if
     * {@see self::lock()} was called first.
     *
     * @throws \LogicException
     */
    private function unlock(): void
    {
        if (!$this->locked) {
            throw new \LogicException('Stream is not locked. Only call unlock if you successfully called lock!');
        }
        $this->locked = false;
        // Notify that the lock is gone
        $this->notifyUpdate();
    }

    private function blockUntilEnded(): void
    {
        $timeout = \microtime(true) + $this->deadlockTimeout;
        $this->assertNotCreator();
        while (null === $this->endOffset) {
            if ($timeout < \microtime(true)) {
                throw new TimeoutException('Possible deadlock detected; timeout after 300 seconds');
            }
            $this->waitForUpdate();
        }
    }

    private function notifyUpdate(): void
    {
        if (null !== $this->endOffset) {
            throw new \LogicException('No updates will occur in an ended stream');
        }
        \phasync::raiseFlag($this);
    }

    private function waitForUpdate(): void
    {
        if (null === $this->endOffset) {
            try {
                \phasync::awaitFlag($this, $this->deadlockTimeout);
            } catch (TimeoutException) {
                throw new \RuntimeException('Possible deadlock detected, no update in ' . $this->deadlockTimeout . ' seconds');
            }
        } else {
            throw new \LogicException('No point waiting in an ended stream');
        }
    }

    private function assertNotCreator(): void
    {
        if (null !== $this->endOffset) {
            return;
        }
        if ($this->creator->get() === \phasync::getFiber()) {
            throw new \RuntimeException("Can't use BufferedStream in the coroutine that created it");
        }
    }
}
