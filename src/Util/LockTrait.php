<?php

namespace phasync\Util;

use Closure;
use Fiber;
use phasync\Internal\ExceptionTool;
use phasync\TimeoutException;

trait LockTrait
{
    private ?object $lockObject = null;
    private ?\Fiber $lockHolder = null;
    private int $lockDepth      = 0;

    /**
     * Lock the implementing object while the provided Closure is invoked.
     * The lock is reentrant from within the current Fiber. Other fibers
     * will block until the lock is released.
     *
     * Note that this implementation is not currently thread safe if threading
     * is enabled in PHP. This trait is meant to facilitate thread safe locking
     * in the future.
     *
     * @throws TimeoutException if the lock was not aquired
     * @throws Throwable        if the closure throws
     *
     * @return mixed The return value from the closure
     */
    public function lock(\Closure $callable, ?float $timeout=null): mixed
    {
        if (null === $this->lockObject) {
            $this->lockObject = new \stdClass();
        }
        $timesOut = null !== $timeout ? \microtime(true) + $timeout : \PHP_FLOAT_MAX;

        try {
            while (null !== $this->lockHolder && $this->lockHolder !== \Fiber::getCurrent()) {
                \phasync::awaitFlag($this->lockObject, $timesOut - \microtime(true));
            }
        } catch (TimeoutException $e) {
            throw ExceptionTool::popTrace(new TimeoutException('Aquiring lock timed out'));
        }

        try {
            $this->lockHolder = \Fiber::getCurrent();
            ++$this->lockDepth;

            return $callable();
        } finally {
            if (0 === --$this->lockDepth) {
                $this->lockHolder = null;
                \phasync::raiseFlag($this->lockObject);
            }
        }
    }
}
