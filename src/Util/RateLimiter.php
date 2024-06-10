<?php

namespace phasync\Util;

use phasync;
use phasync\Internal\SelectManager;
use phasync\ReadChannelInterface;
use phasync\SelectableInterface;

/**
 * This class provides an efficient tool for limiting the rate at which events happen,
 * potentially across coroutines.
 *
 * Example:
 *
 * ```php
 * phasync::run(function() {
 *   $rateLimiter = new RateLimiter(10);
 *   phasync::go(function() use ($rateLimiter) {
 *      for ($i = 0; $i < 100; $i++) {
 *        $rateLimiter->wait();
 *        echo "This happens 10 times per second\n";
 *      }
 *   });
 * });
 */
final class RateLimiter implements SelectableInterface
{
    private ReadChannelInterface $readChannel;

    public function __construct(float $eventsPerSecond, int $burst=0)
    {
        if ($eventsPerSecond <= 0) {
            throw new \InvalidArgumentException('Events per second must be greater than 0');
        }
        $interval = (1 / $eventsPerSecond);
        \phasync::channel($readChannel, $writeChannel, $burst);
        $this->readChannel = $readChannel;
        \phasync::go(static function () use ($interval, $writeChannel) {
            do {
                $writeChannel->write(true);
                \phasync::sleep($interval);
            } while (!$writeChannel->isClosed());
        });
    }

    public function await(): void
    {
        $this->readChannel->await();
    }

    public function getSelectManager(): SelectManager
    {
        return $this->readChannel->getSelectManager();
    }

    public function selectWillBlock(): bool
    {
        return $this->readChannel->selectWillBlock();
    }

    /**
     * Blocks the current coroutine if rate limiting is needed.
     *
     * @throws \RuntimeException
     */
    public function wait(): void
    {
        $this->readChannel->read();
    }
}
