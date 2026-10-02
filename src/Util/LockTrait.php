<?php

namespace phasync\Util;

use Closure;
use Fiber;
use phasync\Internal\ExceptionTool;
use phasync\TimeoutException;

/**
 * Implements LockInterface: a lock that is reentrant for the coroutine holding it and fair for the others.
 *
 * Use it in a class that needs to protect state across waits. The coroutines asking while the lock is held are served in the order they asked: the holder hands the lock to the first in line.
 *
 * ```php
 * class Account
 * {
 *     use phasync\Util\LockTrait;
 *
 *     private int $balance = 100;
 *
 *     public function withdraw(int $amount): void
 *     {
 *         $this->lock(function () use ($amount) {
 *             $balance = $this->balance;
 *             phasync::sleep(0.01);              // another coroutine would interleave here, without the lock
 *             $this->balance = $balance - $amount;
 *         });
 *     }
 * }
 * ```
 *
 * @see phasync\Util\LockInterface
 * @see phasync\Util\Synchronized
 */
trait LockTrait
{
    private ?\Fiber $lockHolder = null;
    private int $lockDepth      = 0;

    /** @var array<int, object{fiber: \Fiber}> those waiting for the lock, first first */
    private array $lockLine = [];

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
