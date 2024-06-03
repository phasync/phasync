<?php

namespace phasync\Util;

use phasync;
use phasync\Internal\SelectableTrait;
use phasync\SelectableInterface;

/**
 * This class provides an efficient tool for waiting until multiple coroutines have
 * completed their task.
 *
 * Example:
 *
 * ```php
 * phasync::run(function() {
 *   $wg = new WaitGroup();
 *   phasync::go(function() use ($wg) {
 *     $wg->add();
 *     phasync::sleep(0.5); // simulate work
 *     $wg->done();
 *   });
 *   phasync::go(function() use ($wg) {
 *     $wg->add();
 *     phasync::sleep(1); // simulate work
 *     $wg->done();
 *   });
 *
 *   $wg->wait(); // pause until the two coroutines finish after 1 second
 * });
 * ```
 */
final class WaitGroup implements SelectableInterface
{
    use SelectableTrait;

    private int $counter = 0;

    public function selectWillBlock(): bool
    {
        return $this->counter > 0;
    }

    /**
     * Add work to the WaitGroup.
     */
    public function add(): void
    {
        ++$this->counter;
    }

    /**
     * Signal that work has been completed to the WaitGroup.
     *
     * @throws \LogicException
     */
    public function done(): void
    {
        if (0 === $this->counter) {
            throw new \LogicException('Call WaitGroup::done() before calling WaitGroup::add()');
        }
        if (0 === --$this->counter) {
            // Activate any waiting coroutines
            \phasync::raiseFlag($this);
            $this->selectManager?->notify();
        }
    }

    /**
     * Wait until the WaitGroup has signalled that all work
     * is done.
     *
     * @throws UsageError
     * @throws \FiberError
     * @throws \Throwable
     */
    public function wait(): void
    {
        if ($this->counter > 0) {
            \phasync::awaitFlag($this);
        }
    }
}
