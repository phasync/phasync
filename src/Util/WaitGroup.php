<?php
namespace phasync\Util;

use FiberError;
use LogicException;
use phasync;
use phasync\Internal\SelectableTrait;
use phasync\SelectableInterface;
use Throwable;
use WeakMap;

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
 * 
 * @package phasync
 */
final class WaitGroup implements SelectableInterface {
    use SelectableTrait;

    private int $counter = 0;

    public function selectWillBlock(): bool {
        return $this->counter > 0;
    }

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
            phasync::raiseFlag($this);
            $this->selectManager?->notify();
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
        if ($this->counter > 0) {
            phasync::awaitFlag($this);
        }
    }
}
