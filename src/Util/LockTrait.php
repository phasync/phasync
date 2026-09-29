<?php

namespace phasync\Util;

use Closure;
use Fiber;
use phasync\Internal\ExceptionTool;
use phasync\TimeoutException;

trait LockTrait
{
    private ?\Fiber $lockHolder = null;
    private int $lockDepth      = 0;

    /** @var array<int, object{fiber: \Fiber}> those waiting for the lock, first first */
    private array $lockLine = [];

    /**
     * Lock the implementing object while the provided Closure is invoked.
     * The lock is reentrant from within the current Fiber. Other fibers
     * will block until the lock is released, and get it in the order they
     * asked: the holder hands it to the first in line.
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
        $current = \Fiber::getCurrent();
        if (null === $this->lockHolder) {
            $this->lockHolder = $current;
        } elseif ($this->lockHolder !== $current) {
            $turn                                     = new \stdClass();
            $turn->fiber                              = $current;
            $this->lockLine[\spl_object_id($turn)]    = $turn;
            $timesOut                                 = null !== $timeout ? \microtime(true) + $timeout : \PHP_FLOAT_MAX;
            $given                                    = false;
            try {
                while ($this->lockHolder !== $current) {
                    \phasync::awaitFlag($turn, $timesOut - \microtime(true));
                }
                $given = true;
            } catch (TimeoutException $e) {
                throw ExceptionTool::popTrace(new TimeoutException('Aquiring lock timed out'));
            } finally {
                if (!$given) {
                    // Gone from the line: if the lock was handed to it meanwhile, it goes on
                    if ($this->lockHolder === $current) {
                        $this->unlockToNext();
                    } else {
                        unset($this->lockLine[\spl_object_id($turn)]);
                    }
                }
            }
        }

        try {
            ++$this->lockDepth;

            return $callable();
        } finally {
            if (0 === --$this->lockDepth) {
                $this->unlockToNext();
            }
        }
    }

    /** To the first in line, or free. */
    private function unlockToNext(): void
    {
        $first = \array_key_first($this->lockLine);
        if (null === $first) {
            $this->lockHolder = null;

            return;
        }
        $turn = $this->lockLine[$first];
        unset($this->lockLine[$first]);
        $this->lockHolder = $turn->fiber;
        \phasync::raiseFlag($turn);
    }
}
