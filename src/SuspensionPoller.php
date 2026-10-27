<?php

declare(strict_types=1);

namespace phasync;

/**
 * The event loop's poller with ext-phasync: a coroutine waiting for a stream suspends in the
 * extension (Suspension::readable()/writable()), and poll() is the loop's Suspension::poll() of
 * every coroutine suspended in a blocking call.
 *
 * @internal the event loop's; see EventLoop::getPoller()
 */
final class SuspensionPoller implements PollerInterface
{
    /** @param \Closure(float): void $poll the loop's poll of its Suspensions */
    public function __construct(private readonly \Closure $poll)
    {
    }

    public function poll(float $timeout): void
    {
        ($this->poll)($timeout);
    }

    public function readable(mixed $stream, float $timeout = \PHP_FLOAT_MAX): void
    {
        if (!ext\Suspension::readable($stream, $timeout >= \PHP_FLOAT_MAX ? null : $timeout)) {
            throw new TimeoutException('Timeout');
        }
    }

    public function writable(mixed $stream, float $timeout = \PHP_FLOAT_MAX): void
    {
        if (!ext\Suspension::writable($stream, $timeout >= \PHP_FLOAT_MAX ? null : $timeout)) {
            throw new TimeoutException('Timeout');
        }
    }
}
