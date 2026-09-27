<?php

namespace phasync;

/**
 * Waits for streams on behalf of the event loop. Coroutines wait in readable() and writable();
 * the loop calls poll() when it has nothing else to do, and poll() raises the flags of the
 * waiters whose streams are ready.
 */
interface PollerInterface
{
    /**
     * @param \Closure(object $flag, float $timeout): void $awaitFlag suspends the current
     *                                                                coroutine until $flag is raised
     * @param \Closure(object $flag): int                  $raiseFlag resumes the coroutines
     *                                                                waiting for $flag
     */
    public function __construct(\Closure $awaitFlag, \Closure $raiseFlag);

    /**
     * Wait up to $timeout seconds (0: don't wait) until a waited-for stream is ready, raise the
     * flags of those that are, and return. With nothing waited for, sleep $timeout. Ready:
     * readable or writable as waited for, at its end, or failed.
     */
    public function poll(float $timeout): void;

    /**
     * Wait with $awaitFlag until poll() finds $stream readable. A stream closed while waited
     * for is ready. If $awaitFlag throws, the stream is no longer waited for when the exception
     * leaves. One coroutine at a time waits to read a stream: a second one gets LogicException.
     *
     * @param resource $stream
     *
     * @throws \LogicException  if another coroutine is waiting to read $stream
     * @throws IOException      if polling fails for this stream
     * @throws TimeoutException after $timeout seconds
     */
    public function readable(mixed $stream, float $timeout = \PHP_FLOAT_MAX): void;

    /**
     * Wait with $awaitFlag until poll() finds $stream writable, as readable() does. One
     * coroutine at a time waits to write to a stream; a reader may wait meanwhile.
     *
     * @param resource $stream
     *
     * @throws \LogicException  if another coroutine is waiting to write to $stream
     * @throws IOException      if polling fails for this stream
     * @throws TimeoutException after $timeout seconds
     */
    public function writable(mixed $stream, float $timeout = \PHP_FLOAT_MAX): void;
}
