<?php
namespace phasync\Psr;

use LogicException;
use phasync\Legacy\Loop;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * This StreamInterface implementation uses an in memory string up to
 * 2 MB of size. If the size is exceeded, the data will be written
 * to disk.
 * 
 * The StreamInterface has an {@see BufferedStream::append()} method
 * to facilitate writing more data to the stream, and an
 * {@see BufferedStream::end()} to signal that no more data will be
 * added. 
 * 
 * @package phasync\Psr
 */
class BufferedStream implements StreamInterface {

    /**
     * The string buffer
     * 
     * @var string
     */
    private string $buffer = '';

    /**
     * The file resource if the stream is file backed
     * 
     * @var mixed
     */
    private mixed $file = null;

    private int $readOffset = 0;
    private int $writeOffset = 0;
    private int $bufferSize;
    private bool $closed = false;
    private bool $ended = false;
    private bool $locked = false;

    public function __construct(int $bufferSize = 2*1024*1024) {
        $this->bufferSize = $bufferSize;
    }

    public function __toString(): string { 
        if ($this->file === null) {
            return $this->buffer;
        } else {
            $result = '';
            for ($i = 0; $i < $this->writeOffset;) {
                // Block Fiber until file is readable
                Loop::readable($this->file);
                \fseek($this->file, $i);
                $chunk = \fread($this->file, 32768);
                if ($chunk === false) {
                    return $result;
                }
                $i += \strlen($chunk);
                $result .= $chunk;
            }
            return $result;
        }
        
    }

    public function close(): void {
        $this->buffer = '';
        $this->file = null;
        $this->closed = true;
    }

    public function detach() {
        if ($this->file !== null) {
            $result = $this->file;
            $this->close();
            return $result;
        }
        $this->close();
    }

    public function getSize(): ?int {
        if ($this->ended) {
            return $this->writeOffset;
        }
        return null;
    }

    public function tell(): int {
        return $this->readOffset;
    }

    public function eof(): bool {
        if ($this->ended) {
            return $this->readOffset === $this->writeOffset;
        }
        return false;
    }

    public function isSeekable(): bool {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void {
        throw new RuntimeException("Stream is not seekable");
    }

    public function rewind(): void {
        throw new RuntimeException("Stream is not seekable");
    }

    public function isWritable(): bool {
        return false;
    }

    public function write(string $string): int {
        throw new RuntimeException("Stream is not writable");
    }

    public function isReadable(): bool {
        return !$this->closed;
    }

    public function read(int $length): string {
        if ($this->closed) {
            throw new RuntimeException("Stream is closed");
        }
        while (!$this->ended && $this->readOffset === $this->writeOffset) {
            Loop::awaitFlag($this);
        }
        if ($this->file === null) {
            $chunk = \substr($this->buffer, $this->readOffset, $length);
            $this->readOffset += \strlen($chunk);
            return $chunk;
        } else {
            // Block Fiber until file is readable
            Loop::readable($this->file);
            \fseek($this->file, $this->readOffset);
            $chunk = \fread($this->file, $length);
            if ($chunk === false) {
                throw new RuntimeException("Read operation failed");
            }
            $this->readOffset += \strlen($chunk);
            return $chunk;
        }
    }

    public function getContents(): string {
        if ($this->file === null) {
            $chunk = \substr($this->buffer, $this->readOffset);
            $this->readOffset += \strlen($chunk);
            return $chunk;
        }
        $result = '';
        while ($this->readOffset < $this->writeOffset) {
            // Block Fiber until file is readable
            Loop::readable($this->file);
            \fseek($this->file, $this->readOffset);
            $chunk = \fread($this->file, 32768);
            if ($chunk === false) {
                throw new RuntimeException("Read operation failed");
            }
            $this->readOffset += \strlen($chunk);
            $result .= $chunk;
        }
        return $result;
    }

    public function getMetadata(?string $key = null) {
        $data = [
            'timed_out' => false,
            'blocked' => false,
            'eof' => $this->ended && $this->readOffset === $this->writeOffset,
            'unread_bytes' => 0,
            'stream_type' => 'custom',
            'wrapper_type' => '',
            'wrapper_data' => null,
            'mode' => "r",
            'seekable' => false,
            'uri' => 'resource',
        ];
        if ($key !== null) {
            return $data[$key] ?? null;
        }
        return $data;
    }

    public function append(string $chunk): void {        
        if ($this->ended) {
            throw new RuntimeException("Stream can't be appended to after ending");
        }
        $length = \strlen($chunk);
        if ($this->writeOffset + $length > $this->bufferSize) {
            $this->transitionToFile();
        }

        if ($this->file === null) {
            $this->buffer .= $chunk;
        } else {
            // Block Fiber until file is writable
            Loop::writable($this->file);
            \fseek($this->file, $this->writeOffset);
            \fwrite($this->file, $chunk);
        }
        $this->writeOffset += $length;
        Loop::raiseFlag($this);
    }

    public function end(): void {
        if ($this->ended) {
            throw new LogicException("Stream was already ended");
        }
        $this->ended = true;
        Loop::raiseFlag($this);
    }

    private function transitionToFile(): void {
        $this->lock();
        if ($this->file !== null) {
            $this->unlock();
            return;
        }
        $this->file = \tmpfile();
        for ($i = 0; $i < $this->writeOffset;) {
            // Block Fiber until file is readable
            Loop::writable($this->file);
            $written = \fwrite($this->file, \substr($this->buffer, $i, 32768));
            if ($written === false) {
                // Abort transition, failed writing so fall back to memory one more chunk
                $this->file = null;
                $this->unlock();
                return;
            }
            $i += $written;
        }
        $this->buffer = '';
        $this->unlock();
    }

    private function lock(): void {
        while ($this->locked) {
            // Await notification that the lock is gone
            Loop::awaitFlag($this);
        }
        $this->locked = true;
    }

    private function unlock(): void {
        if (!$this->locked) {
            throw new LogicException("Stream is not locked");
        }
        $this->locked = false;
        // Notify that the lock is gone
        Loop::raiseFlag($this);
    }
}