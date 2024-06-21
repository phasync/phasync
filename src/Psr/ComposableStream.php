<?php

namespace phasync\Psr;

use Psr\Http\Message\StreamInterface;

class ComposableStream implements StreamInterface
{
    private bool $eof   = false;
    private int $offset = 0;
    private string $mode;
    private string $buffer = '';

    public function __construct(
        private ?\Closure $readFunction = null,
        private ?\Closure $writeFunction = null,
        private ?\Closure $seekFunction = null,
        private ?\Closure $getSizeFunction = null,
        private ?\Closure $closeFunction = null,
        private ?\Closure $eofFunction = null,
    ) {
        if (!$this->readFunction && !$this->writeFunction) {
            throw new \InvalidArgumentException('Either a readFunction or writeFunction must be provided');
        }
        if ($this->seekFunction) {
            if ($this->readFunction && $this->writeFunction) {
                $this->mode = 'r+';
            } elseif ($this->readFunction) {
                $this->mode = 'r';
            } else {
                $this->mode = 'w';
            }
        } else {
            if ($this->readFunction && $this->writeFunction) {
                $this->mode = 'r+';
            } elseif ($this->readFunction) {
                $this->mode = 'r';
            } else {
                $this->mode = 'a';
            }
        }
    }

    public function __toString(): string
    {
        $result = '';
        if ($this->readFunction) {
            try {
                $this->rewind();
            } catch (\RuntimeException) {
            }
            while (!$this->eof()) {
                $result .= $this->read(\PHP_INT_MAX);
            }
        }

        return $result;
    }

    public function close(): void
    {
        if ($this->closeFunction) {
            ($this->closeFunction)();
        }
        $this->detach();
    }

    public function detach()
    {
        $this->readFunction    = null;
        $this->writeFunction   = null;
        $this->getSizeFunction = null;
        $this->closeFunction   = null;
        $this->seekFunction    = null;
        $this->buffer          = '';

        return null;
    }

    public function getSize(): ?int
    {
        if ($this->getSizeFunction) {
            return ($this->getSizeFunction)();
        }

        return null;
    }

    public function tell(): int
    {
        return $this->offset;
    }

    public function eof(): bool
    {
        if (null !== $this->eofFunction) {
            return ($this->eofFunction)();
        }

        return $this->eof && '' === $this->buffer;
    }

    public function isSeekable(): bool
    {
        return null !== $this->seekFunction;
    }

    public function seek(int $offset, int $whence = \SEEK_SET): void
    {
        if (null === $this->seekFunction || \SEEK_SET !== $whence) {
            throw new \RuntimeException('Stream is not seekable or whence != SEEK_SET');
        }
        ($this->seekFunction)($offset, $whence);
        $this->offset = $offset;
        $this->buffer = ''; // Clear the buffer on seek
        $this->eof    = false; // Reset EOF on seek
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function isWritable(): bool
    {
        return null !== $this->writeFunction;
    }

    public function write(string $string): int
    {
        if (null === $this->writeFunction) {
            throw new \RuntimeException('Stream is not writable');
        }
        $result = ($this->writeFunction)($string);
        if (!\is_int($result)) {
            $result = \strlen($string);
        }
        $this->offset += $result;

        return $result;
    }

    public function isReadable(): bool
    {
        return null !== $this->readFunction;
    }

    public function read(int $length): string
    {
        if (null === $this->readFunction) {
            throw new \RuntimeException('Stream is not readable');
        }
        if ($this->eof) {
            return '';
        }

        // Use buffered data if available
        $result = '';
        if ('' !== $this->buffer) {
            $result       = \substr($this->buffer, 0, $length);
            $this->buffer = \substr($this->buffer, $length);
            $length -= \strlen($result);
        }

        // If more data is needed, call the read function
        if ($length > 0) {
            $chunk = ($this->readFunction)($length);
            if (!\is_string($chunk) || '' === $chunk) {
                $this->eof = true;
            } else {
                $result .= \substr($chunk, 0, $length);
                $this->buffer .= \substr($chunk, $length);
            }
        }

        $this->offset += \strlen($result);

        return $result;
    }

    public function getContents(): string
    {
        $data = '';
        while (!$this->eof()) {
            $data .= $this->read(\PHP_INT_MAX);
        }

        return $data;
    }

    public function getMetadata(?string $key = null)
    {
        $data = [
            'timed_out'    => false,
            'blocked'      => false,
            'eof'          => $this->eof(),
            'unread_bytes' => 0,
            'stream_type'  => 'custom',
            'wrapper_type' => '',
            'wrapper_data' => null,
            'mode'         => $this->mode,
            'seekable'     => $this->isSeekable(),
            'uri'          => 'resource',
        ];
        if (null !== $key) {
            return $data[$key] ?? null;
        }

        return $data;
    }
}
