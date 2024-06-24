<?php

namespace phasync\Psr;

use Psr\Http\Message\StreamInterface;

/**
 * A PSR-7 StreamInterface containing a constant string.
 */
class StringStream implements StreamInterface
{
    private int $readOffset = 0;
    private bool $closed    = false;
    private bool $detached  = false;
    private string $buffer;

    public function __construct(string $contents)
    {
        $this->buffer = $contents;
    }

    public function __toString(): string
    {
        $this->readOffset = \strlen($this->buffer);

        return $this->buffer;
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function detach()
    {
        $this->closed   = true;
        $this->detached = true;
    }

    public function getSize(): ?int
    {
        return \strlen($this->buffer);
    }

    public function tell(): int
    {
        return $this->readOffset;
    }

    public function eof(): bool
    {
        return $this->readOffset === \strlen($this->buffer);
    }

    public function isSeekable(): bool
    {
        return true;
    }

    public function seek(int $offset, int $whence = \SEEK_SET): void
    {
        switch ($whence) {
            case \SEEK_SET:
                $this->readOffset = \max(0, \min(\strlen($this->buffer), $offset));
                break;
            case \SEEK_CUR:
                $this->seek($this->readOffset + $offset);
                break;
            case \SEEK_END:
                $this->seek(\strlen($this->buffer) + $offset);
                break;
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
        return !$this->closed;
    }

    public function read(int $length): string
    {
        if ($this->closed) {
            throw new \RuntimeException('Stream is closed or detached');
        }
        $chunk = \substr($this->buffer, $this->readOffset, $length);
        $this->readOffset += \strlen($chunk);

        return $chunk;
    }

    public function getContents(): string
    {
        return $this->read(\PHP_INT_MAX);
    }

    public function getMetadata(?string $key = null)
    {
        $data = [
            'timed_out'    => false,
            'blocked'      => false,
            'unread_bytes' => 0,
            'stream_type'  => 'custom',
            'wrapper_type' => '',
            'wrapper_data' => null,
            'mode'         => 'r',
            'seekable'     => true,
            'uri'          => 'resource',
            'eof'          => $this->eof(),
        ];
        if (null !== $key) {
            return $data[$key] ?? null;
        }

        return $data;
    }
}
