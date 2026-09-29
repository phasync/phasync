<?php

namespace phasync;

use Fiber;
use phasync\Context\ContextInterface;
use phasync\Context\DefaultContext;
use phasync\Context\ServiceContext;
use phasync\Internal\ExceptionTool;
use phasync\Internal\FiberExceptionHolder;
use phasync\Internal\Flag;
use phasync\Internal\Scheduler;
use WeakMap;

/**
 * phasync's event loop: runs coroutines, and waits for timers, flags and (through its poller)
 * streams.
 */
final class EventLoop implements \Countable
{
    /**
     * Holds the queue of fibers that will be activated on the next
     * invocation of {@see EventLoop::tick()}.
     *
     * @var \SplQueue<\Fiber>
     */
    private \SplQueue $queue;

    /**
     * Holds a reference to all Fiber that are created by this driver.
     * Service fibers have a special service context.
     *
     * @var \WeakMap<\Fiber, ContextInterface>
     */
    private \WeakMap $contexts;

    /**
     * phasync::finally() callbacks of the withContext() call each fiber is in, if any, by
     * spl_object_id() of the fiber; run as that call returns.
     *
     * @var array<int, list<\Closure>>
     */
    private array $withContextFinally = [];

    /**
     * Holds a reference to all fibers that will be resumed by this event
     * loop, and their timeout timestamp.
     *
     * @var \SplObjectStorage<\Fiber, float>
     */
    private \SplObjectStorage $pending;

    /**
     * Maps child fibers to their parent fibers, unless the fiber
     * is a root fiber.
     *
     * @var \WeakMap<\Fiber,\Fiber|null>
     */
    private \WeakMap $parentFibers;

    /**
     * Exceptions to be thrown inside a fiber, or exceptions that
     * weren't handled by a terminated fiber is stored here in an
     * ExceptionHolder object. ExceptionHolder objects is a safe-
     * guard against unhandled exceptions, by using their destructor
     * to check if the exception was retrieved externally and if not,
     * it should ensure that the exception surfaces.
     *
     * @var \WeakMap<\Fiber, FiberExceptionHolder>
     */
    private \WeakMap $fiberExceptionHolders;

    /**
     * When an exception is caught from the exception holder, it is
     * moved here to ensure it can be retrieved in the future, since
     * multiple coroutines can await the same fiber and the exception
     * needs to be thrown also there. When an exception is stored here
     * it is assumed that it has been properly handled, and no longer
     * needs the FiberExceptionHolder object as a safeguard.
     *
     * @var \WeakMap<\Fiber, \Throwable>
     */
    private \WeakMap $fiberExceptions;

    /**
     * A min-heap that provides fast access to the next fiber to be
     * activated due to a planned sleep.
     */
    private Scheduler $scheduler;

    /**
     * Holds a reference to fibers that are waiting for a flag to be
     * raised. The Flag object will automatically resume all fibers
     * if the object is garbage collected.
     *
     * @var \WeakMap<object, Flag>
     */
    private \WeakMap $flaggedFibers;

    /**
     * The coroutine parked in each slot ({@see self::park()}).
     *
     * @var array<int, \Fiber>
     */
    private array $parked = [];

    /**
     * The slot each parked coroutine waits in, by its object id. A parked coroutine can't go
     * away (its slot holds it), so the id is its own until unpark() or discard() removes it.
     *
     * @var array<int, int>
     */
    private array $parkedSlots = [];

    private int $nextSlot = 0;

    /**
     * The time of the last timeout check iteration. This value is used
     * because checking for timeouts involves a scan through all blocked
     * fibers and is slightly expensive.
     */
    private float $lastTimeoutCheck = 0;

    /**
     * The time that we last activated tasks waiting for idle. Tasks waiting
     * for idle time will never wait more than one second before they are
     * activated.
     */
    private float $lastIdleRun = 0;

    /**
     * This WeakMap traces which flag a fiber is waiting for.
     *
     * @var \WeakMap<\Fiber, object>
     */
    public \WeakMap $flagGraph;

    /**
     * True if cyclic garbage collection should be performed.
     */
    private bool $shouldGarbageCollect = false;

    /**
     * Callbacks to be invoked between fibers.
     *
     * @var \SplQueue<\Closure>
     */
    private \SplQueue $callbackQueue;

    /**
     * The time since the last garbage collect cycles invoked.
     */
    private float $lastGarbageCollect = 0;

    /** When the loop last counted the possible cycles, see tick(). */
    private float $lastGarbageCheck = 0;

    /** How often the loop counts the possible cycles, and how many make it collect: PHP's own threshold. */
    private const GC_CHECK_INTERVAL = 0.05;
    private const GC_ROOTS          = 10_000;
    private const GC_ROOTS_MAX      = 1_000_000;

    /** The possible cycles that make the loop collect now: GC_ROOTS, raised while collections find nothing. */
    private int $gcRoots = self::GC_ROOTS;

    private ServiceContext $serviceContext;

    private \stdClass $idleFlag;
    private \stdClass $afterNextFlag;

    private ?\Fiber $currentFiber             = null;
    private ?ContextInterface $currentContext = null;

    /**
     * The phasync extension's poll()-based stream_select() when it is loaded. It takes the
     * same arguments as the native one but is not limited by FD_SETSIZE, which caps the
     * native one at file descriptor numbers below 1024 on a typical build.
     */
    /**
     * phasync-ext's poller when the extension is loaded (epoll, and its worker threads' waiters),
     * else stream_select().
     */
    private PollerInterface|ext\Poller $poller;

    /**
     * Create a new EventLoop instance.
     */
    public function __construct()
    {
        $this->clear();
    }

    /**
     * Clear the event loop driver, removing any scheduled fibers etc.
     */
    public function clear(): void
    {
        if ($fiber = $this->getCurrentFiber()) {
            $context = $this->getContext($fiber);
            $fibers  = $context->getFibers();
            foreach ($fibers as $cFiber => $v) {
                if ($cFiber !== $fiber) {
                    $fibers->offsetUnset($cFiber);
                }
            }
            $this->contexts         = new \WeakMap();
            $this->contexts[$fiber] = $context;
        } else {
            $this->contexts = new \WeakMap();
        }
        $this->queue                 = new \SplQueue();
        $this->pending               = new \SplObjectStorage();
        $this->parentFibers          = new \WeakMap();
        $this->withContextFinally    = [];
        $this->fiberExceptionHolders = new \WeakMap();
        $this->fiberExceptions       = new \WeakMap();
        $this->scheduler             = new Scheduler();
        $this->flaggedFibers         = new \WeakMap();
        $this->flagGraph             = new \WeakMap();
        $this->idleFlag              = new \stdClass();
        $this->afterNextFlag         = new \stdClass();
        $this->serviceContext        = new ServiceContext();
        $this->poller                = \class_exists(ext\Poller::class, false)
            ? new ext\Poller($this->getSlot(...), $this->park(...), $this->unpark(...))
            : new StreamSelectPoller($this);
        $this->parked                = [];
        $this->parkedSlots           = [];
        $this->callbackQueue         = new \SplQueue();
        \gc_collect_cycles();
        $this->shouldGarbageCollect = true;
    }

    /**
     * Returns the full internal state of the driver for debugging purposes.
     *
     * @return array<string, int> Counts of various internal data structures
     */
    public function getFullState(): array
    {
        $result = [
            'queue'                 => $this->queue->count(),
            'contexts'              => $this->contexts->count(),
            'pending'               => $this->pending->count(),
            'parentFibers'          => $this->parentFibers->count(),
            'fiberExceptionHolders' => $this->fiberExceptionHolders->count(),
            'fiberExceptions'       => $this->fiberExceptions->count(),
            'scheduler'             => $this->scheduler->count(),
            'flaggedFibers'         => $this->flaggedFibers->count(),
            'flagGraph'             => $this->flagGraph->count(),
            'parked'                => \count($this->parked),
        ];

        return $result;
    }

    public function count(): int
    {
        return $this->pending->count();
    }

    /**
     * Run the fibers that are ready to resume work.
     */
    public function tick(): void
    {
        $now   = \microtime(true);
        $queue = $this->queue;

        // Check if any fibers have timed out
        if ($now - $this->lastTimeoutCheck > 0.1) {
            $this->checkTimeouts();
        }

        /*
         * Activate any fibers from the scheduler
         */
        while (!$this->scheduler->isEmpty() && $this->scheduler->getNextTimestamp() <= $now) {
            $fiber = $this->scheduler->extract();
            $queue->enqueue($fiber);
        }

        /**
         * Determine how long it is until the next coroutine will be running.
         */
        $maxSleepTime = 0 === $queue->count() ? 0.5 : 0;

        /*
         * Ensure the delay is not too long for the scheduler
         */
        if ($maxSleepTime > 0 && !$this->scheduler->isEmpty()) {
            $maxSleepTime = \min($maxSleepTime, $this->scheduler->getNextTimestamp() - $now);
        }

        if ($maxSleepTime > 0) {
            // Use idle times as opportunity to check timeouts
            if ($now - $this->lastTimeoutCheck > 0.1) {
                $this->checkTimeouts();
            }

            // If work was added, cancel the sleep
            if ($this->queue->count() > 0) {
                $maxSleepTime = 0;
            }
        } else {
            // Ensure non-negative sleep time
            $maxSleepTime = 0;
        }

        $afterNextCount = isset($this->flaggedFibers[$this->afterNextFlag]) ? $this->flaggedFibers[$this->afterNextFlag]->count() : 0;
        $idleCount      = isset($this->flaggedFibers[$this->idleFlag]) ? $this->flaggedFibers[$this->idleFlag]->count() : 0;

        if ($maxSleepTime > 0 && $afterNextCount > 0 && 0 === $queue->count() && $this->scheduler->isEmpty()) {
            $maxSleepTime = 0;
        }

        if ($now - $this->lastIdleRun > 1 || ($idleCount > 0 && $maxSleepTime > 0)) {
            // Raise the idle flag
            $this->lastIdleRun = $now;
            $this->raiseFlag($this->idleFlag);
        }

        $this->poller->poll($maxSleepTime);

        /*
         * Ensure afterNext fibers are given an opportunity to run
         */
        $this->raiseFlag($this->afterNextFlag);

        /**
         * Run enqueued fibers.
         */
        $fiberCount            = $queue->count();
        $fiberExceptionHolders = $this->fiberExceptionHolders;
        $contexts              = $this->contexts;
        for ($i = 0; $i < $fiberCount && !$queue->isEmpty(); ++$i) {
            $fiber = $queue->dequeue();
            unset($this->pending[$fiber]);

            again:

            try {
                $this->currentFiber   = $fiber;
                $this->currentContext = $contexts[$fiber];

                if (isset($fiberExceptionHolders[$fiber])) {
                    // We got an opportunity to throw the exception inside the coroutine
                    $eh = $fiberExceptionHolders[$fiber];
                    unset($fiberExceptionHolders[$fiber]);
                    $exception = $eh->get();
                    $eh->returnToPool();
                    // FiberState::for($fiber)->log('throwing ' . \get_class($exception));
                    $value = $fiber->throw($exception);
                } else {
                    $value = $fiber->resume();
                }
                if ($value instanceof \Fiber) {
                    // If a Fiber suspends itself with another Fiber, it swaps with that fiber.
                    // In this case, no exception was thrown and the fiber is not terminated
                    // $this->enqueue($value);
                    $fiber = $value;
                    goto again;
                }
            } catch (\Throwable $e) {
                /*
                 * In case this exception is not caught, we must store it in an
                 * exception holder which will surface the exception if the fiber
                 * is garbage collected. Ideally the exception holder will not be
                 * garbage collected.
                 */
                $fiberExceptionHolders[$fiber] = $this->makeExceptionHolder($e, $fiber);
            }
            /*
             * Whenever a fiber is terminated, we'll actively check it
             * here to ensure deferred closures can run as soon as possible
             */
            if ($fiber->isTerminated()) {
                $this->handleTerminatedFiber($fiber);
            }
        }
        $this->currentFiber   = null;
        $this->currentContext = null;

        if ($this->shouldGarbageCollect && $now - $this->lastGarbageCollect > 0.5) {
            \gc_collect_cycles();
            $this->lastGarbageCollect   = $now;
            $this->shouldGarbageCollect = false;
        } elseif ($now - $this->lastGarbageCheck > self::GC_CHECK_INTERVAL) {
            // Coroutines that live on (a server's connections) make garbage while none ends:
            // collect, between coroutines as always, once as many possible cycles gathered as make
            // PHP's own collector run
            $this->lastGarbageCheck = $now;
            if (\gc_status()['roots'] >= $this->gcRoots) {
                // As PHP's collector adapts: a collection that finds (almost) nothing makes the next
                // wait for more possible cycles, one that finds garbage brings the threshold back
                $this->gcRoots              = \gc_collect_cycles() < 100 ? \min($this->gcRoots * 2, self::GC_ROOTS_MAX) : self::GC_ROOTS;
                $this->lastGarbageCollect   = $now;
                $this->shouldGarbageCollect = false;
            }
        }

        while (!$this->callbackQueue->isEmpty()) {
            $callback = $this->callbackQueue->dequeue();
            $callback();
        }
    }

    /**
     * Create a coroutine that will run independently of contexts. It will run in the event
     * loop until it completes its work. The intended use case is to provide services for
     * many other fibers, such as curl_multi_exec() invocations.
     */
    public function runService(\Closure $closure): void
    {
        $fiber = $this->create(closure: $closure, context: $this->serviceContext);
        unset($this->parentFibers[$fiber]);
    }

    /**
     * Create a coroutine. If no `$context` is provided, the new Fiber will inherit the
     * context of the current coroutine, or receive a new DefaultContext instance.
     *
     * This function must not throw exceptions; the exception must be associated with the
     * returned Fiber, and be thrown when the coroutine is awaited.
     */
    public function create(\Closure $closure, array $args = [], ?ContextInterface $context = null): \Fiber
    {
        if (null !== $context) {
            $context->activate();
        }
        $fiber = new \Fiber($closure);
        // FiberState::register($fiber);

        $currentFiber               = $this->currentFiber;
        $currentContext             = $this->currentContext ?? new DefaultContext();
        $this->contexts[$fiber]     = $context ?? ($context = $currentContext);
        $this->parentFibers[$fiber] = $currentFiber;

        // The context should track all fibers associated with it. This is
        // especially useful to ensure nested phasync::run() calls complete
        // in order.
        $context->getFibers()[$fiber] = true;

        // Start the code in the Fiber, so that we don't have to support
        // launching of coroutines as part of the event loop.
        try {
            $this->currentFiber   = $fiber;
            $this->currentContext = $context;
            $value                = $fiber->start(...$args);
            while ($value instanceof \Fiber) {
                try {
                    $this->currentFiber   = $value;
                    $this->currentContext = $this->contexts[$fiber];
                    $value                = $value->resume();
                } catch (\Throwable $e) {
                    $this->enqueueWithException($value, $e);
                    $value = null;
                }
            }

            return $fiber;
        } catch (\Throwable $e) {
            // $e = ExceptionTool::popTrace($e, __FILE__);
            $this->fiberExceptionHolders[$fiber] = $this->makeExceptionHolder($e, $fiber);

            return $fiber;
        } finally {
            $this->currentFiber   = $currentFiber;
            $this->currentContext = $currentContext;
            if ($fiber->isTerminated()) {
                $this->handleTerminatedFiber($fiber);
            }
        }
    }

    /**
     * Returns the ContextInterface instance associated with the current fiber.
     */
    public function getContext(\Fiber $fiber): ?ContextInterface
    {
        return $this->contexts[$fiber] ?? null;
    }

    /**
     * Raise a flag to enable any fiber that is scheduled to activate on
     * this flag via {@see self::whenFlagged()}.
     */
    public function raiseFlag(object $flag): int
    {
        if (!isset($this->flaggedFibers[$flag])) {
            return 0;
        }

        // Raising resumes every waiter, so the store is empty afterwards
        $fiberStore = $this->flaggedFibers[$flag];
        unset($this->flaggedFibers[$flag]);
        $count = $fiberStore->raiseFlag();
        $fiberStore->returnToPool();

        return $count;
    }

    /**
     * Add a Fiber to the event loop.
     */
    public function enqueue(\Fiber $fiber): void
    {
        if ($fiber->isTerminated()) {
            throw new \LogicException("Can't enqueue a terminated fiber (" . Debug::getDebugInfo($fiber) . ')');
        }
        // FiberState::for($fiber)->log("enqueued");
        $this->pending[$fiber] = \PHP_FLOAT_MAX;
        $this->queue->enqueue($fiber);
    }

    /**
     * Add a Fiber to the event loop with an exception to be thrown.
     *
     * @internal
     *
     * @param \Throwable|null $exception
     */
    public function enqueueWithException(\Fiber $fiber, \Throwable $exception): void
    {
        if ($fiber->isTerminated()) {
            throw new \LogicException("Can't enqueue a terminated fiber (" . Debug::getDebugInfo($fiber) . ')');
        }
        // FiberState::for($fiber)->log("enqueued with " . \get_class($exception));
        $this->fiberExceptionHolders[$fiber] = $this->makeExceptionHolder($exception, $fiber);
        $this->enqueue($fiber);
    }

    /**
     * Activate the Fiber immediately after the next tick. This will
     * not affect the system sleep interval and is useful for reacting
     * to activity that may have occurred in other Fiber instances.
     */
    public function afterNext(\Fiber $fiber): void
    {
        if (isset($this->pending[$fiber])) {
            throw new \LogicException('Fiber is already pending when scheduling with afterNext');
        }
        // FiberState::for($fiber)->log("afterNext");
        $this->whenFlagged($this->afterNextFlag, \PHP_FLOAT_MAX, $fiber);
    }

    /**
     * Schedule the Fiber instance to run when the object is flagged
     * {@see self::raiseFlag()}
     *
     * @param float $timeout The number of seconds to allow the fiber to be suspended. Will raise a TimeoutException.
     */
    public function whenFlagged(object $flag, float $timeout, \Fiber $fiber): void
    {
        if (isset($this->pending[$fiber])) {
            throw new \LogicException('Fiber is already pending when enqueueing for flag');
        }
        if ($flag instanceof \Fiber && $this->isBlockedByFlag($flag, $fiber)) {
            // Detect cycles
            throw new \LogicException('Await cycle deadlock detected');
        }

        if (!isset($this->flaggedFibers[$flag])) {
            $this->flaggedFibers[$flag] = Flag::create($this);
        }

        // FiberState::for($fiber)->log("whenFlagged for " . Debug::getDebugInfo($flag));
        if ($flag instanceof \Fiber) {
            // The graph is only used to detect await cycles, which only follow fibers. It
            // must not hold ordinary flags, or a flag could never be garbage collected while
            // a coroutine waits for it.
            $this->flagGraph[$fiber] = $flag;
        }
        $this->flaggedFibers[$flag]->add($fiber);
        $this->pending[$fiber] = \microtime(true) + $timeout;
    }

    /**
     * Returns true if `$fiber` is blocked by `$flag`. If `$flag` is a
     * fiber, the check is performed recursively to detect cycles.
     */
    private function isBlockedByFlag(\Fiber $fiber, object $flag): bool
    {
        if ($fiber === $flag) {
            throw new \InvalidArgumentException("A fiber can't block itself");
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
        } while ($current instanceof \Fiber);

        return false;
    }

    /**
     * Schedule a callback to be invoked after the current (or next) tick, outside of the fiber.
     */
    public function defer(\Closure $callback): void
    {
        $this->callbackQueue->enqueue($callback);
    }

    /**
     * Activate the Fiber when there is no immediately pending activity or when the timeout has
     * occurred whichever comes first. The timeout should not throw a TimeoutException in the
     * coroutine.
     *
     * @param float $timeout The number of seconds to allow the fiber to be suspended. Will raise a TimeoutException.
     */
    public function whenIdle(float $timeout, \Fiber $fiber): void
    {
        if (isset($this->pending[$fiber])) {
            throw new \LogicException('Fiber is already pending in whenIdle');
        }
        // FiberState::for($fiber)->log('whenIdle timeout=' . $timeout);
        $this->whenFlagged($this->idleFlag, $timeout, $fiber);
    }

    /**
     * A slot number no one else has, for {@see self::park()} and {@see self::unpark()}.
     */
    public function getSlot(): int
    {
        return $this->nextSlot++;
    }

    /**
     * Suspend the current coroutine in $slot until {@see self::unpark()} resumes it, or until
     * it is cancelled or times out. A lighter wait than a flag, for code that owns its slots
     * (pollers, services, channels): what it parks, it must unpark, or the coroutine waits until
     * its timeout. Unlike a flag, nothing notices a slot its owner forgot. Flags are for objects
     * that other code holds; slots are for waits inside one component.
     *
     * @throws \LogicException  if a coroutine is parked in $slot already
     * @throws TimeoutException after $timeout seconds
     */
    public function park(int $slot, float $timeout = \PHP_FLOAT_MAX): void
    {
        if (isset($this->parked[$slot])) {
            throw new \LogicException('A coroutine is parked in slot ' . $slot . ' already');
        }
        $fiber                                     = $this->currentFiber;
        $this->parked[$slot]                       = $fiber;
        $this->parkedSlots[\spl_object_id($fiber)] = $slot;
        $this->pending[$fiber]                     = \microtime(true) + $timeout;
        try {
            \Fiber::suspend();
        } catch (\Throwable $e) {
            // As phasync::suspend(): the exception gets a trace from here
            try {
                $className = \get_class($e);
                throw new $className($e->getMessage(), $e->getCode(), $e);
            } catch (\Throwable) {
                throw $e;
            }
        }
    }

    /**
     * Resume the coroutine parked in $slot. False if the slot is vacant: nothing was parked in
     * it, it was unparked already, or its wait was cancelled or timed out (which resumed the
     * coroutine, and vacated the slot).
     */
    public function unpark(int $slot): bool
    {
        if (!isset($this->parked[$slot])) {
            return false;
        }
        $fiber = $this->parked[$slot];
        unset($this->parked[$slot], $this->parkedSlots[\spl_object_id($fiber)]);
        $this->pending[$fiber] = \PHP_FLOAT_MAX;
        $this->queue->enqueue($fiber);

        return true;
    }

    /**
     * Run $fn in the current coroutine as a coroutine of $context: while $fn runs, $context is the
     * coroutine's context and counts it among its coroutines; afterwards the coroutine has its own
     * context again. Coroutines $fn starts belong to $context and keep running after $fn returns.
     */
    public function withContext(\Closure $fn, ContextInterface $context): mixed
    {
        $fiber = $this->currentFiber;
        $id    = \spl_object_id($fiber);
        $context->activate();
        $previous                       = $this->contexts[$fiber];
        $outerFinally                   = $this->withContextFinally[$id] ?? null;
        $this->contexts[$fiber]         = $context;
        $this->currentContext           = $context;
        $context->getFibers()[$fiber]   = true;
        $this->withContextFinally[$id]  = [];
        try {
            return $fn();
        } finally {
            $callbacks = $this->withContextFinally[$id];
            try {
                if ([] !== $callbacks) {
                    // phasync::finally() callbacks registered in $fn, last first, still in $context
                    self::runFinally($callbacks);
                }
            } finally {
                if (null === $outerFinally) {
                    unset($this->withContextFinally[$id]);
                } else {
                    $this->withContextFinally[$id] = $outerFinally;
                }
                unset($context->getFibers()[$fiber]);
                $this->contexts[$fiber] = $previous;
                $this->currentContext   = $previous;
            }
        }
    }

    /**
     * Register a phasync::finally() callback with the withContext() call $fiber is in: false
     * when it is in none.
     *
     * @internal
     */
    public function finallyWithContext(\Fiber $fiber, \Closure $fn): bool
    {
        $id = \spl_object_id($fiber);
        if (!isset($this->withContextFinally[$id])) {
            return false;
        }
        $this->withContextFinally[$id][] = $fn;

        return true;
    }

    /**
     * Run callbacks last first; each runs also when a later one threw (PHP chains the
     * exceptions, as in nested finally blocks).
     *
     * @param list<\Closure> $callbacks
     */
    private static function runFinally(array $callbacks): void
    {
        if ([] === $callbacks) {
            return;
        }
        $last = \array_pop($callbacks);
        try {
            $last();
        } finally {
            self::runFinally($callbacks);
        }
    }

    /**
     * The poller that coroutines wait for streams with.
     */
    public function getPoller(): PollerInterface|ext\Poller
    {
        return $this->poller;
    }

    /**
     * Schedule the Fiber instance to run after the specified number of seconds.
     */
    public function whenTimeElapsed(float $seconds, \Fiber $fiber): void
    {
        if (isset($this->pending[$fiber])) {
            throw new \LogicException('Fiber is already pending in whenTimeElapsed');
        }
        // FiberState::for($fiber)->log('whenTimeElapsed (seconds=' . $seconds . ')');
        if ($seconds > 0) {
            $this->pending[$fiber] = \PHP_FLOAT_MAX;
            $this->scheduler->schedule($seconds + \microtime(true), $fiber);
        } else {
            $this->enqueue($fiber);
        }
    }

    /**
     * @throws \RuntimeException
     * @throws \LogicException
     */
    public function cancel(\Fiber $fiber, ?\Throwable $exception = null): void
    {
        if ($fiber->isTerminated()) {
            throw new \LogicException("Can't cancel a terminated fiber");
        }
        if (!isset($this->contexts[$fiber])) {
            throw new \LogicException('The fiber (' . Debug::getDebugInfo($fiber) . ') is not a phasync fiber');
        }
        if (!$this->discard($fiber)) {
            // FiberState::for($fiber)->log('unable to discard');
            throw new \RuntimeException('Unable to cancel fiber ' . Debug::getDebugInfo($fiber) . ', not found.');
        }
        // FiberState::for($fiber)->log('cancel (exception=' . Debug::getDebugInfo($exception) .')');
        $this->enqueueWithException($fiber, $exception ?? new CancelledException('Operation cancelled'));
    }

    /**
     * Removes a fiber from the event loop completely, without throwing any exception.
     *
     * @throws \RuntimeException if the fiber is not scheduled or pending
     */
    public function discard(\Fiber $fiber): bool
    {
        if (!isset($this->contexts[$fiber])) {
            return false;
        }

        // FiberState::for($fiber)->log('discard');

        // Search for fibers waiting for IO
        if (!isset($this->pending[$fiber])) {
            return false;
        }

        do {
            $cancelled = false;
            // Parked: the slot is emptied
            $fiberId = \spl_object_id($fiber);
            if (isset($this->parkedSlots[$fiberId])) {
                unset($this->parked[$this->parkedSlots[$fiberId]], $this->parkedSlots[$fiberId]);
                $cancelled = true;
                break;
            }

            // Search for fibers that are delayed
            if ($this->scheduler->contains($fiber)) {
                $this->scheduler->cancel($fiber);
                $cancelled = true;
                break;
            }

            // Search for fibers that are waiting for a flag
            foreach ($this->flaggedFibers as $flag => $fiberStore) {
                if ($fiberStore->contains($fiber)) {
                    $fiberStore->remove($fiber);
                    if (0 === $fiberStore->count()) {
                        unset($this->flaggedFibers[$flag]);
                        $fiberStore->returnToPool();
                    }

                    $cancelled = true;
                    break 2;
                }
            }

            // The fiber must be in the pending queue
            $count = $this->queue->count();
            for ($i = 0; $i < $count; ++$i) {
                $item = $this->queue->dequeue();
                if ($item !== $fiber) {
                    $this->queue->enqueue($item);
                } else {
                    $cancelled = true;
                }
            }
        } while (false);

        if ($cancelled) {
            unset($this->pending[$fiber]);

            /*
            if (isset($this->fiberExceptionHolders[$fiber])) {
                $this->fiberExceptionHolders[$fiber]->get();
                $this->fiberExceptionHolders[$fiber]->returnToPool();
                unset($this->fiberExceptionHolders[$fiber]);
            }
            */
            return true;
        }

        return false;
    }

    /**
     * Returns the unhandled exception thrown by a Fiber.
     */
    public function getException(\Fiber $fiber): ?\Throwable
    {
        if (!$fiber->isTerminated()) {
            throw new \LogicException("Can't get exception from a running fiber this way");
        }
        if (isset($this->fiberExceptions[$fiber])) {
            // Exception has been retrieved from the exception holder
            // before.
            return $this->fiberExceptions[$fiber];
        } elseif (isset($this->fiberExceptionHolders[$fiber])) {
            // Exception is stored in an exception holder, which can
            // now be returned to the pool
            $eh        = $this->fiberExceptionHolders[$fiber];
            $exception = $eh->get();
            $eh->returnToPool();
            unset($this->fiberExceptionHolders[$fiber], $eh);
            if (null !== $exception) {
                return $this->fiberExceptions[$fiber] = $exception;
            }
        }

        return null;
    }

    /**
     * Returns the fiber that is currently being executed by the driver.
     */
    public function getCurrentFiber(): ?\Fiber
    {
        return $this->currentFiber;
    }

    /**
     * Returns the context of the currently executing fiber.
     */
    public function getCurrentContext(): ?ContextInterface
    {
        return $this->currentContext;
    }

    /**
     * Scans pending fibers and cancels any that have exceeded their timeout.
     */
    private function checkTimeouts(): void
    {
        $now = \microtime(true);
        // Collect first. Cancelling a fiber removes it from $this->pending, and removing an
        // entry from an SplObjectStorage while iterating over it makes the loop skip entries.
        $expired = [];
        foreach ($this->pending as $fiber) {
            if ($this->pending[$fiber] <= $now) {
                $expired[] = $fiber;
            }
        }
        foreach ($expired as $fiber) {
            // FiberState::for($fiber)->log("timeout");
            $this->cancel($fiber, new TimeoutException('Operation timed out for ' . Debug::getDebugInfo($fiber)));
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
     */
    private function makeExceptionHolder(\Throwable $exception, \Fiber $fiber): FiberExceptionHolder
    {
        $context = $this->getContext($fiber);

        return FiberExceptionHolder::create($exception, $fiber, static function (\Throwable $exception, \WeakReference $fiberRef) use ($context) {
            // This fallback should only happen if exceptions are not
            // properly handled in the context.
            $context->setContextException($exception);
        });
    }

    /**
     * Whenever a fiber is terminated, this method must be used. It will ensure that any
     * deferred closures are immediately run and that garbage collection will occur.
     * If the Fiber threw an exception, ensure it is thrown in the parent if that is still
     * running, or.
     */
    private function handleTerminatedFiber(\Fiber $fiber): void
    {
        // FiberState::for($fiber)->log('handleTerminatedFiber');
        $context = $this->contexts[$fiber];
        $this->raiseFlag($fiber);
        unset($this->contexts[$fiber]->getFibers()[$fiber]);
        unset($this->contexts[$fiber], $this->parentFibers[$fiber]);
        $this->shouldGarbageCollect = true;

        if (0 === $context->getFibers()->count() && ($e = $this->getException($fiber))) {
            // This is the last fiber remaining in the context, so if it throws
            // it is the last opportunity to set the context exception. Any unhandled
            // exceptions already set at the context from a child coroutine has
            // precedence.
            if (!$context->getContextException()) {
                $context->setContextException($e);
            }
        }
    }
}
