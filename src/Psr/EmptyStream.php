<?php

namespace phasync\Psr;

use Psr\Http\Message\StreamInterface;

/**
 * A PSR-7 StreamInterface containing an empty stream response.
 * The implementation is immutable, so a singleton is available
 * via {@see EmptyStream::create()}.
 */
final class EmptyStream implements StreamInterface
{
    private static ?EmptyStream $instance = null;

    public static function create(): EmptyStream
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
    }

    public function __toString(): string
    {
        return '';
    }

    public function close(): void
    {
    }

    public function detach()
    {
        return null;
    }

    public function getSize(): ?int
    {
        return 0;
    }

    public function tell(): int
    {
        return 0;
    }

    public function eof(): bool
    {
        return true;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = \SEEK_SET): void
    {
    }

    public function rewind(): void
    {
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new \RuntimeException('Not writable');
    }

    public function isReadable(): bool
    {
        return false;
    }

    public function read(int $length): string
    {
        return '';
    }

    public function getContents(): string
    {
        return '';
    }

    public function getMetadata(?string $key = null)
    {
        $data = [
            'timed_out'    => false,
            'blocked'      => false,
            'eof'          => true,
            'unread_bytes' => 0,
            'stream_type'  => 'custom',
            'wrapper_type' => '',
            'wrapper_data' => null,
            'mode'         => 'r+',
            'seekable'     => false,
            'uri'          => 'resource',
        ];
        if (null !== $key) {
            return $data[$key] ?? null;
        }

        return $data;
    }
}
