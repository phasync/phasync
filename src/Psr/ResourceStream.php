<?php

namespace phasync\Psr;

use InvalidArgumentException;
use phasync;
use phasync\Legacy\Loop;
use phasync\UsageError;
use Psr\Http\Message\StreamInterface;

/**
 * A PSR-7 StreamInterface which maps directly to a PHP stream resource.
 * This implementation integrates with phasync for read and write 
 * operations
 *
 */
final class ResourceStream implements StreamInterface
{
    /**
     * True if the stream resource has been closed.
     */
    protected bool $closed = true;

    /**
     * True if the stream resource has been detached.
     */
    protected bool $detached = true;

    /**
     * The stream resource or null if detached or closed.
     *
     * @var resource|null
     */
    protected mixed $resource = null;

    /**
     * Used to override the apparent mode of this stream resource.
     */
    private ?string $mode = null;

    /**
     * Construct a PSR compliant stream resource which integrates
     * with phasync for asynchronous IO. If created using a
     * StreamInterface, the original StreamInterface object will
     * be detached.
     *
     * @param resource|StreamInterface $resource
     * @param ?string                  $mode     If set will override the actual mode
     *
     * @throws UsageError
     *
     * @return void
     */
    public function __construct(mixed $resource, ?string $mode=null)
    {
        $this->setResource($resource);
        if (null !== $mode) {
            $this->mode = $mode;
        }
    }

    /**
     * Reinitialize the stream resource.
     *
     * @throws InvalidArgumentException
     */
    protected function setResource(mixed $resource): void
    {
        if ($resource instanceof StreamInterface) {
            $resource = $resource->detach();
        }
        if (!\is_resource($resource) || 'stream' !== \get_resource_type($resource)) {
            throw new InvalidArgumentException('Not a stream resource');
        }
        $this->closed   = false;
        $this->detached = false;
        $this->resource = $resource;
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
        if ($this->closed || $this->detached || 0 === $this->getSize()) {
            return '';
        }
        $this->seek(0);
        $chunks = [];
        try {
            while (!\feof($this->resource)) {
                $chunk = $this->read(32768);
                if ('' === $chunk) {
                    phasync::yield();
                } else {
                    $chunks[] = $chunk;
                }
            }

            return \implode('', $chunks);
        } catch (\RuntimeException) {
            return '';
        }
    }

    /**
     * Closes the stream and any underlying resources.
     */
    public function close(): void
    {
        if ($this->closed || $this->detached) {
            return;
        }
        $this->closed = true;
        \fclose($this->resource);
        $this->resource = null;
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
        if ($this->closed || $this->detached) {
            return null;
        }
        $this->detached = true;
        $resource       = $this->resource;
        $this->resource = null;

        return $resource;
    }

    /**
     * Get the size of the stream if known.
     *
     * @return int|null returns the size in bytes if known, or null if unknown
     */
    public function getSize(): ?int
    {
        if ($this->closed || $this->detached) {
            return null;
        }
        $stat = \fstat($this->resource);

        return (int) $stat['size'];
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
        if ($this->closed || $this->detached) {
            throw new \RuntimeException('Stream is closed or detached');
        }

        return \ftell($this->resource);
    }

    /**
     * Returns true if the stream is at the end of the stream.
     */
    public function eof(): bool
    {
        if ($this->closed || $this->detached) {
            return true;
        }

        return \feof($this->resource);
    }

    /**
     * Returns whether or not the stream is seekable.
     */
    public function isSeekable(): bool
    {
        if ($this->closed || $this->detached) {
            return false;
        }

        return \stream_get_meta_data($this->resource)['seekable'] ?? false;
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
        if ($this->closed || $this->detached) {
            throw new \RuntimeException('Stream is closed or detached');
        }
        $result = \fseek($this->resource, $offset, $whence);
        if (0 !== $result) {
            throw new \RuntimeException('Unable to seek');
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
        if ($this->closed || $this->detached) {
            throw new \RuntimeException('Stream is closed or detached');
        }
        if (!$this->isSeekable()) {
            throw new \RuntimeException('Stream is not seekable');
        }
        \rewind($this->resource);
    }

    /**
     * Returns whether or not the stream is writable.
     */
    public function isWritable(): bool
    {
        if ($this->closed || $this->detached) {
            return false;
        }
        $mode = $this->mode ?? \stream_get_meta_data($this->resource)['mode'] ?? null;

        return null !== $mode && (\str_contains($mode, '+') || \str_contains($mode, 'x') || \str_contains($mode, 'w') || \str_contains($mode, 'a') || \str_contains($mode, 'c'));
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
        if ($this->closed || $this->detached) {
            throw new \RuntimeException('Stream is closed or detached');
        }
        if (!$this->isWritable()) {
            throw new \RuntimeException('Stream is not writable');
        }        
        $result = \fwrite(phasync::writable($this->resource), $string);
        if (false === $result) {
            throw new \RuntimeException('Failed writing to stream');
        }

        return $result;
    }

    /**
     * Returns whether or not the stream is readable.
     */
    public function isReadable(): bool
    {
        if ($this->closed || $this->detached) {
            return false;
        }
        $mode = $this->mode ?? \stream_get_meta_data($this->resource)['mode'] ?? null;

        return null !== $mode && (\str_contains($mode, '+') || \str_contains($mode, 'r'));
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
        if ($this->closed || $this->detached) {
            throw new \RuntimeException('Stream is closed or detached');
        }
        if ($length < 0) {
            throw new \RuntimeException("Can't read a negative amount");
        }
        if (!$this->isReadable()) {
            throw new \RuntimeException('Stream is not writable');
        }
        $result = \fread(phasync::readable($this->resource), $length);
        if (false === $result) {
            throw new \RuntimeException('Failed writing to stream');
        }

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
        if ($this->closed || $this->detached) {
            throw new \RuntimeException('Stream is closed or detached');
        }
        $buffer = [];
        while (!$this->eof()) {
            $chunk = $this->read(32768);
            if ('' !== $chunk) {
                $buffer[] = $chunk;
            }
        }

        return \implode('', $buffer);
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
        if ($this->closed || $this->detached) {
            return null;
        }
        $meta = \stream_get_meta_data($this->resource);
        if (null !== $key) {
            return $meta[$key] ?? null;
        }

        return $meta;
    }
}
