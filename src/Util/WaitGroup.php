<?php

namespace phasync\Util;

use phasync\SelectableInterface;

/**
 * Waits until a counted amount of work has been done, like Go's `sync.WaitGroup`.
 *
 * Call `add()` before starting each piece of work, `done()` when it ends, and `await()` in the coroutine that waits. `await()` returns when the counter is zero, and at once if it already is. The counter may be raised again after it reached zero, so one group can be reused.
 *
 * ```php
 * phasync::run(function () {
 *     $group = new phasync\Util\WaitGroup();
 *
 *     foreach ([0.02, 0.01] as $delay) {
 *         $group->add();
 *         phasync::go(function () use ($group, $delay) {
 *             try {
 *                 phasync::sleep($delay);
 *             } finally {
 *                 $group->done();
 *             }
 *         });
 *     }
 *     $group->await();
 *     echo "both done\n";
 * });
 * ```
 *
 * @see phasync::go
 * @see phasync\SelectableInterface
 */
final class WaitGroup implements SelectableInterface
{
    private int $counter = 0;

    /**
     * Returns true when the counter is zero: nothing is left to wait for.
     *
     * @see WaitGroup::await
     */
    public function isReady(): bool
    {
        return 0 === $this->counter;
    }

    /**
     * Adds `$delta` to the counter of unfinished work.
     *
     * A negative delta marks work as done. When the counter reaches zero, the waiting coroutines resume.
     *
     * @param int $delta the amount of work to add
     *
     * @throws \LogicException if the counter would become negative
     *
     * @see WaitGroup::done
     */
    public function add(int $delta = 1): void
    {
        $counter = $this->counter + $delta;
        if ($counter < 0) {
            throw new \LogicException('The WaitGroup counter would become negative');
        }
        $this->counter = $counter;
        if (0 === $counter) {
            // Activate any waiting coroutines
            \phasync::raiseFlag($this);
        }
    }

    /**
     * Marks one piece of work as done.
     *
     * @throws \LogicException if the counter is zero already
     *
     * @see WaitGroup::add
     */
    public function done(): void
    {
        if (0 === $this->counter) {
            throw new \LogicException('Call WaitGroup::done() before calling WaitGroup::add()');
        }
        if (0 === --$this->counter) {
            // Activate any waiting coroutines
            \phasync::raiseFlag($this);
        }
    }

    /**
     * Waits until the counter is zero.
     *
     * @param float $timeout seconds to wait at most
     *
     * @throws TimeoutException if the counter is not zero in time
     * @throws \LogicException  outside a coroutine, if the counter is not zero
     *
     * @see WaitGroup::add
     */
    public function await(float $timeout = \PHP_FLOAT_MAX): void
    {
        $timesOut = \microtime(true) + $timeout;
        if (!$this->isReady()) {
            \phasync::awaitFlag($this, $timesOut - \microtime(true));
        }
    }

    /**
     * Waits until the counter is zero.
     *
     * @deprecated use {@see WaitGroup::await()}
     * @see WaitGroup::await
     */
    public function wait(): void
    {
        $this->await();
    }
}
