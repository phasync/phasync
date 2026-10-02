<?php

namespace phasync\Psr;

use phasync\ReadChannelInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Builds a PSR-7 stream from the kinds of value a response or request body is given as.
 *
 * Response, Request and ServerRequest pass their `$body` through {@see StreamFactory::create()}.
 *
 * ```php
 * $stream = StreamFactory::create('Hello');             // a string
 * $stream = StreamFactory::create(fopen('data.txt', 'rb')); // a stream resource
 * ```
 *
 * @see Response
 * @see Request
 */
final class StreamFactory
{
    /**
     * Returns a stream for `$source`.
     *
     * A string becomes a `StringStream` (an empty stream for ''), null an empty stream, and a stream
     * resource a stream that reads it with `phasync::readable()`, so the coroutine yields while it waits. A
     * `ReadChannelInterface` becomes a stream that reads the channel. A `Stringable` is converted with
     * `(string)`, and a `JsonSerializable` with `json_encode()`. A `StreamInterface` is returned as it is.
     *
     * @param mixed $source a string, null, a stream resource, a `StreamInterface`, a `ReadChannelInterface`, a `Stringable` or a `JsonSerializable`
     *
     * @throws \InvalidArgumentException for any other value
     *
     * @see StringStream
     * @see UnbufferedStream
     */
    public static function create(mixed $source): StreamInterface
    {
        if (\is_string($source)) {
            return '' === $source ? EmptyStream::create() : new StringStream($source);
        } elseif ($source instanceof StreamInterface) {
            return $source;
        } elseif (null === $source) {
            return EmptyStream::create();
        } elseif (\is_resource($source) && 'stream' === \get_resource_type($source)) {
            return new ResourceStream($source);
        } elseif ($source instanceof ReadChannelInterface) {
            return new ReadChannelStream($source);
        } elseif ($source instanceof \Stringable) {
            return new StringStream((string) $source);
        } elseif ($source instanceof \JsonSerializable) {
            return new StringStream(\json_encode($source));
        }
        throw new \InvalidArgumentException("Unsupported stream source '" . \get_debug_type($source) . "'");
    }
}
