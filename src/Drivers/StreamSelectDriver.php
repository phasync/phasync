<?php
namespace phasync\Drivers;

use Closure;
use Fiber;
use LogicException;
use phasync\CancelledException;
use phasync\DefaultContext;
use phasync\ContextInterface;
use phasync\DriverInterface;
use phasync\Loop;
use phasync\TimeoutException;
use phasync\Util\ClosureStore;
use phasync\Util\Debug;
use phasync\Util\ExceptionHolder;
use phasync\Util\FiberStore;
use phasync\Util\Scheduler;
use RuntimeException;
use SplQueue;
use stdClass;
use Throwable;
use TypeError;
use WeakMap;

class StreamSelectDriver implements DriverInterface {    

    /**
     * Fibers that are ready to be resumed.
     * 
     * @var SplQueue<Fiber>
     */
    private SplQueue $queue;

    /**
     * Fibers that are scheduled to run in the future using
     * a min-heap for efficiency.
     * 
     * @var Scheduler
     */
    private Scheduler $scheduler;

    /**
     * All streams being polled for readability
     * 
     * @var array<int,resource>
     */
    private array $readable = [];

    /**
     * All streams being polled for writability
     * @var array<int,resource>
     */
    private array $writable = [];

    /**
     * Maps the stream resource to a Fiber instance for readable and writable polling
     * 
     * @return array<int,Fiber> 
     */
    private array $streamFibers = [];

    /**
     * Holds a reference to all fibers that are currently pending the
     * event loop waiting for a flag to be raised, io or timeout. This
     * allows us to very quickly prevent scheduling fibers twice.
     * 
     * @var WeakMap
     */
    private WeakMap $pendingFibers;

    /**
     * Holds a reference to fibers that are waiting for a flag to be
     * raised. The FiberStore will automatically resume all fibers
     * if the flag object is garbage collected.
     * 
     * @var WeakMap<object, FiberStore>
     */
    private WeakMap $flaggedFibers;

    /**
     * Whenever an exception occurs which should be propagated to
     * another fiber, it can be scheduled here.
     * 
     * @var WeakMap<Fiber, object>
     */
    private WeakMap $plannedExceptions;    

    /**
     * Whenever a fiber does not handle an exception by itself,
     * that exception will be scheduled to be thrown in fibers
     * that await the failed fiber.
     * 
     * @var WeakMap<Fiber, ExceptionHolder>
     */
    private WeakMap $unhandledExceptions;

    /**
     * Closures that will be invoked when the current Fiber
     * terminates. {@see self::defer()}. The ClosureStore
     * will invoke the closures when it is garbage collected,
     * in case the event loop does not invoke the closures
     * when it detects that the fiber is terminated.
     * 
     * @var null|WeakMap<Fiber, ClosureStore>
     */
    private WeakMap $deferredClosures;

    /**
     * Traces which Fibers have been created by phasync, and
     * if the Fiber was created from another fiber, the creator
     * Fiber is referenced.
     * 
     * @var WeakMap<Fiber, Fiber|true>
     */
    private WeakMap $knownFibers;

    /**
     * Contexts are associated with each coroutine via this
     * WeakMap.
     * 
     * @var WeakMap<Fiber, ContextInterface>
     */
    private WeakMap $contexts;

    /**
     * Microtasks are closures that will be invoked immediately
     * before the next tick. They are needed for doing tasks
     * which can't run for example in the destructor of a class,
     * and should not be used excessively.
     * 
     * @var array
     */
    private array $microtasks = [];

    /**
     * Whenever a fiber is terminated, garbage collection should
     * be performed to ensure proper cleanup. It is also good for
     * stable performance.
     * 
     * @var bool
     */
    private bool $shouldGarbageCollect = false;

    /**
     * This object is used as a flag for whenever fibers are 
     * paused waiting for the event loop to become idle.
     * 
     * @var object
     */
    private object $idleFlag;

    /**
     * The default timeout for all fiber blocking operations.
     * 
     * @var float
     */
    private float $defaultTimeout = 30;

    /**
     * The time of the last timeout check iteration. This value is used
     * because checking for timeouts involves a scan through all blocked
     * fibers and is slightly expensive.
     * 
     * @var float
     */
    private float $lastTimeoutCheck = 0;

    public function __construct() {
        $this->queue = new SplQueue();
        $this->scheduler = new Scheduler();
        $this->pendingFibers = new WeakMap();
        $this->unhandledExceptions = new WeakMap();
        $this->flaggedFibers = new WeakMap();
        $this->plannedExceptions = new WeakMap();
        $this->deferredClosures = new WeakMap();
        $this->knownFibers = new WeakMap();
        $this->contexts = new WeakMap();
        $this->idleFlag = new stdClass;
    }

    public function setDefaultTimeout(float $defaultTimeout): void {
        $this->defaultTimeout = \max(0, $defaultTimeout);
    }

    public function getDefaultTimeout(): float {
        return $this->defaultTimeout;
    }

    public function getContext(Fiber $fiber): ContextInterface {
        return $this->contexts[$fiber];
    }

    public function getException(Fiber $fiber): ?Throwable {
        if (!isset($this->unhandledExceptions[$fiber])) {
            return null;
        }
        return $this->unhandledExceptions[$fiber]->get();
    }

    public function isPending(Fiber $fiber): bool {
        return isset($this->pendingFibers[$fiber]);
    }

    private function setPending(Fiber $fiber, ?float $timeout): void {
        if ($this->isPending($fiber)) {
            throw new LogicException("Fiber " . Debug::getDebugInfo($fiber) . " is already pending");
        }
        if ($timeout !== null && $timeout <= 0) {
            $this->pendingFibers[$fiber] = PHP_FLOAT_MAX;
        } else {
            $this->pendingFibers[$fiber] = \microtime(true) + ($timeout ?? $this->getDefaultTimeout());
        }
    }

    public function count(): int { 
        return $this->knownFibers->count();
    }

    private function runMicrotasks(): void {
        while ($this->microtasks !== []) {
            $microtasks = $this->microtasks;
            $this->microtasks = [];
            try {
                foreach ($microtasks as $microtask) {
                    $microtask();
                }
            } catch (Throwable $exception) {
                echo "FATAL ERROR (Microtasks can't throw exceptions)\n$exception\n";
                die();
            }    
        }
    }

    public function tick(float $maxSleepTime = 5): void {
        if (Fiber::getCurrent() !== null) {
            throw new LogicException("Can't run ticks from within a Fiber");
        }

        // Check if any fibers have timed out
        $this->checkTimeouts();

        $this->runMicrotasks();

        // Microtasks may have cleaned up and left nothing to do
        if ($this->count() === 0) {
            return;
        }

        // Activate from the scheduler
        $timestamp = \microtime(true);
        while (!$this->scheduler->isEmpty() && $this->scheduler->getNextTimestamp() <= $timestamp) {
            $this->queue->enqueue($this->scheduler->extract());
        }

        // Calculate the max idle duration
        if ($this->queue->count() > 0) {
            $maxSleepTime = 0;
        } elseif (!$this->scheduler->isEmpty()) {
            $maxSleepTime = \min($maxSleepTime, $this->scheduler->getNextTimestamp() - $timestamp);
        }        
        $maxSleepTime = \max(0, $maxSleepTime);

        // If the loop is idle, schedule any idle fibers
        if ($maxSleepTime > 0 && $this->raiseFlag($this->idleFlag) > 0) {
            $maxSleepTime = 0;
        }


        // Activate fibers waiting for IO
        if (!empty($this->readable) || !empty($this->writable)) {
            $reads = \array_values($this->readable);
            $writes = \array_values($this->writable);
            $excepts = [];

            $result = \stream_select($reads, $writes, $excepts, \intval($maxSleepTime), ($maxSleepTime - intval($maxSleepTime)) * 1000000);

            if (\is_int($result) && $result > 0) {
                foreach ($reads as $readableStream) {
                    $id = \get_resource_id($readableStream);
                    $this->queue->enqueue($this->streamFibers[$id]);
                    unset($this->streamFibers[$id], $this->readable[$id]);                
                }
                foreach ($writes as $writableStream) {
                    $id = \get_resource_id($writableStream);
                    $this->queue->enqueue($this->streamFibers[$id]);
                    unset($this->streamFibers[$id], $this->writable[$id]);                
                }
            }
        } elseif ($maxSleepTime > 0) {
            \usleep((int) (1000000 * $maxSleepTime));
        }

        // Run queued fibers
        $maxCount = $this->queue->count();
        for ($i = 0; $i < $maxCount && !$this->queue->isEmpty(); $i++) {
            /** @var Fiber */
            $fiber = $this->queue->dequeue();
            if (!isset($this->pendingFibers[$fiber])) {
                throw new LogicException("Dequeued a fiber that was not pending");
            }
            unset($this->pendingFibers[$fiber]);

            try {
                if (isset($this->plannedExceptions[$fiber])) {
                    $exception = $this->plannedExceptions[$fiber];
                    unset($this->plannedExceptions[$fiber]);
                    $fiber->throw($exception);
                } else {                    
                    $fiber->resume();
                }
            } catch (Throwable $e) {
                /**
                 * We don't know if the exception will be handled by whatever
                 * launched this fiber, so we'll store the exception here
                 * and just throw it if the fiber becomes garbage collected.
                 */
                $this->unhandledExceptions[$fiber] = $this->makeExceptionHolder($e, $fiber);
            } finally {
                /**
                 * Whenever a fiber is terminated, we'll actively check it 
                 * here to ensure deferred closures can run as soon as possible
                 * 
                 */
                if ($fiber->isTerminated()) {
                    $this->handleTerminatedFiber($fiber);
                }
            }

            $this->runMicrotasks();
        }

        /**
         * Ensure garbage collection happens with minimal
         * interference
         */
        if ($this->shouldGarbageCollect) {
            $this->shouldGarbageCollect = false;
            \gc_collect_cycles();
        }    
    }

    private function checkTimeouts(): void {
        $now = microtime(true);
        if ($now - $this->lastTimeoutCheck < 0.1) {
            return;
        }
        foreach ($this->pendingFibers as $fiber => $timeout) {
            if ($timeout <= $now) {
                $this->cancel($fiber);
                $fiber->throw(new TimeoutException("Operation timed out"));
                $this->enqueue($fiber);
            }
        }
    }
    
    public function enqueue(Fiber $fiber): void {
        $this->setPending($fiber, 0);
        $this->queue->enqueue($fiber);
    }

    public function defer(Closure $deferredFunction, Fiber $fiber): void {
        $handler = $this->deferredClosures[$fiber] = self::$deferredClosures[$fiber] ?? new ClosureStore(function($store) use ($fiber) {
            /**
             * This code will run in the garbage collector as a fail safe,
             * but if the fiber is run via phasync this should not happen.
             */
            foreach ($store as $closure) {
                try {
                    $closure();
                } catch (\Throwable $e) {
                    $this->unhandledExceptions[$fiber] = $this->makeExceptionHolder($e, $fiber);
                    return;
                }
            }
        });
        $handler->add($deferredFunction);        
        
    }

    public function waitForFlag(object $flag, float $timeout=null, Fiber $fiber): void { 

        $this->setPending($fiber, $timeout);
        if (!isset($this->flaggedFibers[$flag])) {
            $this->flaggedFibers[$flag] = new FiberStore(function($fibers) {
                foreach ($fibers as $fiber) {
                    $this->plannedExceptions[$fiber] = new RuntimeException("Flag source no longer exists");
                    unset($this->pendingFibers[$fiber]);
                    $this->enqueue($fiber);
                }
            });
        }
        $this->flaggedFibers[$flag]->add($fiber);
    }

    public function raiseFlag(object $flag): int {
        if (!isset($this->flaggedFibers[$flag])) {
            return 0;
        }

        $count = 0;
        foreach ($this->flaggedFibers[$flag] as $fiber) {
            unset($this->pendingFibers[$fiber], $this->flaggedFibers[$flag]);
            $this->enqueue($fiber);
            ++$count;
        }
        return $count;
    }

    public function idle(float $timeout=null, Fiber $fiber): void {
        $this->waitForFlag($this->idleFlag, $timeout, $fiber);
    }

    public function delay(float $seconds, Fiber $fiber): void {
        $this->setPending($fiber, 0);
        $this->scheduler->schedule($seconds + microtime(true), $fiber);
    }

    public function readable($resource, float $timeout=null, Fiber $fiber): void {        
        if (!\is_resource($resource) || \get_resource_type($resource) !== 'stream') {
            throw new TypeError("Expecting a stream resource");
        }
        $resourceId = \get_resource_id($resource);
        if (isset($this->streamFibers[$resourceId])) {
            throw new LogicException("Already blocking a Fiber for this resource");
        }
        $this->setPending($fiber, $timeout);
        $this->streamFibers[$resourceId] = $fiber;
        $this->readable[$resourceId] = $resource;
    }

    public function writable($resource, float $timeout=null, Fiber $fiber): void {
        if (!\is_resource($resource) || \get_resource_type($resource) !== 'stream') {
            throw new TypeError("Expecting a stream resource");
        }
        $resourceId = \get_resource_id($resource);
        if (isset($this->streamFibers[$resourceId])) {
            throw new LogicException("Already blocking a Fiber for this resource");
        }
        $this->setPending($fiber, $timeout);
        $this->streamFibers[$resourceId] = $fiber;
        $this->writable[$resourceId] = $resource;
    }

    public function cancel(Fiber $fiber): bool {
        if (!$this->isPending($fiber)) {
            return false;
        }

        // Search for fibers waiting for IO
        $index = \array_search($fiber, $this->streamFibers, true);
        if (\is_int($index)) {
            unset($this->readable[$index]);
            unset($this->writable[$index]);
            unset($this->streamFibers[$index]);
            unset($this->pendingFibers[$fiber]);
            return true;
        }

        // Search for fibers that are delayed
        if ($this->scheduler->contains($fiber)) {
            unset($this->pendingFibers[$fiber]);
            $this->scheduler->cancel($fiber);
            return true;
        }

        // Search for fibers that are waiting for a flag
        foreach ($this->flaggedFibers as $flag => $fiberStore) {
            if ($fiberStore->contains($fiber)) {
                unset($this->pendingFibers[$fiber]);
                $fiberStore->remove($fiber);
                return true;
            }
        }

        // If the fiber hasn't been found yet, we must search
        // enqueued fibers.
        if (!$this->queue->isEmpty()) {
            $oldQueue = $this->queue;
            $this->queue = new SplQueue();
            $result = false;
            foreach ($oldQueue as $heldFiber) {
                if ($heldFiber === $fiber) {
                    unset($this->pendingFibers[$fiber]);
                    $result = true;
                    continue;
                }
                $this->queue->enqueue($heldFiber);
            }    
        }

        return $result;
    }

    public function startFiber(Closure $function, array $args, ?ContextInterface $context = null): Fiber {
        $currentFiber = Fiber::getCurrent();

        $fiber = new Fiber($function);
        $this->knownFibers[$fiber] = $currentFiber ?? true;

        if ($context === null) {
            if ($currentFiber) {
                $context = $this->contexts[$currentFiber];
            } else {
                $context = new DefaultContext();
            }
        }

        $this->contexts[$fiber] = $context;
        $context->getFibers()->offsetSet($fiber, \microtime(true));

        try {
            $fiber->start(...$args);
        } catch (Throwable $e) {
            $this->unhandledExceptions[$fiber] = $this->makeExceptionHolder($e, $fiber);
        } finally {
            if ($fiber->isTerminated()) {
                $this->handleTerminatedFiber($fiber);
            }    
        }
        return $fiber;
    }

    /**
     * To ensure that no exceptions will be lost, an ExceptionHolder class is used.
     * When the Fiber is garbage collected, the ExceptionHolder instance will be
     * destroyed thanks to the WeakMap. The ExceptionHolder tracks if the exception
     * is retreived. If it has not been retreived when the ExceptionHolders'
     * destructor is invoked, the exception will be attached to the nearest ancestor
     * Fiber.
     * 
     * @param Throwable $exception 
     * @param Fiber $fiber 
     * @return ExceptionHolder 
     */
    private function makeExceptionHolder(Throwable $exception, Fiber $fiber): ExceptionHolder {
        if (isset($this->knownFibers[$fiber]) && $this->knownFibers[$fiber] instanceof Fiber) {
            // We can record the fiber ancestors
            $ancestors = [];
            $current = $fiber;
            while ($this->knownFibers[$current] instanceof Fiber) {
                $ancestors[] = $current = $this->knownFibers[$current];                
            }
            return new ExceptionHolder($exception, function(Throwable $exception) use ($ancestors) {
                foreach ($ancestors as $ancestor) {
                    if (!$ancestor->isTerminated() && !isset($this->plannedExceptions[$ancestor])) {
                        $this->plannedExceptions[$ancestor] = $exception;
                        return;
                    }
                }
                Loop::handleException($exception);
            });
        } else {
            return new ExceptionHolder($exception, null);
        }
    }

    /**
     * Whenever a fiber is terminated, this method should be used. It will ensure that any
     * deferred closures are immediately run and that garbage collection will occur.
     * 
     * @param Fiber $fiber 
     * @return void 
     */
    private function handleTerminatedFiber(Fiber $fiber) {
        if (isset($this->deferredClosures[$fiber])) {
            $closures = $this->deferredClosures[$fiber];
            unset($this->deferredClosures[$fiber]);
            foreach ($closures as $closure) {
                $this->microtasks[] = function() use ($closure, $fiber) {
                    try {
                        $closure();
                    } catch (Throwable $e) {
                        $this->unhandledExceptions[$fiber] = $this->makeExceptionHolder($e, $fiber);
                    }
                };
            }
        }
        $this->getContext($fiber)->getFibers()->offsetUnset($fiber);
        $this->shouldGarbageCollect = true;
    }

}