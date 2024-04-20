<?php
namespace phasync;

use FiberError;
use LogicException;
use Throwable;

final class WaitGroup {
    private int $counter = 0;

    /**
     * Add work to the WaitGroup.
     * 
     * @return void 
     */
    public function add(): void {
        ++$this->counter;
    }

    /**
     * Signal that work has been completed to the WaitGroup.
     * 
     * @return void 
     * @throws LogicException 
     */
    public function done(): void {
        if ($this->counter === 0) {
            throw new LogicException("Call WaitGroup::done() before calling WaitGroup::add()");
        }
        if (--$this->counter === 0) {
            // Activate any waiting coroutines
            Loop::raiseFlag($this);
        }
    }

    /**
     * Wait until the WaitGroup has signalled that all work
     * is done.
     * 
     * @return void 
     * @throws UsageError
     * @throws FiberError 
     * @throws Throwable 
     */
    public function wait(): void {
        Loop::awaitFlag($this);
    }
}