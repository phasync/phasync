<?php

namespace phasync\Psr;

use phasync\ReadChannelInterface;
use Psr\Http\Message\StreamInterface;

/**
 * This implementation of Psr\Http\Message\StreamInterface uses a channel
 * to read from.
 */
final class ReadChannelStream implements StreamInterface
{
    private ?ReadChannelInterface $source;
    private ?int $size;
    private int $offset = 0;

    public function __construct(ReadChannelInterface $source, ?int $size=null)
    {
        $this->source = $source;
        $this->size   = null;
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
        try {
            return $this->getContents();
        } catch (\RuntimeException $e) {
            return 'Error: ' . $e->getMessage();
        }
    }

    /**
     * Closes the stream and any underlying resources.
     */
    public function close(): void
    {
        if ($this->source) {
            $this->source->close();
        }
        $this->source = null;
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
        $this->close();

        return null;
    }

    public function getSize(): ?int
    {
        return $this->size;
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
        return $this->offset;
    }

    /**
     * Returns true if the stream is at the end of the stream.
     */
    public function eof(): bool
    {
        return $this->offset === $this->size;
    }

    /**
     * Returns whether or not the stream is seekable.
     */
    public function isSeekable(): bool
    {
        return false;
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
        throw new \RuntimeException('Not a seekable stream');
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
        $this->seek(0);
    }

    /**
     * Returns whether or not the stream is writable.
     */
    public function isWritable(): bool
    {
        return false;
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
        throw new \RuntimeException('Not writable');
    }

    /**
     * Returns whether or not the stream is readable.
     */
    public function isReadable(): bool
    {
        return $this->source && !$this->source->isClosed();
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
        if (!$this->isReadable()) {
            throw new \RuntimeException('Not readable');
        }
        $chunk = $this->source->read();
        if (\is_string($chunk)) {
            $this->offset += \strlen($chunk);

            return $chunk;
        }
        throw new \RuntimeException('Unable to read from stream');
    }

    /**
     * Returns the remaining contents in a string
     *
     * @throws \RuntimeException if unable to read
     * @throws \RuntimeException if error occurs while reading
     */
    public function getContents(): string
    {
        $chunks = [];
        while (!$this->eof()) {
            $chunk = $this->read(65536);
            if (null === $chunk) {
                $this->close();
            }
            $chunks[] = $chunk;
        }

        return \implode('', $chunks);
    }

    /**
     * Get stream metadata as an associative array or retrieve a specific key.
     *
     * The keys returned are identical to the keys returned from PHP's
     * stream_get_meta_data() function.
     *
     * @see http://php.net/manual/en/function.stream-get-meta-data.php
     *
     * @param string $key specific metadata to retrieve
     *
     * @return array|mixed|null Returns an associative array if no key is
     *                          provided. Returns a specific key value if a key is provided and the
     *                          value is found, or null if the key is not found.
     */
    public function getMetadata(?string $key = null)
    {
        $data = [
            'timed_out'    => false,
            'blocked'      => false,
            'eof'          => $this->eof(),
            'unread_bytes' => 0,
            'stream_type'  => 'phasync-channel',
            'wrapper_type' => 'phasync',
            'wrapper_data' => $this->source,
            'mode'         => 'r',
            'seekable'     => false,
            'uri'          => '',
        ];
        if (null !== $key) {
            return $data[$key] ?? null;
        }

        return $data;
    }
}
