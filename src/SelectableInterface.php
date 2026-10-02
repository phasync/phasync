<?php

namespace phasync;

/**
 * An object that can be asked whether using it would block, and waited for until it would not.
 *
 * `isReady()` is a non-blocking check. `await()` waits for it in the current coroutine, and `phasync::await()` takes any selectable. Channel ends, `WaitGroup`, `StringBuffer` and `RateLimiter` are selectable.
 *
 * ```php
 * phasync::run(function () {
 *     $group = new phasync\Util\WaitGroup();
 *     $group->add();
 *     phasync::go(function () use ($group) { phasync::sleep(0.01); $group->done(); });
 *
 *     var_dump($group->isReady());   // false: one task is left
 *     $group->await();
 *     var_dump($group->isReady());   // true
 * });
 * ```
 *
 * @see phasync::await
 * @see phasync\Util\WaitGroup
 */
interface SelectableInterface
{
    /**
     * Waits until the object is ready: using it would not block.
     *
     * What a timeout does depends on the object: channels and `WaitGroup` throw a TimeoutException,
     * a `StringBuffer` returns, and a `RateLimiter` has none.
     *
     * @param float $timeout seconds to wait at most
     *
     * @see SelectableInterface::isReady
     */
    public function await(float $timeout = \PHP_FLOAT_MAX): void;

    /**
     * Returns true when using the object would not block, for example because data is available or the source is closed or failed.
     *
     * @see SelectableInterface::await
     */
    public function isReady(): bool;
}
