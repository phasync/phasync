<?php

namespace phasync\Util;

use phasync\SelectableInterface;

/**
 * This class provides an efficient tool for waiting until multiple coroutines have
 * completed their task.
 */
final class WaitGroup implements SelectableInterface
{
    private int $counter = 0;

    public function isReady(): bool
    {
        return 0 === $this->counter;
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
        }
    }

    /**
     * Wait until the WaitGroup has signalled that all work
     * is done.
     *
     * @throws \Throwable
     */
    public function await(float $timeout = \PHP_FLOAT_MAX): void
    {
        $timesOut = \microtime(true) + $timeout;
        if (!$this->isReady()) {
            \phasync::awaitFlag($this, $timesOut - \microtime(true));
        }
    }

    /**
     * This function was renamed to {@see WaitGroup::await()} to harmonize
     * with the SelectableInterface API.
     *
     * @see WaitGroup::await()
     * @deprecated
     *
     * @throws \Throwable
     */
    public function wait(): void
    {
        $this->await();
    }
}
