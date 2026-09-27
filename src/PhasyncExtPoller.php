<?php

namespace phasync;

/**
 * The poller of phasync-ext: the extension waits for streams (epoll, no FD_SETSIZE limit) and
 * for its worker threads, parks and unparks through the loop given to phasync\ext\manage() in
 * phasync::run(), and unparks only inside poll().
 */
final class PhasyncExtPoller implements PollerInterface
{
    public function __construct(EventLoop $loop)
    {
    }

    public function poll(float $timeout): void
    {
        \phasync\ext\poll($timeout);
    }

    public function readable(mixed $stream, float $timeout = \PHP_FLOAT_MAX): void
    {
        \phasync\ext\readable($stream, $timeout);
    }

    public function writable(mixed $stream, float $timeout = \PHP_FLOAT_MAX): void
    {
        \phasync\ext\writable($stream, $timeout);
    }
}
