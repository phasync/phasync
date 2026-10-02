<?php

namespace phasync;

/**
 * Waits for streams on behalf of the event loop.
 *
 * Coroutines wait in readable() and writable(); the loop calls poll() when it has nothing else
 * to do, and poll() unparks the waiters whose streams are ready ({@see EventLoop::park()}).
 * phasync-ext's phasync\ext\Poller has the same methods; the loop uses it directly when the
 * extension is loaded.
 */
interface PollerInterface
{
    /**
     * Waits up to `$timeout` seconds until a waited-for stream is ready, and unparks the waiters of those that are.
     *
     * 0 does not wait. With nothing waited for, it sleeps `$timeout`. A stream is ready when it is
     * readable or writable as waited for, at its end, or failed.
     */
    public function poll(float $timeout): void;

    /**
     * Parks the current coroutine until `poll()` finds `$stream` readable.
     *
     * A stream closed while waited for is ready. If the wait is cancelled or times out, the stream
     * is no longer waited for when the exception leaves. One coroutine at a time waits to read a
     * stream: a second one gets a `LogicException`.
     *
     * @param resource $stream
     *
     * @throws \LogicException  if another coroutine is waiting to read $stream
     * @throws IOException      if polling fails for this stream
     * @throws TimeoutException after $timeout seconds
     */
    public function readable(mixed $stream, float $timeout = \PHP_FLOAT_MAX): void;

    /**
     * Parks the current coroutine until `poll()` finds `$stream` writable.
     *
     * Otherwise as `readable()`. One coroutine at a time waits to write to a stream; a reader may wait meanwhile.
     *
     * @param resource $stream
     *
     * @throws \LogicException  if another coroutine is waiting to write to $stream
     * @throws IOException      if polling fails for this stream
     * @throws TimeoutException after $timeout seconds
     */
    public function writable(mixed $stream, float $timeout = \PHP_FLOAT_MAX): void;
}
