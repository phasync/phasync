<?php

namespace phasync\Util;

use phasync;
use phasync\ChannelException;
use phasync\ReadChannelInterface;
use phasync\SelectableInterface;
use phasync\TimeoutException;

/**
 * Lets events pass at a fixed rate, shared by every coroutine that calls `wait()`.
 *
 * The constructor starts a coroutine in the current frame that issues one permit every `1 / $eventsPerSecond` seconds, and it stops when the limiter is dropped. The first `wait()` returns at once. A `$burst` above 0 lets permits accumulate while nobody waits: after an idle period, up to `$burst + 1` events pass at once. A limiter is a SelectableInterface: it is ready when a permit is available.
 *
 * ```php
 * phasync::run(function () {
 *     $limiter = new phasync\Util\RateLimiter(10);
 *
 *     for ($i = 0; $i < 5; $i++) {
 *         $limiter->wait();
 *         echo "at most 10 per second\n";
 *     }
 * });
 * ```
 *
 * @see SelectableInterface
 * @see phasync::sleep
 *
 * @topic coordination-and-limits
 *
 * @example examples/RateLimiter/1-twenty-per-second.php Twenty per second
 */
final class RateLimiter implements SelectableInterface
{
    private ReadChannelInterface $readChannel;

    /**
     * Creates the limiter and starts its coroutine.
     *
     * @param float $eventsPerSecond the rate, above 0
     * @param int   $burst           permits that may accumulate while nobody waits
     *
     * @throws \InvalidArgumentException if `$eventsPerSecond` is not above 0
     * @throws \LogicException           outside a coroutine
     *
     * @example examples/RateLimiter.__construct/1-burst.php Permits saved up while idle pass at once
     */
    public function __construct(float $eventsPerSecond, int $burst = 0)
    {
        if ($eventsPerSecond <= 0) {
            throw new \InvalidArgumentException('Events per second must be greater than 0');
        }
        $interval = (1 / $eventsPerSecond);
        \phasync::channel($readChannel, $writeChannel, $burst);
        $this->readChannel = $readChannel;
        \phasync::go(static function () use ($interval, $writeChannel) {
            do {
                try {
                    $writeChannel->write(true);
                } catch (ChannelException) {
                    return; // the limiter was dropped: its channel's reading end is gone
                }
                \phasync::sleep($interval);
            } while (!$writeChannel->isClosed());
        });
    }

    /**
     * Waits for a permit and takes it.
     *
     * @param float $timeout seconds to wait at most
     *
     * @throws TimeoutException if no permit came in time
     */
    public function await(float $timeout = \PHP_FLOAT_MAX): void
    {
        $this->readChannel->read($timeout);
    }

    /**
     * Returns true if a permit is available.
     */
    public function isReady(): bool
    {
        return $this->readChannel->isReady();
    }

    /**
     * Waits for a permit, which delays the coroutine as much as the rate requires.
     *
     * @see RateLimiter::await
     */
    public function wait(): void
    {
        $this->await();
    }
}
