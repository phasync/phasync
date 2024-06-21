<?php

namespace phasync\Psr;

use JsonSerializable;
use phasync;
use phasync\io;
use RuntimeException;
use SplFileInfo;

class FormDataStream extends ComposableStream implements MultipartStreamInterface
{
    private string $boundary;

    public function __construct(mixed $parts, string $boundary)
    {
        $this->boundary = $boundary;

        $source = (function () use ($parts) {
            yield from $this->walk($parts);
            yield $this->getEndBoundary();
        })();

        parent::__construct(readFunction: static function () use ($source) {
            if ($source->valid()) {
                $chunk = $source->current();
                $source->next();

                return $chunk;
            }

            return null;
        });
    }

    public function getContentType(): string
    {
        return "multipart/form-data";
    }

    private function walk(mixed $value, ?string $prefix = null)
    {
        if (\is_iterable($value)) {
            foreach ($value as $k => $v) {
                yield from self::walk($v, null !== $prefix ? $prefix . '[' . $k . ']' : $k);
            }
        } elseif (null !== $prefix) {
            if ($value instanceof SplFileInfo) {
                $value = $value->openFile("r");
            }
            if (\is_resource($value) && 'stream' === \get_resource_type($value)) {
                \stream_set_blocking($value, false);
                $metadata = \stream_get_meta_data($value);
                $filename = basename($metadata['uri'] ?? 'unnamed-file');
                \rewind($value);
                $contentType = \mime_content_type($filename) ?: 'application/octet-stream';
                yield $this->getBoundary([
                    "Content-Disposition: form-data; name=\"{$prefix}\"; filename=\"{$filename}\"",
                    "Content-Type: {$contentType}",
                ]);
                while (!\feof($value)) {
                    phasync::readable($value);
                    $chunk = \fread($value, 65536);
                    if (false === $chunk) {
                        throw new \RuntimeException("Unable to read from stream '$filename'");
                    }
                    yield $chunk;
                }
                yield "\r\n";
            } else {
                yield $this->getBoundary([
                    "Content-Disposition: form-data; name=\"{$prefix}\"",
                ]);
                yield $value . "\r\n";
            }
        }
    }

    private function getBoundary(array $headers): string
    {
        return "--{$this->boundary}\r\n" . \implode("\r\n", $headers) . "\r\n\r\n";
    }

    private function getEndBoundary(): string
    {
        return "--{$this->boundary}--\r\n";
    }
}
