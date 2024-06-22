<?php

namespace phasync\Psr;

use phasync\ReadChannelInterface;
use Psr\Http\Message\StreamInterface;

final class StreamFactory
{
    public static function create(mixed $source): StreamInterface
    {
        if ($source instanceof StreamInterface) {
            return $source;
        } elseif (null === $source || '' === $source) {
            return EmptyStream::create();
        } elseif (\is_resource($source) && 'stream' === \get_resource_type($source)) {
            return new ResourceStream($source);
        } elseif ($source instanceof ReadChannelInterface) {
            return new ReadChannelStream($source);
        } elseif (\is_string($source) || $source instanceof \Stringable) {
            return new StringStream((string) $source);
        } elseif ($source instanceof \JsonSerializable) {
            return new StringStream(\json_encode($source));
        }
        throw new \InvalidArgumentException("Unsupported stream source '" . \get_debug_type($source) . "'");
    }
}
