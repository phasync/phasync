<?php
namespace phasync\Drivers;

use Closure;
use Fiber;
use InvalidArgumentException;
use LogicException;
use phasync;
use phasync\CancelledException;
use phasync\Context\ContextInterface;
use phasync\Context\DefaultContext;
use phasync\Drivers\DriverInterface;
use phasync\TimeoutException;
use phasync\Internal\ClosureStore;
use phasync\Debug;
use phasync\Internal\FiberExceptionHolder;
use phasync\Internal\Flag;
use phasync\Internal\Scheduler;
use RuntimeException;
use SplObjectStorage;
use SplQueue;
use stdClass;
use Throwable;
use WeakMap;
use WeakReference;

final class StreamSelectDriver implements DriverInterface {

    /**
     * Holds the queue of fibers that will be activated on the next
     * invocation of {@see StreamSelectDriver::tick()}
     * 
     * @var SplQueue<Fiber>
     */
    private readonly SplQueue $queue;

    /**
     * Holds a reference to all Fiber that are created by this driver.
     * Service fibers have a special service context.
     * 
     * @var WeakMap<Fiber, ContextInterface>
     */
    private readonly WeakMap $contexts;

    /**
     * Holds a reference to all fibers that will be resumed by this event
     * loop, and their timeout timestamp.
     * 
     * @var SplObjectStorage<Fiber, float>
     */
    private readonly SplObjectStorage $pending;    

    /**
     * Maps child fibers to their parent fibers, unless the fiber
     * is a root fiber.
     * 
     * @var WeakMap<Fiber,null|Fiber>
     */
    private readonly WeakMap $parentFibers;

    /**
     * Exceptions to be thrown inside a fiber, or exceptions that
     * weren't handled by a terminated fiber is stored here in an
     * ExceptionHolder object. ExceptionHolder objects is a safe-
     * guard against unhandled exceptions, by using their destructor
     * to check if the exception was retrieved externally and if not,
     * it should ensure that the exception surfaces.
     * 
     * @var WeakMap<Fiber, FiberExceptionHolder>
     */
    private readonly WeakMap $fiberExceptionHolders;

    /**
     * When an exception is caught from the exception holder, it is
     * moved here to ensure it can be retrieved in the future, since
     * multiple coroutines can await the same fiber and the exception
     * needs to be thrown also there. When an exception is stored here
     * it is assumed that it has been properly handled, and no longer
     * needs the FiberExceptionHolder object as a safeguard.
     * 
     * @var WeakMap<Fiber, Throwable>
     */
    private readonly WeakMap $fiberExceptions;

    /**
     * A min-heap that provides fast access to the next fiber to be
     * activated due to a planned sleep.
     * 
     * @var Scheduler
     */
    private readonly Scheduler $scheduler;

    /**
     * All streams where we are waiting for it to become readable
     * 
     * @var array<int,resource>
     */
    private array $streams = [];

    /**
     * All stream modes as a bitmap of {@see DriverInterface} constants.
     * 
     * @var array<int,int>
     */
    private array $streamModes = [];

    /**
     * Maps the stream resource to a Fiber instance for readable,
     * writable and exception polling.
     * 
     * @return array<int,Fiber> 
     */
    private array $streamFibers = [];


    /**
     * Closures that will be invoked when the current Fiber
     * terminates. {@see self::defer()}. The ClosureStore
     * will invoke the closures when it is garbage collected,
     * in case the event loop does not invoke the closures
     * when it detects that the fiber is terminated.
     * 
     * @var WeakMap<Fiber, ClosureStore>
     */
    private WeakMap $deferredClosures;

    /**
     * Holds a reference to fibers that are waiting for a flag to be
     * raised. The FiberStore will automatically resume all fibers
     * if the flag object is garbage collected.
     * 
     * @var WeakMap<object, Flag>
     */
    private WeakMap $flaggedFibers;

    /**
     * The time of the last timeout check iteration. This value is used
     * because checking for timeouts involves a scan through all blocked
     * fibers and is slightly expensive.
     * 
     * @var float
     */
    private float $lastTimeoutCheck = 0;

    /**
     * This WeakMap traces which flag a fiber is waiting for.
     * 
     * @var WeakMap<Fiber, object>
     */
    public WeakMap $flagGraph;

    /**
     * True if cyclic garbage collection should be performed.
     * 
     * @var bool
     */
    private bool $shouldGarbageCollect = false;

    private DefaultContext $serviceContext;

    private stdClass $idleFlag;
    private stdClass $afterNextFlag;

    private ?Fiber $currentFiber = null;
    private ?ContextInterface $currentContext = null;

    public function __construct() {
        $this->queue = new SplQueue();
        $this->contexts = new WeakMap();
        $this->pending = new SplObjectStorage();
        $this->parentFibers = new WeakMap();
        $this->fiberExceptionHolders = new WeakMap();
        $this->fiberExceptions = new WeakMap();
        $this->scheduler = new Scheduler();
        $this->deferredClosures = new WeakMap();
        $this->flaggedFibers = new WeakMap();
        $this->flagGraph = new WeakMap();
        $this->idleFlag = new stdClass();
        $this->afterNextFlag = new stdClass();
        $this->serviceContext = new DefaultContext();
    }

    public function dumpState() {
        echo "----------------------------- STATE -----------------------------\n";
        $states = [
            'suspended' => 0,
            'terminated' => 0,
            'running' => 0,
        ];

        foreach ($this->contexts as $fiber => $context) {
            if ($fiber->isRunning()) $states['running']++;
            elseif ($fiber->isSuspended()) $states['suspended']++;
            elseif ($fiber->isTerminated()) $states['terminated']++;
        }

        /*
        foreach ($this->contexts as $fiber => $context) {
            echo "CONTEXT " . \spl_object_id($context) . "\n";
            foreach ($context->getFibers() as $fiber => $void) {
                echo " - " . Debug::getDebugInfo($fiber) . "\n";
            }
        }
        */

        foreach ($states as $k => $v) {
            echo "$k=$v ";
        }
        echo "Queue={$this->queue->count()} Contexts={$this->contexts->count()} Pending={$this->pending->count()} ".
            "ParentFibers={$this->parentFibers->count()} ".
            "Scheduler={$this->scheduler->count()} FlaggedFibers={$this->flaggedFibers->count()}\n";
    }

    public function count(): int {
        return $this->contexts->count();
    }

    public function tick(): void {
        $now = \microtime(true);
        $queue = $this->queue;

        // Check if any fibers have timed out
        if ($now - $this->lastTimeoutCheck > 0.1) {
            $this->checkTimeouts();
        }

        /**
         * Activate any fibers from the scheduler
         */
        while (!$this->scheduler->isEmpty() && $this->scheduler->getNextTimestamp() <= $now) {
            $fiber = $this->scheduler->extract();
            $queue->enqueue($fiber);
        }

        /**
         * Determine how long it is until the next coroutine will be running
         */
        $maxSleepTime = $queue->count() === 0 ? 0.2 : 0;

        /**
         * Ensure the delay is not too long for the scheduler
         */
        if ($maxSleepTime > 0 && !$this->scheduler->isEmpty()) {
            $maxSleepTime = \min($maxSleepTime, $this->scheduler->getNextTimestamp() - $now);
        }

        /**
         * Fibers suspended with afterNext should not have to wait too long,
         * in case they are actually waiting for another fiber suspended
         * with afterNext().
         */
        $afterNextCount = isset($this->flaggedFibers[$this->afterNextFlag]) ? $this->flaggedFibers[$this->afterNextFlag]->count() : 0;
        if ($afterNextCount > 1 && $maxSleepTime > 0.05) {
            // Fibers may be waiting for each other
            $maxSleepTime = 0.01;
        } elseif ($afterNextCount === 1 && $this->scheduler->isEmpty() && empty($this->streams)) {
            // Fiber is the only fiber waiting for itself
            $maxSleepTime = 0;
        } else {
            // Ensure non-negative sleep time
            $maxSleepTime = \max(0, $maxSleepTime);
        }

        if ($maxSleepTime > 0) {
            // Use idle times as opportunity to check timeouts
            if ($now - $this->lastTimeoutCheck > 0.1) {
                $this->checkTimeouts();
            }

            // Raise the idle flag
            $this->raiseFlag($this->idleFlag);

            // If work was added, cancel the sleep
            if ($this->queue->count() > 0) {
                $maxSleepTime = 0;
            }
        }

        /**
         * Activate any Fibers waiting for stream activity
         */
        if (!empty($this->streamFibers)) {
            $reads = [];
            $writes = [];
            $excepts = [];

            foreach ($this->streamModes as $streamId => $streamMode) {
                if (($streamMode & DriverInterface::STREAM_READ) !== 0) {
                    $reads[] = $this->streams[$streamId];
                }
                if (($streamMode & DriverInterface::STREAM_WRITE) !== 0) {
                    $writes[] = $this->streams[$streamId];
                }
                if (($streamMode & DriverInterface::STREAM_EXCEPT) !== 0) {
                    $excepts[] = [];
                }
            }

            $result = \stream_select($reads, $writes, $excepts, \intval($maxSleepTime), ($maxSleepTime - intval($maxSleepTime)) * 1000000);

            if (\is_int($result) && $result > 0) {
                foreach ($reads as $readableStream) {
                    $id = \get_resource_id($readableStream);
                    $queue->enqueue($this->streamFibers[$id]);
                    unset($this->streamFibers[$id], $this->streams[$id], $this->streamModes[$id]);                
                }
                foreach ($writes as $writableStream) {
                    $id = \get_resource_id($writableStream);
                    if (!isset($this->streams[$id])) continue;
                    $queue->enqueue($this->streamFibers[$id]);
                    unset($this->streamFibers[$id], $this->streams[$id], $this->streamModes[$id]);                
                }
                foreach ($excepts as $exceptStream) {
                    $id = \get_resource_id($exceptStream);
                    if (!isset($this->streams[$id])) continue;
                    $queue->enqueue($this->streamFibers[$id]);
                    unset($this->streamFibers[$id], $this->streams[$id], $this->streamModes[$id]);                
                }
            }
        } elseif ($maxSleepTime > 0) {
            // There are no fibers waiting for afterNext, and the 
            \usleep(intval($maxSleepTime * 1000000));
        }

        /**
         * Ensure afterNext fibers are given an opportunity to run
         */
        $this->raiseFlag($this->afterNextFlag);

        /**
         * Run enqueued fibers
         */
        $fiberCount = $queue->count();
        $fiberExceptionHolders = $this->fiberExceptionHolders;
        $contexts = $this->contexts;
        for ($i = 0; $i < $fiberCount && !$queue->isEmpty(); $i++) {
            $fiber = $queue->dequeue();
            unset($this->pending[$fiber]);

            again:

            try {
                $this->currentFiber = $fiber;
                $this->currentContext = $contexts[$fiber];

                if (isset($fiberExceptionHolders[$fiber])) {
                    // We got an opportunity to throw the exception inside the coroutine
                    $eh = $fiberExceptionHolders[$fiber];
                    unset($fiberExceptionHolders[$fiber]);
                    $exception = $eh->get();
                    $eh->returnToPool();
                    $value = $fiber->throw($exception);
                } else {
                    $value = $fiber->resume();
                }
                if ($value instanceof Fiber) {
                    // If a Fiber suspends itself with another Fiber, it swaps with that fiber
                    $fiber = $value;
                    goto again;
                }
            } catch (Throwable $e) {
                /**
                 * In case this exception is not caught, we must store it in an
                 * exception holder which will surface the exception if the fiber
                 * is garbage collected. Ideally the exception holder will not be
                 * garbage collected.
                 */
                $fiberExceptionHolders[$fiber] = $this->makeExceptionHolder($e, $fiber);
            }
            /**
             * Whenever a fiber is terminated, we'll actively check it 
             * here to ensure deferred closures can run as soon as possible
             */
            if ($fiber->isTerminated()) {
                $this->handleTerminatedFiber($fiber);
            }
        }
        $this->currentFiber = null;
        $this->currentContext = null;

        if ($this->shouldGarbageCollect) {
            \gc_collect_cycles();
            $this->shouldGarbageCollect = false;
        }
    }

    public function runService(Closure $closure): void {
        $fiber = self::create($closure, [], $this->serviceContext);
        unset($this->parentFibers[$fiber]);
    }

    public function create(Closure $closure, array $args = [], ?ContextInterface $context=null): Fiber {        
        $fiber = new Fiber($closure);

        $currentFiber = $this->currentFiber;
        $currentContext = $this->currentContext ?? new DefaultContext();
        $this->contexts[$fiber] = $context ?? ($context = $currentContext);
        $this->parentFibers[$fiber] = $currentFiber;

        // The context should track all fibers associated with it. This is
        // especially useful to ensure nested phasync::run() calls complete
        // in order.
        $context->getFibers()[$fiber] = true;

        // Start the code in the Fiber, so that we don't have to support
        // launching of coroutines as part of the event loop.
        try {
            $this->currentFiber = $fiber;
            $this->currentContext = $context;
            $value = $fiber->start(...$args);
            while ($value instanceof Fiber) {
                try {
                    $this->currentFiber = $value;
                    $this->currentContext = $this->contexts[$fiber];
                    $value = $value->resume();
                } catch (Throwable $e) {
                    $this->enqueueWithException($value, $e);
                    $value = null;
                }
            }
            return $fiber;
        } catch (Throwable $e) {
            $this->fiberExceptionHolders[$fiber] = $this->makeExceptionHolder($e, $fiber);
            return $fiber;
        } finally {
            $this->currentFiber = $currentFiber;
            $this->currentContext = $currentContext;
            if ($fiber->isTerminated()) {
                $this->handleTerminatedFiber($fiber);
            }        
        }
    }

    public function getContext(Fiber $fiber): ?ContextInterface {
        return $this->contexts[$fiber] ?? null;
    }

    public function raiseFlag(object $flag): int {
        if (!isset($this->flaggedFibers[$flag])) {
            return 0;
        }

        return $this->flaggedFibers[$flag]->raiseFlag();
    }

    public function enqueue(Fiber $fiber): void {
        $this->pending[$fiber] = \PHP_FLOAT_MAX;
        $this->queue->enqueue($fiber);
    }

    public function enqueueWithException(Fiber $fiber, Throwable $exception): void {
        $this->fiberExceptionHolders[$fiber] = $this->makeExceptionHolder($exception, $fiber);
        $this->enqueue($fiber);
    }

    public function afterNext(Fiber $fiber): void {
        $this->whenFlagged($this->afterNextFlag, PHP_FLOAT_MAX, $fiber);
    }

    public function whenFlagged(object $flag, float $timeout, Fiber $fiber): void {
        if ($flag instanceof Fiber && $this->isBlockedByFlag($flag, $fiber)) {
            // Detect cycles
            throw new LogicException("Await cycle deadlock detected");
        }

        if (!isset($this->flaggedFibers[$flag])) {
            $this->flaggedFibers[$flag] = Flag::create($this);
        }

        $this->flagGraph[$fiber] = $flag;
        $this->flaggedFibers[$flag]->add($fiber);
        $this->pending[$fiber] = \microtime(true) + $timeout;
    }

    /**
     * Returns true if `$fiber` is blocked by `$flag`. If `$flag` is a
     * fiber, the check is performed recursively to detect cycles.
     * 
     * @param Fiber $fiber 
     * @param object $flag 
     * @return bool 
     */
    private function isBlockedByFlag(Fiber $fiber, object $flag): bool {
        if ($fiber === $flag) {
            throw new InvalidArgumentException("A fiber can't block itself");
        }

        $current = $fiber;
        do {
            if (!isset($this->flagGraph[$current])) {
                // The fiber is not blocked
                return false;
            }
            $current = $this->flagGraph[$current];
            if ($current === $flag) {
                // The fiber is blocked by the flag
                return true;
            }            
        } while ($current instanceof Fiber);

        return false;
    }

    public function whenIdle(float $timeout, Fiber $fiber): void {
        $this->whenFlagged($this->idleFlag, $timeout, $fiber);
    }

    public function whenResourceActivity(mixed $resource, int $mode, float $timeout, Fiber $fiber): void {
        if (!\is_resource($resource) || \get_resource_type($resource) !== 'stream') {
            throw new InvalidArgumentException("Expecting a stream resource type");
        }
        $resourceId = \get_resource_id($resource);
        if (isset($this->streams[$resourceId])) {
            throw new RuntimeException("The stream resource is already being monitored");
        }
        $this->streams[$resourceId] = $resource;
        $this->streamModes[$resourceId] = $mode;
        $this->streamFibers[$resourceId] = $fiber;
        $this->pending[$fiber] = \microtime(true) + $timeout;
    }

    public function whenTimeElapsed(float $seconds, Fiber $fiber): void {
        if ($seconds > 0) {
            $this->pending[$fiber] = PHP_FLOAT_MAX;
            $this->scheduler->schedule($seconds + microtime(true), $fiber);
        } else {
            $this->enqueue($fiber);
        }
    }

    /**
     * Cancel a blocked fiber by throwing an exception in the fiber. If
     * an exception is not provided, a CancelledException is thrown.
     * 
     * @param Fiber $fiber 
     * @param null|Throwable $exception 
     * @return void 
     * @throws RuntimeException 
     * @throws LogicException 
     */
    public function cancel(Fiber $fiber, ?Throwable $exception = null): void {
        if (!isset($this->contexts[$fiber])) {
            throw new LogicException("The fiber (" . Debug::getDebugInfo($fiber) . ") is not a phasync fiber");
        }
        if (!isset($this->pending[$fiber])) {
            throw new RuntimeException("The fiber (" . Debug::getDebugInfo($fiber) . ") is not blocked");
        }

        // Search for fibers waiting for IO
        $index = \array_search($fiber, $this->streamFibers, true);
        if (\is_int($index)) {
            unset($this->streams[$index], $this->streamModes[$index], $this->streamFibers[$index]);            
            $this->enqueueWithException($fiber, $exception ?? new CancelledException("Operation cancelled"));
            return;
        }

        // Search for fibers that are delayed
        if ($this->scheduler->contains($fiber)) {
            $this->scheduler->cancel($fiber);
            $this->enqueueWithException($fiber, $exception ?? new CancelledException("Operation cancelled"));
            return;
        }

        // Search for fibers that are waiting for a flag
        foreach ($this->flaggedFibers as $flag => $fiberStore) {
            if ($fiberStore->contains($fiber)) {
                $fiberStore->remove($fiber);
                $this->enqueueWithException($fiber, $exception ?? new CancelledException("Operation cancelled"));
                return;
            }
        }

        // If the fiber hasn't been found yet, it must have already been
        // enqueued.
        $this->fiberExceptionHolders[$fiber] = $this->makeExceptionHolder($exception ?? new CancelledException("Operation cancelled"), $fiber);
    }

    /**
     * Gets the exception for a terminated fiber and returns the ExceptionHolder instance
     * to the pool for reuse.
     * 
     * @inheritdoc
     * @param Fiber $fiber 
     * @return null|Throwable 
     */
    public function getException(Fiber $fiber): ?Throwable {
        if (!$fiber->isTerminated()) {
            throw new LogicException("Can't get exception from a running fiber this way");
        }
        if (isset($this->fiberExceptions[$fiber])) {
            // Exception has been retrieved from the exception holder
            // before.
            return $this->fiberExceptions[$fiber];
        } elseif (isset($this->fiberExceptionHolders[$fiber])) {
            // Exception is stored in an exception holder, which can
            // now be returned to the pool
            $eh = $this->fiberExceptionHolders[$fiber];
            $exception = $eh->get();
            $eh->returnToPool();
            unset($this->fiberExceptionHolders[$fiber], $eh);
            if ($exception !== null) {
                return $this->fiberExceptions[$fiber] = $exception;
            }
        }
        return null;
    }

    public function getCurrentFiber(): ?Fiber {
        return $this->currentFiber;
    }

    public function getCurrentContext(): ?ContextInterface {
        return $this->currentContext;
    }

    private function checkTimeouts(): void {
        $now = microtime(true);
        foreach ($this->pending as $fiber) {
            if ($this->pending[$fiber] <= $now) {
                $this->cancel($fiber, new TimeoutException("Operation timed out for " . Debug::getDebugInfo($fiber)));
            }
        }
        $this->lastTimeoutCheck = $now;
    }

    /**
     * To ensure that no exceptions will be lost, an ExceptionHolder class is used.
     * When the Fiber is garbage collected, the ExceptionHolder instance will be
     * destroyed thanks to the WeakMap. The ExceptionHolder tracks if the exception
     * is retrieved. If it has not been retrieved when the ExceptionHolders'
     * destructor is invoked, the exception will be attached to the nearest ancestor
     * Fiber.
     * 
     * @param Throwable $exception 
     * @param Fiber $fiber 
     * @return FiberExceptionHolder 
     */
    private function makeExceptionHolder(Throwable $exception, Fiber $fiber): FiberExceptionHolder {        
        $context = $this->getContext($fiber);
        return FiberExceptionHolder::create($exception, $fiber, static function(Throwable $exception, WeakReference $fiberRef) use ($context) {
            // This fallback should only happen if exceptions are not
            // properly handled in the context.
            $context->setContextException($exception);
        });
    }

    /**
     * Whenever a fiber is terminated, this method must be used. It will ensure that any
     * deferred closures are immediately run and that garbage collection will occur.
     * If the Fiber threw an exception, ensure it is thrown in the parent if that is still
     * running, or 
     * 
     * @param Fiber $fiber 
     * @return void 
     */
    private function handleTerminatedFiber(Fiber $fiber) {
        $context = $this->contexts[$fiber];
        $this->raiseFlag($fiber);
        unset($this->contexts[$fiber]->getFibers()[$fiber]);
        unset($this->contexts[$fiber], $this->parentFibers[$fiber]);
        $this->shouldGarbageCollect = true;

        if ($context->getFibers()->count() === 0 && ($e = $this->getException($fiber))) {
            // This is the last fiber remaining in the context, so if it throws
            // it is the last opportunity to set the context exception. Any unhandled
            // exceptions already set at the context from a child coroutine has
            // precedence.
            if (!$context->getContextException()) {
                $context->setContextException($e);
            }
        }
    }

    private static function debug_refcount($value) {
        ob_start();
        debug_zval_dump($value);
        $string = ob_get_contents();
        ob_end_clean();
        var_dump($string);        
    }
}