<?php

namespace phasync\Psr;

use Psr\Http\Message\StreamInterface;

/**
 * This StreamInterface implementation allows you to implement
 * functions such as a readFunction, writeFunction, seekFunction
 * and provide them via the constructor to generate a compliant
 * StreamInterface stream.
 */
class ComposableStream implements StreamInterface
{
    private bool $eof   = false;
    private int $offset = 0;
    private string $mode;

    public function __construct(
        private ?\Closure $readFunction = null,
        private ?\Closure $writeFunction = null,
        private ?\Closure $seekFunction = null,
        private ?\Closure $getSizeFunction = null,
        private ?\Closure $closeFunction = null,
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
        return $this->eof;
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
            $result = \mb_strlen($string);
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
            echo "Read at eof\n";

            return '';
        }
        $chunk = ($this->readFunction)($length);
        if (!\is_string($chunk)) {
            $this->eof = true;

            return '';
        }
        $readLength = \mb_strlen($chunk);
        if ($readLength > $length) {
            throw new \RuntimeException("The stream readFunction returned a chunk that was longer than the expected length ($length bytes).");
        }
        $this->offset += $readLength;

        return $chunk;
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
            'eof'          => $this->eof,
            'unread_bytes' => 0,
            'stream_type'  => 'custom',
            'wrapper_type' => '',
            'wrapper_data' => null,
            'mode'         => $this->mode,
            'seekable'     => $this->seekFunction ? true : false,
            'uri'          => 'resource',
        ];
        if (null !== $key) {
            return $data[$key] ?? null;
        }

        return $data;
    }
}
