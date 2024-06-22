<?php

namespace phasync\Interfaces;

use Closure;
use phasync\TimeoutException;

interface LockInterface
{
    /**
     * Lock the implementing object while the provided Closure is invoked.
     * The lock is reentrant from within the current Fiber. Other fibers
     * will block until the lock is released.
     *
     * @throws TimeoutException if the lock was not aquired
     * @throws \Throwable       if the closure throws
     *
     * @return mixed The return value from the closure
     */
    public function lock(\Closure $callable, ?float $timeout=null): mixed;
}
