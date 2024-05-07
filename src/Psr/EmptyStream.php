<?php
namespace phasync\Psr;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * This StreamInterface implementation represents an empty
 * stream. It is readable but not writable.
 * 
 * @package phasync\Psr
 */
final class EmptyStream implements StreamInterface {

    public function __toString(): string {
        return '';
    }

    public function close(): void { }

    public function detach() {
        return null;
    }

    public function getSize(): ?int {
        return 0;
    }

    public function tell(): int {
        return 0;
    }

    public function eof(): bool {
        return true;
    }

    public function isSeekable(): bool {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void { }

    public function rewind(): void { }

    public function isWritable(): bool {
        return false;
    }

    public function write(string $string): int {
        throw new RuntimeException("Not writable");
    }

    public function isReadable(): bool { 
        return false;
    }

    public function read(int $length): string { 
        return '';
    }

    public function getContents(): string { 
        return '';
    }

    public function getMetadata(?string $key = null) { 
        $data = [
            'timed_out' => false,
            'blocked' => false,
            'eof' => true,
            'unread_bytes' => 0,
            'stream_type' => 'custom',
            'wrapper_type' => '',
            'wrapper_data' => null,
            'mode' => 'r+',
            'seekable' => false,
            'uri' => 'resource',
        ];
        if ($key !== null) {
            return $data[$key] ?? null;
        }
        return $data;
    }

}