<?php

namespace phasync\Util;

use Closure;
use phasync\TimeoutException;

/**
 * An object that runs a closure while holding a lock that other coroutines wait for.
 *
 * @see phasync\Util\LockTrait
 * @see phasync\Util\Synchronized
 */
interface LockInterface
{
    /**
     * Runs `$callable` while holding the lock of this object, and returns what it returns.
     *
     * The lock is reentrant for the coroutine that holds it. Other coroutines wait, and get it in the order they asked.
     *
     * @param \Closure   $callable what to run with the lock held
     * @param float|null $timeout  seconds to wait for the lock at most; null: no limit
     *
     * @return mixed what `$callable` returned
     *
     * @throws TimeoutException if the lock was not acquired in time
     * @throws \Throwable       what `$callable` threw
     */
    public function lock(\Closure $callable, ?float $timeout=null): mixed;
}
