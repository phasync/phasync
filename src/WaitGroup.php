<?php
namespace phasync;

use Fiber;
use FiberError;
use LogicException;
use Throwable;

final class WaitGroup {
    private int $counter = 0;
    private array $suspended = [];

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
            foreach ($this->suspended as $fiber) {
                Loop::enqueue($fiber);
            }
            $this->suspended = [];
        }
        Loop::yield();
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
        $fiber = Fiber::getCurrent();
        if ($fiber === null) {
            throw new UsageError("Can only wait from within a coroutine");
        }
        $this->suspended[] = $fiber;
        Fiber::suspend();
    }
}