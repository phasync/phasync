<?php

namespace phasync\Psr;

use phasync\Legacy\Loop;
use Psr\Http\Message\StreamInterface;

/**
 * Represents a file backed temporary buffer that can be accessed
 * asynchronously.
 */
final class TempFileStream implements StreamInterface
{
    /**
     * True if the stream resource has been closed.
     */
    protected bool $closed = false;

    /**
     * True if the stream resource has been detached.
     */
    protected bool $detached = false;

    /**
     * The in-memory buffer, used if {@see TempStream::$stream} is
     * null.
     *
     * @var string|null
     */
    private string $buffer = '';

    /**
     * The StreamInterface instance used when the memory buffer grows
     * too large.
     */
    private ?StreamInterface $stream = null;

    /**
     * The maximum length of the {@see FileBuffer::$buffer} string.
     *
     * @todo For future implementation
     */
    private int $maxBufferSize = 2 * 1024 * 1024;

    /**
     * The virtual file offset.
     */
    private int $offset = 0;

    /**
     * The simulated file mode. {@see \fopen()}
     */
    private string $mode;

    public static function fromString(string $contents): TempFileStream
    {
        $stream = new TempFileStream();
        $stream->write($contents);
        $stream->rewind();

        return $stream;
    }

    public function __construct(string $mode = 'r+')
    {
        if ('' !== \trim($mode, 'r+waxce')) {
            throw new \RuntimeException("Invalid mode string '$mode'");
        }
        $this->mode = $mode;
    }

    /**
     * Reads all data from the stream into a string, from the beginning to end.
     *
     * This method MUST attempt to seek to the beginning of the stream before
     * reading data and read the stream until the end is reached.
     *
     * Warning: This could attempt to load a large amount of data into memory.
     *
     * This method MUST NOT raise an exception in order to conform with PHP's
     * string casting operations.
     *
     * @see http://php.net/manual/en/language.oop5.magic.php#object.tostring
     */
    public function __toString(): string
    {
        if (null !== $this->stream) {
            return $this->stream->__toString();
        }
        if ($this->closed || $this->detached) {
            return '';
        }
        $this->offset = \strlen($this->buffer);

        return $this->buffer;
    }

    /**
     * Closes the stream and any underlying resources.
     */
    public function close(): void
    {
        if (null !== $this->stream) {
            return $this->stream->close();
        }
        if ($this->closed || $this->detached) {
            return;
        }
        $this->closed = true;
        $this->buffer = '';
    }

    /**
     * Separates any underlying resources from the stream.
     *
     * After the stream has been detached, the stream is in an unusable state.
     *
     * @return resource|null Underlying PHP stream, if any
     */
    public function detach()
    {
        if (null !== $this->stream) {
            return $this->stream->detach();
        }
        $this->closed   = true;
        $this->detached = true;
        $this->offset   = 0;
        $this->buffer   = '';

        return null;
    }

    /**
     * Get the size of the stream if known.
     *
     * @return int|null returns the size in bytes if known, or null if unknown
     */
    public function getSize(): ?int
    {
        if (null !== $this->stream) {
            return $this->stream->getSize();
        }
        if ($this->closed || $this->detached) {
            return null;
        }

        return \strlen($this->buffer);
    }

    /**
     * Returns the current position of the file read/write pointer
     *
     * @throws \RuntimeException on error
     *
     * @return int Position of the file pointer
     */
    public function tell(): int
    {
        if (null !== $this->stream) {
            return $this->stream->tell();
        }
        if ($this->closed || $this->detached) {
            throw new \RuntimeException('Stream is closed or detached');
        }

        return $this->offset;
    }

    /**
     * Returns true if the stream is at the end of the stream.
     */
    public function eof(): bool
    {
        if (null !== $this->stream) {
            return $this->stream->eof();
        }

        return $this->offset >= \strlen($this->buffer);
    }

    /**
     * Returns whether or not the stream is seekable.
     */
    public function isSeekable(): bool
    {
        if (null !== $this->stream) {
            return $this->stream->isSeekable();
        }
        if ($this->closed || $this->detached) {
            return false;
        }

        return \str_contains($this->mode, '+') || !\str_contains($this->mode, 'a');
    }

    /**
     * Seek to a position in the stream.
     *
     * @see http://www.php.net/manual/en/function.fseek.php
     *
     * @param int $offset Stream offset
     * @param int $whence Specifies how the cursor position will be calculated
     *                    based on the seek offset. Valid values are identical to the built-in
     *                    PHP $whence values for `fseek()`.  SEEK_SET: Set position equal to
     *                    offset bytes SEEK_CUR: Set position to current location plus offset
     *                    SEEK_END: Set position to end-of-stream plus offset.
     *
     * @throws \RuntimeException on failure
     */
    public function seek(int $offset, int $whence = \SEEK_SET): void
    {
        if (null !== $this->stream) {
            return $this->stream->seek($offset, $whence);
        }
        if ($this->closed || $this->detached) {
            throw new \RuntimeException('Stream is closed or detached');
        }
        if (\SEEK_SET === $whence) {
            $this->offset = $offset;
        } elseif (\SEEK_CUR === $whence) {
            $this->offset += $offset;
        } elseif (\SEEK_END === $whence) {
            $this->offset = \strlen($this->buffer) + $offset;
        } else {
            throw new \RuntimeException('Invalid $whence value');
        }
        if ($this->offset < 0) {
            $this->offset = 0;
        }
    }

    /**
     * Seek to the beginning of the stream.
     *
     * If the stream is not seekable, this method will raise an exception;
     * otherwise, it will perform a seek(0).
     *
     * @see seek()
     * @see http://www.php.net/manual/en/function.fseek.php
     *
     * @throws \RuntimeException on failure
     */
    public function rewind(): void
    {
        if (null !== $this->stream) {
            $this->stream->rewind();

            return;
        }
        if ($this->closed || $this->detached) {
            throw new \RuntimeException('Stream is closed or detached');
        }
        if (!$this->isSeekable()) {
            throw new \RuntimeException('Stream is not seekable');
        }
        $this->offset = 0;
    }

    /**
     * Returns whether or not the stream is writable.
     */
    public function isWritable(): bool
    {
        if (null !== $this->stream) {
            return $this->stream->isWritable();
        }
        if ($this->closed || $this->detached) {
            return false;
        }

        return \str_contains($this->mode, '+') || \str_contains($this->mode, 'x') || \str_contains($this->mode, 'w') || \str_contains($this->mode, 'a') || \str_contains($this->mode, 'c');
    }

    /**
     * Write data to the stream.
     *
     * @param string $string the string that is to be written
     *
     * @throws \RuntimeException on failure
     *
     * @return int returns the number of bytes written to the stream
     */
    public function write(string $string): int
    {
        if (null !== $this->stream) {
            return $this->stream->write($string);
        }
        if ($this->closed || $this->detached) {
            throw new \RuntimeException('Stream is closed or detached');
        }
        if (!$this->isWritable()) {
            throw new \RuntimeException('Stream is not writable');
        }

        $bytesToWrite = \strlen($string);
        $bufferLength = \strlen($this->buffer);
        if ($this->offset + $bytesToWrite > $this->maxBufferSize) {
            // Transition to using a stream resource
            $fp = \tmpfile();
            \stream_set_blocking($fp, false);
            $offset       = 0;
            $bufferLength = \strlen($this->buffer);
            $chunkSize    = 32768;
            for ($offset = 0; $offset < $bufferLength;) {
                $chunk = \substr($this->buffer, $offset, $chunkSize);
                Loop::writable($fp);
                $written = \fwrite($fp, $chunk);
                if (false === $written) {
                    \fclose($fp);
                    $this->detach();
                    throw new \RuntimeException('Failed writing to file system');
                }
                $offset += $written;
            }
            $this->stream = new ResourceStream($fp, mode: $this->mode);
            $this->stream->seek($this->offset);
            $this->buffer = '';
            $this->offset = 0;

            return $this->stream->write($string);
        }

        if ($this->offset > $bufferLength) {
            // need to fill with spaces
            $spacesToFill = $this->offset - $bufferLength;
            $this->buffer .= \str_repeat(' ', $spacesToFill) . $string;
            $this->offset = \strlen($this->buffer);

            return \strlen($string);
        }

        $this->buffer = \substr($this->buffer, 0, $this->offset) . $string . \substr($this->buffer, $this->offset + \strlen($string));

        return \strlen($string);
    }

    /**
     * Returns whether or not the stream is readable.
     */
    public function isReadable(): bool
    {
        if ($this->closed || $this->detached) {
            return false;
        }

        return \str_contains($this->mode, '+') || \str_contains($this->mode, 'r');
    }

    /**
     * Read data from the stream.
     *
     * @param int $length Read up to $length bytes from the object and return
     *                    them. Fewer than $length bytes may be returned if underlying stream
     *                    call returns fewer bytes.
     *
     * @throws \RuntimeException if an error occurs
     *
     * @return string returns the data read from the stream, or an empty string
     *                if no bytes are available
     */
    public function read(int $length): string
    {
        if (null !== $this->stream) {
            return $this->stream->read($length);
        }
        if ($this->closed || $this->detached) {
            throw new \RuntimeException('Stream is closed or detached');
        }
        if ($length < 0) {
            throw new \RuntimeException("Can't read a negative amount");
        }
        if (!$this->isReadable()) {
            throw new \RuntimeException('Stream is not readable');
        }
        $result = \substr($this->buffer, $this->offset, $length);
        $this->offset += \strlen($result);

        return $result;
    }

    /**
     * Returns the remaining contents in a string
     *
     * @throws \RuntimeException if unable to read or an error occurs while
     *                           reading
     */
    public function getContents(): string
    {
        if (null !== $this->stream) {
            return $this->stream->getContents();
        }
        if ($this->closed || $this->detached) {
            throw new \RuntimeException('Stream is closed or detached');
        }

        return \substr($this->buffer, $this->offset);
    }

    /**
     * Get stream metadata as an associative array or retrieve a specific key.
     *
     * The keys returned are identical to the keys returned from PHP's
     * stream_get_meta_data() function.
     *
     * @see http://php.net/manual/en/function.stream-get-meta-data.php
     *
     * @param string|null $key specific metadata to retrieve
     *
     * @return array|mixed|null Returns an associative array if no key is
     *                          provided. Returns a specific key value if a key is provided and the
     *                          value is found, or null if the key is not found.
     */
    public function getMetadata(?string $key = null)
    {
        if (null !== $this->stream) {
            return $this->stream->getMetadata($key);
        }
        if ($this->closed || $this->detached) {
            return null;
        }
        if ('mode' === $key) {
            return $this->mode;
        }
        $meta = [
            'timed_out'    => false,
            'blocked'      => false,
            'eof'          => $this->offset >= \strlen($this->buffer),
            'unread_bytes' => 0,
            'stream_type'  => 'string',
            'wrapper_type' => 'string',
            'wrapper_data' => null,
            'mode'         => $this->mode,
            'seekable'     => $this->isSeekable(),
            'uri'          => 'php://temp',
        ];
        if (null !== $key) {
            return $meta[$key] ?? null;
        }

        return $meta;
    }
}
