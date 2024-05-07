<?php
namespace phasync\Legacy\Drivers;

use Closure;
use CurlHandle;
use CurlMultiHandle;
use Fiber;
use LogicException;
use mysqli;
use phasync\CancelledException;
use phasync\Context\DefaultContext;
use phasync\Context\ContextInterface;
use phasync\Legacy\Drivers\OldDriverInterface;
use phasync\Legacy\Loop;
use phasync\TimeoutException;
use phasync\Internal\ClosureStore;
use phasync\Debug;
use phasync\Internal\FiberExceptionHolder;
use phasync\Internal\FiberStore;
use phasync\Internal\Scheduler;
use RuntimeException;
use Socket;
use SplQueue;
use stdClass;
use Throwable;
use TypeError;
use WeakMap;

class OldStreamSelectDriver implements DriverInterface {    

    private bool $debug = false;

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
     * @var WeakMap<Fiber, bool>
     */
    private WeakMap $pendingFibers;


    private WeakMap $timeoutFibers;

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
     * @var WeakMap<Fiber, FiberExceptionHolder>
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

    private CurlMultiHandle $curlMulti;
    private array $curlHandles = [];

    private array $afterNext = [];

    /**
     * 
     * @var WeakMap<mysqli, Fiber>
     */
    private WeakMap $mysqliHandles;

    public function __construct() {
        $this->queue = new SplQueue();
        $this->scheduler = new Scheduler();
        $this->pendingFibers = new WeakMap();
        $this->unhandledExceptions = new WeakMap();
        $this->flaggedFibers = new WeakMap();
        $this->plannedExceptions = new WeakMap();
        $this->timeoutFibers = new WeakMap();
        $this->deferredClosures = new WeakMap();
        $this->knownFibers = new WeakMap();
        $this->contexts = new WeakMap();
        $this->idleFlag = new stdClass;
        $this->curlMulti = curl_multi_init();
        $this->mysqliHandles = new WeakMap();
    }

    public function runService(Closure $closure): void {
        
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
        if ($this->debug && $this->isPending($fiber)) {
            throw new LogicException("Fiber " . Debug::getDebugInfo($fiber) . " is already pending");
        }

        if ($timeout === null) {
            $this->timeoutFibers[$fiber] = microtime(true) + $this->defaultTimeout;
        } elseif ($timeout > 0) {
            $this->timeoutFibers[$fiber] = microtime(true) + $timeout;
        }

        $this->pendingFibers[$fiber] = true;
    }

    public function count(): int { 
        return $this->knownFibers->count();
    }

    private function runMicrotasks(): void {
        while ($this->microtasks !== []) {
            $microtasks = $this->microtasks;
            $this->microtasks = [];
            foreach ($microtasks as $microtask) {
                $microtask();
            }
        }
    }

    public function tick(float $maxSleepTime = 5): void {
        if ($this->debug  && Fiber::getCurrent() !== null) {
            throw new LogicException("Can't run ticks from within a Fiber");
        }

        // Check if any fibers have timed out
        if (\microtime(true) - $this->lastTimeoutCheck > 0.1) {
            $this->checkTimeouts();
        }

        if ($this->microtasks !== []) {
            $this->runMicrotasks();
        }

        // Microtasks may have cleaned up and left nothing to do
        if ($this->count() === 0) {
            return;
        }

        // Activate from the scheduler
        $timestamp = \microtime(true);
        while (!$this->scheduler->isEmpty() && $this->scheduler->getNextTimestamp() <= $timestamp) {
            $this->queue->enqueue($this->scheduler->extract());
        }

        // Activate from curl, which will cause fibers to enter the queue and reduce maxSleepTime to 0
        if (!empty($this->curlHandles)) {
            $this->pollCurl();
        }

        if (count($this->mysqliHandles) > 0) {
            $this->pollMysqli();
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
            if (!empty($this->curlHandles)) {
                $this->pollCurl($maxSleepTime);
            } else {
                \usleep((int) (1000000 * $maxSleepTime));
            }
        }

        foreach ($this->afterNext as $k => $f) {
            $this->queue->enqueue($f);
            unset($this->afterNext[$k]);
        }

        // Run queued fibers
        $maxCount = $this->queue->count();
        for ($i = 0; $i < $maxCount && !$this->queue->isEmpty(); $i++) {
            /** @var Fiber */
            $fiber = $this->queue->dequeue();
            if ($this->debug && !isset($this->pendingFibers[$fiber])) {
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
                if (!isset($this->unhandledExceptions[$fiber])) {
                    $this->unhandledExceptions[$fiber] = $this->makeExceptionHolder($e, $fiber);
                }
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

            if ($this->microtasks !== []) {
                $this->runMicrotasks();
            }
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

    private function pollCurl(float $sleep=0): void {
        if ($sleep > 0) {
            $setCount = curl_multi_select($this->curlMulti, $sleep);
        }
        $execResult = curl_multi_exec($this->curlMulti, $stillRunning);
        /**
         * @var array{msg: int, result: int, handle: CurlHandle}
         */
        while ($info = \curl_multi_info_read($this->curlMulti)) {
            if ($info['msg'] === \CURLMSG_DONE) {
                $id = \spl_object_id($info['handle']);
                \curl_multi_remove_handle($this->curlMulti, $info['handle']);
                if (!isset($this->curlHandles[$id])) {
                    throw new RuntimeException("Unexpected curl handle");
                }
                unset($this->curlHandles[$id]);
                $this->raiseFlag($info['handle']);    
            }
        }
    }

    private function pollMysqli(float $sleep=0): void {
        $all = [];
        foreach ($this->mysqliHandles as $handle => $fiber) {
            $all[] = $handle;
        }
        $reads = $errors = $rejects = $all;
        $seconds = intval($sleep);
        $microseconds = ($sleep - $seconds) * 1000000;
        $result = mysqli::poll($reads, $errors, $rejects, $seconds, $microseconds);
        if (\is_int($result) && $result > 0) {
            foreach ($reads as $link) {
                $fiber = $this->mysqliHandles[$link];                
                unset($this->mysqliHandles[$link], $this->pendingFibers[$fiber], $this->timeoutFibers[$fiber]);
                $this->enqueue($fiber);
            }
            foreach ($errors as $link) {
                $fiber = $this->mysqliHandles[$link];                
                unset($this->mysqliHandles[$link], $this->pendingFibers[$fiber], $this->timeoutFibers[$fiber]);
                $this->enqueue($fiber);
            }
            foreach ($rejects as $link) {
                $fiber = $this->mysqliHandles[$link];                
                unset($this->mysqliHandles[$link], $this->pendingFibers[$fiber], $this->timeoutFibers[$fiber]);
                $this->enqueue($fiber);
            }
        }
    }

    private function checkTimeouts(): void {
        $now = microtime(true);
        foreach ($this->timeoutFibers as $fiber => $timeout) {
            if ($timeout <= $now) {
                $this->cancel($fiber);
                $this->plannedExceptions[$fiber] = new TimeoutException("Operation timed out for " . Debug::getDebugInfo($fiber));
                $this->enqueue($fiber);
            }
        }
        $this->lastTimeoutCheck = $now;
    }
    
    public function enqueue(Fiber $fiber): void {
        $this->setPending($fiber, 0);
        $this->queue->enqueue($fiber);
    }

    public function afterNext(Fiber $fiber): void {
        $this->setPending($fiber, 0);
        $this->afterNext[] = $fiber;
    }

    public function defer(Closure $deferredFunction, Fiber $fiber): void {
        if (!isset($this->deferredClosures[$fiber])) {
            $this->deferredClosures[$fiber] = ClosureStore::create($this, static function(OldStreamSelectDriver $driver, $store) use ($fiber) {
                /**
                 * This code will run in the garbage collector as a fail safe,
                 * but if the fiber is run via phasync this should not happen.
                 */
                foreach ($store as $closure) {
                    try {
                        $closure();
                    } catch (\Throwable $e) {
                        fwrite(STDERR, "E1 $e");
                        $driver->unhandledExceptions[$fiber] = $driver->makeExceptionHolder($e, $fiber);
                        return;
                    }
                }
            });
        }
        $this->deferredClosures[$fiber]->add($deferredFunction);        
        
    }

    public function waitForCurl(CurlHandle $curl, float $timeout=null, Fiber $fiber): void {
        $id = spl_object_id($curl);
        if (isset($this->curlHandles[$id])) {
            throw new LogicException("This curl handle is already being awaited");
        }
        $this->curlHandles[$id] = $curl;
        \curl_multi_add_handle($this->curlMulti, $curl);
        $this->whenFlagged($curl, $timeout, $fiber);
    }

    public function waitForMysqli(mysqli $connection, ?float $timeout = null, Fiber $fiber): void {        
        $this->setPending($fiber, $timeout);
        $this->mysqliHandles[$connection] = $fiber;
    }

    public function whenFlagged(object $flag, float $timeout, Fiber $fiber): void {
        $this->setPending($fiber, $timeout);
        if (!isset($this->flaggedFibers[$flag])) {
            $this->flaggedFibers[$flag] = FiberStore::create($this, static function($driver, $fibers) {
                foreach ($fibers as $fiber) {
                    $driver->plannedExceptions[$fiber] = new RuntimeException("Flag source no longer exists");
                    unset($driver->pendingFibers[$fiber], $driver->timeoutFibers[$fiber]);
                    $driver->enqueue($fiber);
                }
            });
        }
        $this->flaggedFibers[$flag]->add($fiber);
    }

    public function raiseFlag(object $flag): int {
        if (!isset($this->flaggedFibers[$flag])) {
            return 0;
        }

        $fiberStore = $this->flaggedFibers[$flag];
        unset($this->flaggedFibers[$flag]);

        $count = 0;
        foreach ($fiberStore as $fiber) {
            unset($this->pendingFibers[$fiber], $this->timeoutFibers[$fiber]);
            $this->enqueue($fiber);
            ++$count;
        }

        $fiberStore->returnToPool();
        unset($fiberStore);

        return $count;
    }

    public function whenIdle(float $timeout=null, Fiber $fiber): void {
        $this->whenFlagged($this->idleFlag, $timeout, $fiber);
    }

    public function whenTimeElapsed(float $seconds, Fiber $fiber): void {
        $this->setPending($fiber, 0);
        $this->scheduler->schedule($seconds + microtime(true), $fiber);
    }

    public function whenResourceActivity(mixed $resource, int $mode, float $timeout, Fiber $fiber): void {
        if (!\is_resource($resource) || \get_resource_type($resource) !== 'stream') {
            if ($resource instanceof Socket) {
                $resource = socket_export_stream($resource);
            } else {
                throw new TypeError("Expecting a valid stream resource");
            }
        }
        $resourceId = \get_resource_id($resource);
        if (isset($this->streamFibers[$resourceId])) {
            throw new LogicException("Already blocking a Fiber for this resource");
        }
        $this->setPending($fiber, $timeout);
        $this->streamFibers[$resourceId] = $fiber;
        if ($mode & DriverInterface::STREAM_READ) {
            $this->readable[$resourceId] = $resource;        
        }
        if ($mode & DriverInterface::STREAM_WRITE) {
            $this->writable[$resourceId] = $resource;        
        }
    }

    public function readable($resource, float $timeout=null, Fiber $fiber): void {        
        if (!\is_resource($resource) || \get_resource_type($resource) !== 'stream') {
            if ($resource instanceof Socket) {
                $resource = socket_export_stream($resource);
            } else {
                throw new TypeError("Expecting a valid stream resource");
            }
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

    public function cancel(Fiber $fiber, ?Throwable $exception=null): void {
        if (!$this->isPending($fiber)) {
            throw new LogicException("Can't cancel a fiber that is not pending");
        }
        if ($exception === null) {
            $exception = new CancelledException("Fiber was cancelled");
        }

        // Search for fibers waiting for IO
        $index = \array_search($fiber, $this->streamFibers, true);
        if (\is_int($index)) {
            unset($this->readable[$index]);
            unset($this->writable[$index]);
            unset($this->streamFibers[$index]);
            unset($this->pendingFibers[$fiber], $this->timeoutFibers[$fiber]);
            $this->plannedExceptions[$fiber] = $exception;;
            return;
        }

        // Search for fibers that are delayed
        if ($this->scheduler->contains($fiber)) {
            unset($this->pendingFibers[$fiber], $this->timeoutFibers[$fiber]);
            $this->scheduler->cancel($fiber);
            $this->plannedExceptions[$fiber] = $exception;;
            return;
        }

        // Search for fibers that are waiting for a flag
        foreach ($this->flaggedFibers as $flag => $fiberStore) {
            if ($fiberStore->contains($fiber)) {
                unset($this->pendingFibers[$fiber], $this->timeoutFibers[$fiber]);
                $fiberStore->remove($fiber);
                $this->plannedExceptions[$fiber] = $exception;;
                return;
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
                    unset($this->pendingFibers[$fiber], $this->timeoutFibers[$fiber]);
                    $result = true;
                    $this->plannedExceptions[$fiber] = $exception;
                    continue;
                }
                $this->queue->enqueue($heldFiber);
            }    
        }

        return;
    }

    public function create(Closure $function, array $args=[], ?ContextInterface $context): Fiber {
        if (\mt_rand(0,150)===0) {
            // Some programs create a large amount of fibers before the event loop can run.
            \gc_collect_cycles();
        }
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
            throw $e;
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
     * @return FiberExceptionHolder 
     */
    private function makeExceptionHolder(Throwable $exception, Fiber $fiber): FiberExceptionHolder {
        // Does this fiber have a parent fiber?
        if (isset($this->knownFibers[$fiber]) && $this->knownFibers[$fiber] instanceof Fiber) {
            // We can record the fiber ancestors
            $ancestors = [];
            $current = $fiber;
            while ($this->knownFibers[$current] instanceof Fiber) {
                $ancestors[] = $current = $this->knownFibers[$current];                
            }
            return new FiberExceptionHolder($exception, function(Throwable $exception) use ($ancestors) {
                foreach ($ancestors as $ancestor) {
                    if (!$ancestor->isTerminated() && !isset($this->plannedExceptions[$ancestor])) {
                        $this->plannedExceptions[$ancestor] = $exception;
                        return;
                    }
                }
                Loop::handleException($exception);
            });
        } else {
            return new FiberExceptionHolder($exception, null);
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
                        if (!isset($this->unhandledExceptions[$fiber])) {
                            $this->unhandledExceptions[$fiber] = $this->makeExceptionHolder($e, $fiber);
                        }
                    }
                };
            }
            $closures->returnToPool();
            unset($closures);
        }
        $this->getContext($fiber)->getFibers()->offsetUnset($fiber);
        $this->shouldGarbageCollect = true;
    }

}