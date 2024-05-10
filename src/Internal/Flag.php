<?php
namespace phasync\Internal;

use Closure;
use Countable;
use Fiber;
use IteratorAggregate;
use LogicException;
use phasync\CancelledException;
use phasync\Debug;
use phasync\Drivers\DriverInterface;
use phasync\Legacy\Loop;
use RuntimeException;
use SplObjectStorage;
use SplQueue;
use Throwable;
use Traversable;
use WeakMap;

/**
 * Stores Fiber instances until this object is garbage collected
 * or the Fibers are explicitly released.
 *
 * @package phasync\Util
 */
final class Flag implements ObjectPoolInterface {
    use ObjectPoolTrait;

    private DriverInterface $driver;

    private array $fibers = [];

    public static function create(DriverInterface $driver): Flag {
        $instance = self::popInstance();
        if ($instance) {
            $instance->driver = $driver;
            return $instance;
        }
        return new Flag($driver);
    }

    private function __construct(DriverInterface $driver) {
        $this->driver = $driver;
    }

    public function raiseFlag(): int {
        if (count($this->fibers) === 0) {
            return 0;
        }
        $driver = $this->driver;
        $count = 0;
        foreach ($this->fibers as $k => $fiber) {
            unset($this->fibers[$k]);
            unset($driver->flagGraph[$fiber]);
            $this->driver->enqueue($fiber);
            ++$count;
        }
        return $count;
    }

    public function cancelAll(?Throwable $cancellationException = null): void {
        foreach ($this->fibers as $fid => $fiber) {
            unset($this->fibers[$fid]);
            unset($this->driver->flagGraph[$fiber]);
            $this->driver->enqueue($fiber, $cancellationException ?? new CancelledException("The operation was cancelled"));
        }
    }

    public function returnToPool(): void {
        $this->cancelAll();
        $this->pushInstance();
    }

    /**
     * Ensures any waiting Fiber instances are resumed.
     * @return void 
     */
    public function __destruct() {
        $this->cancelAll(new CancelledException("The flag no longer exists"));
    }

    public function count(): int {
        return count($this->fibers);
    }

    public function add(Fiber $fiber): void {
        $fid = \spl_object_id($fiber);
        if (isset($this->fibers[$fid])) {
            throw new LogicException("Fiber is already added");
        }
        $this->fibers[$fid] = $fiber;
    }

    public function remove(Fiber $fiber): void {
        $fid = \spl_object_id($fiber);
        if (!isset($this->fibers[$fid])) {
            throw new LogicException("Fiber is not contained here");
        }
        unset($this->fibers[$fid]);
        unset($this->driver->flagGraph[$fiber]);
    }

    public function contains(Fiber $fiber): bool {
        $fid = \spl_object_id($fiber);
        return isset($this->fibers[$fid]);
    }
}