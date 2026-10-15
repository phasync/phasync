<?php

namespace phasync\Internal;

use Fiber;
use phasync\CancelledException;
use phasync\EventLoop;
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
final class Flag implements ObjectPoolInterface, \Countable
{
    use ObjectPoolTrait;

    private static array $allFibers = [];
    private int $id;
    private EventLoop $driver;

    /**
     * Whether the flag this instance tracks waiters for is itself a Fiber (await-cycle
     * detection, see EventLoop::whenFlagged()). All waiters here share one flag identity, so
     * this is the same for every one of them: raiseFlag()/cancelAll() use it to skip touching
     * EventLoop::$flagGraph, which an ordinary flag's waiters never entered.
     */
    private bool $isFiberFlag = false;

    public static function create(EventLoop $driver, bool $isFiberFlag = false): Flag
    {
        $instance = self::popInstance();
        if ($instance) {
            $instance->driver      = $driver;
            $instance->isFiberFlag = $isFiberFlag;

            return $instance;
        }
        $instance              = new Flag($driver);
        $instance->isFiberFlag = $isFiberFlag;

        return $instance;
    }

    private function __construct(EventLoop $driver)
    {
        $this->id                   = \spl_object_id($this);
        $this->driver               = $driver;
        self::$allFibers[$this->id] = [];
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

    public function listFibers(): void
    {
        foreach (self::$allFibers[$this->id] as $fiber) {
            echo ' - ' . Debug::getDebugInfo($fiber) . "\n";
        }
    }

    public function raiseFlag(): int
    {
        // Destroyed: after exit(), PHP's shutdown destroys every object, also those still
        // referenced, in no particular order, and a destructor may raise this flag after it
        // was destroyed itself (a channel closing). Its waiters were cancelled then.
        if (0 === $this->count()) {
            return 0;
        }
        $driver      = $this->driver;
        $isFiberFlag = $this->isFiberFlag;
        $count       = 0;
        foreach (self::$allFibers[$this->id] as $k => $fiber) {
            unset(self::$allFibers[$this->id][$k]);
            if ($isFiberFlag) {
                unset($driver->flagGraph[$fiber]);
            }
            // A waiter destroyed in that shutdown, already
            if (!$fiber->isTerminated()) {
                $driver->enqueueKnownLive($fiber);
                ++$count;
            }
        }

        return $count;
    }

    public function cancelAll(?\Throwable $cancellationException = null): void
    {
        if (0 === $this->count()) {
            return;
        }
        $isFiberFlag = $this->isFiberFlag;
        foreach (self::$allFibers[$this->id] as $fid => $fiber) {
            if (!$fiber->isTerminated()) {
                $this->driver->enqueueWithException($fiber, $cancellationException ?? new CancelledException('The operation was cancelled'));
            }
            unset(self::$allFibers[$this->id][$fid]);
            if ($isFiberFlag) {
                unset($this->driver->flagGraph[$fiber]);
            }
        }
    }

    public function returnToPool(): void
    {
        $this->cancelAll();
        $this->pushInstance();
    }

    /** Fibers waiting; none once destroyed, see raiseFlag(). */
    public function count(): int
    {
        return \count(self::$allFibers[$this->id] ?? []);
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
