<?php
namespace phasync\Internal;

use Fiber;
use InvalidArgumentException;
use phasync;
use phasync\Drivers\DriverInterface;
use phasync\SelectableInterface;
use phasync\SelectorInterface;
use phasync\TimeoutException;
use Throwable;
use WeakReference;

/**
 * This class can be used with stream resources to become selectable
 * with phasync::select(). Use the read or write arguments of phasync::select()
 * instead of this class directly.
 * 
 * @internal
 * @package phasync\Internal
 */
final class FiberSelector implements SelectorInterface, ObjectPoolInterface {
    use ObjectPoolTrait;
    use SelectableTrait;

    private int $id;
    private ?Fiber $fiber;
    private ?Fiber $waitFiber;

    public function getSelected(): Fiber {
        return $this->fiber;
    }

    public static function create(Fiber $fiber): FiberSelector {
        if ($fiber->isTerminated()) {
            throw new InvalidArgumentException("Fiber is already terminated");
        }
        $instance = self::popInstance() ?? new self;
        $instance->fiber = $fiber;
        $instance->waitFiber = phasync::go(args: [$instance], fn: static function(FiberSelector $instance) {
            try {
                phasync::await($instance->fiber, PHP_FLOAT_MAX);
            } catch (Throwable $e) {
                // Ignore errors in the wait fiber
            } finally {
                $instance->getSelectManager()->notify();
                $instance->waitFiber = null;
            }
        });

        return $instance;
    }

    private function __construct() {
        $this->id = \spl_object_id($this);
    }
    
    public function returnToPool(): void {
        $this->fiber = null;
        if ($this->waitFiber) {
            phasync::cancel($this->waitFiber);
        }
        $this->waitFiber = null;
        self::$pool[self::$instanceCount++] = $this;
    }

    public function selectWillBlock(): bool {
        return $this->fiber !== null && !$this->fiber->isTerminated();
    }
}
