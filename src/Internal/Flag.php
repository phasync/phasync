<?php

namespace phasync\Internal;

use Fiber;
use phasync\CancelledException;
use phasync\Debug;
use phasync\Drivers\DriverInterface;
use WeakMap;

/**
 * This class stores fiber instances associated with a flag. It
 * is designed to be used in a WeakMap, so that if the flag object
 * is garbage collected, then the destructor of this object will
 * ensure that all Fiber instances waiting to be resumed are
 * resumed with an exception.
 *
 * @internal
 */
final class Flag implements ObjectPoolInterface
{
    use ObjectPoolTrait;

    private static array $allFibers = [];
    private int $id;
    private DriverInterface $driver;

    public static function create(DriverInterface $driver): Flag
    {
        $instance = self::popInstance();
        if ($instance) {
            $instance->driver = $driver;

            return $instance;
        }

        return new Flag($driver);
    }

    private function __construct(DriverInterface $driver)
    {
        $this->id                   = \spl_object_id($this);
        $this->driver               = $driver;
        self::$allFibers[$this->id] = [];
    }

    public function listFibers(): void
    {
        foreach (self::$allFibers[$this->id] as $fiber) {
            echo ' - ' . Debug::getDebugInfo($fiber) . "\n";
        }
    }

    public function raiseFlag(): int
    {
        if (!isset(self::$allFibers[$this->id])) {
            throw new \LogicException("Flag is no longer valid and can't be raised");
        }
        if (0 === \count(self::$allFibers[$this->id])) {
            return 0;
        }
        $driver = $this->driver;
        $count  = 0;
        foreach (self::$allFibers[$this->id] as $k => $fiber) {
            unset(self::$allFibers[$this->id][$k]);
            unset($driver->flagGraph[$fiber]);
            $this->driver->enqueue($fiber);
            ++$count;
        }

        return $count;
    }

    public function cancelAll(?\Throwable $cancellationException = null): void
    {
        if (0 === \count(self::$allFibers[$this->id])) {
            return;
        }
        foreach (self::$allFibers[$this->id] as $fid => $fiber) {
            if ($fiber->isTerminated()) {
                $this->driver->handleTerminatedFiber($fiber);
            } else {
                $this->driver->enqueueWithException($fiber, $cancellationException ?? new CancelledException('The operation was cancelled'));
            }
            unset(self::$allFibers[$this->id][$fid]);
            unset($this->driver->flagGraph[$fiber]);
        }
    }

    public function returnToPool(): void
    {
        $this->cancelAll();
        $this->pushInstance();
    }

    /**
     * Ensures any waiting Fiber instances are resumed.
     *
     * @return void
     */
    public function __destruct()
    {
        $this->cancelAll(new CancelledException('The flag no longer exists'));
        unset(self::$allFibers[$this->id]);
    }

    public function count(): int
    {
        return \count(self::$allFibers[$this->id]);
    }

    public function add(\Fiber $fiber): void
    {
        $fid = \spl_object_id($fiber);
        if (isset(self::$allFibers[$this->id][$fid])) {
            throw new \LogicException('Fiber is already added');
        }
        self::$allFibers[$this->id][$fid] = $fiber;
    }

    public function remove(\Fiber $fiber): void
    {
        $fid = \spl_object_id($fiber);
        if (!isset(self::$allFibers[$this->id][$fid])) {
            throw new \LogicException('Fiber is not contained here');
        }
        unset(self::$allFibers[$this->id][$fid]);
        unset($this->driver->flagGraph[$fiber]);
    }

    public function contains(\Fiber $fiber): bool
    {
        $fid = \spl_object_id($fiber);

        return isset(self::$allFibers[$this->id][$fid]);
    }
}
