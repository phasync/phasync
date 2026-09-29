<?php

namespace phasync;

use Fiber;
use phasync\Context\ExceptionHandlerInterface;
use phasync\Context\SwitchAwareInterface;
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
     * The context of every fiber created by this driver: any object. Service fibers have the
     * service context.
     *
     * @var \WeakMap<\Fiber, object>
     */
    private \WeakMap $contexts;

    /**
     * The fibers of each context.
     *
     * @var \WeakMap<object, \WeakMap<\Fiber, true>>
     */
    private \WeakMap $contextFibers;

    /**
     * The context each context was entered from (by withContext(), or go() with a context of its
     * own): cancelling a context cancels those nested in it.
     *
     * @var \WeakMap<object, object>
     */
    private \WeakMap $outerContexts;

    /**
     * The contexts of the phasync::run() calls in progress, with the failures that reached each:
     * a failure no handler took fails the nearest run() it is nested in.
     *
     * @var \WeakMap<object, list<\Throwable>>
     */
    private \WeakMap $runContexts;

    /** The context of the outermost phasync::run(): where services' failures go. */
    private ?object $rootRunContext = null;

    /**
     * The root context of each context with coroutines: a run()'s context is its own root, and so
     * is a context entered from it (a request); a context entered from any other shares the root
     * of that one. A root maps to itself, until its last coroutine leaves it (PHP 8.2's cycle
     * collector never frees a WeakMap entry whose value is its key).
     *
     * @var \WeakMap<object, object>
     */
    private \WeakMap $rootContexts;

    /**
     * Preemption (with phasync-ext's set_preempt_function()): the root contexts frozen because
     * one of their coroutines was preempted, each with that coroutine; none of a frozen root's
     * other coroutines runs before it resumes.
     *
     * @var \WeakMap<object, \Fiber>
     */
    private \WeakMap $frozen;

    /**
     * The coroutines of each frozen root that became ready meanwhile, in order: they run right
     * after the preempted one resumes.
     *
     * @var \WeakMap<object, list<\Fiber>>
     */
    private \WeakMap $held;

    /** How many roots are frozen: 0 keeps every check off the loop's path. */
    private int $preempted = 0;

    /** Ticks so far, and the coroutine and tick the preempt function saw last. */
    private int $ticks            = 0;
    private ?\Fiber $preemptFiber = null;
    private int $preemptTick      = -1;

    /**
     * Contexts used already: a context is used once.
     *
     * @var \WeakMap<object, true>
     */
    private \WeakMap $usedContexts;

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
    /**
     * Coroutines waiting with a finite timeout, by the 10 ms slot their deadline falls in
     * (rounded up, so none fires early): only these are looked at when time passes.
     *
     * @var array<int, array<int, \Fiber>>
     */
    private array $timeoutBuckets = [];

    /**
     * The slot of each coroutine in $timeoutBuckets, by spl_object_id().
     *
     * @var array<int, int>
     */
    private array $timeoutSlots = [];

    /** The last slot whose timeouts were delivered. */
    private int $lastTimeoutSlot = 0;

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

    /** How long a coroutine runs in a PHP loop before it yields to other requests (phasync-ext). */
    public const PREEMPT_INTERVAL = 0.01;

    /** How often the loop counts the possible cycles, and how many make it collect: PHP's own threshold. */
    private const GC_CHECK_INTERVAL = 0.05;
    private const GC_ROOTS          = 10_000;
    private const GC_ROOTS_MAX      = 1_000_000;

    /** The possible cycles that make the loop collect now: GC_ROOTS, raised while collections find nothing. */
    private int $gcRoots = self::GC_ROOTS;

    private \stdClass $serviceContext;

    /** PHP is shutting down: coroutines cancelled by it did not fail. */
    private static bool $exiting = false;

    private \stdClass $idleFlag;
    private \stdClass $afterNextFlag;

    private ?\Fiber $currentFiber             = null;
    private ?object $currentContext           = null;

    /** The switch-aware context whose coroutine ran last: see SwitchAwareInterface. */
    private ?SwitchAwareInterface $liveContext = null;

    /** Whether a switch-aware context was ever used: until then, switches check nothing. */
    private bool $switchAware = false;

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
        $this->contexts          = new \WeakMap();
        $this->contextFibers     = new \WeakMap();
        $this->outerContexts     = new \WeakMap();
        $this->usedContexts      = new \WeakMap();
        $this->rootContexts      = new \WeakMap();
        $this->frozen            = new \WeakMap();
        $this->held              = new \WeakMap();
        $this->preempted         = 0;
        $this->runContexts       = new \WeakMap();
        if ($fiber = $this->getCurrentFiber()) {
            // The current fiber stays, alone in its context
            $context                               = $this->currentContext;
            $this->contexts[$fiber]                = $context;
            $this->contextFibers[$context]         = new \WeakMap();
            $this->contextFibers[$context][$fiber] = true;
            $this->usedContexts[$context]          = true;
            $this->rootContexts[$context]          = $context;
        }
        $this->queue                               = new \SplQueue();
        $this->pending                             = new \SplObjectStorage();
        $this->parentFibers                        = new \WeakMap();
        $this->withContextFinally                  = [];
        $this->fiberExceptionHolders               = new \WeakMap();
        $this->fiberExceptions                     = new \WeakMap();
        $this->scheduler                           = new Scheduler();
        $this->timeoutBuckets                      = [];
        $this->timeoutSlots                        = [];
        $this->lastTimeoutSlot                     = (int) (\microtime(true) * 100);
        $this->flaggedFibers                       = new \WeakMap();
        $this->flagGraph                           = new \WeakMap();
        $this->idleFlag                            = new \stdClass();
        $this->afterNextFlag                       = new \stdClass();
        $this->serviceContext                      = new \stdClass();
        $this->usedContexts[$this->serviceContext] = true;
        $this->rootContexts[$this->serviceContext] = $this->serviceContext;
        static $shutdown                           = false;
        if (!$shutdown) {
            $shutdown = true;
            \register_shutdown_function(static function () { self::$exiting = true; });
        }
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

        // Deliver the timeouts of the 10 ms slots that have passed: usually none this tick
        if (($slot = (int) ($now * 100)) > $this->lastTimeoutSlot) {
            $this->checkTimeouts($slot);
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
            // Wake at the next timeout slot, where timeouts are waiting
            if ([] !== $this->timeoutBuckets) {
                $maxSleepTime = \min($maxSleepTime, \max(0.0, ($this->lastTimeoutSlot + 1) / 100 - $now));
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

        /*
         * Run enqueued fibers.
         */
        ++$this->ticks;
        $fiberCount            = $queue->count();
        if (0 !== $this->preempted) {
            $this->runFrozen($fiberCount);
            $fiberCount = 0;
        }
        $fiberExceptionHolders = $this->fiberExceptionHolders;
        $contexts              = $this->contexts;
        for ($i = 0; $i < $fiberCount && !$queue->isEmpty(); ++$i) {
            $fiber = $queue->dequeue();
            unset($this->pending[$fiber]);

            try {
                $this->currentFiber   = $fiber;
                $this->currentContext = $contexts[$fiber];
                if ($this->switchAware && $this->currentContext instanceof SwitchAwareInterface && $this->currentContext !== $this->liveContext) {
                    $this->makeLive($this->currentContext);
                }

                if (isset($fiberExceptionHolders[$fiber])) {
                    // We got an opportunity to throw the exception inside the coroutine
                    $eh = $fiberExceptionHolders[$fiber];
                    unset($fiberExceptionHolders[$fiber]);
                    $exception = $eh->get();
                    $eh->returnToPool();
                    // FiberState::for($fiber)->log('throwing ' . \get_class($exception));
                    $fiber->throw($exception);
                } else {
                    $fiber->resume();
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
            if (0 !== $this->preempted) {
                // A coroutine was preempted: the rest of this tick respects its frozen root
                $this->runFrozen($fiberCount - $i - 1);
                break;
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
     * The queue loop of tick() while roots are frozen: up to $count coroutines, those of a frozen
     * root held back until its preempted coroutine resumes, then run right after it.
     */
    private function runFrozen(int $count): void
    {
        $queue = $this->queue;
        for ($i = 0; $i < $count && !$queue->isEmpty(); ++$i) {
            $fiber = $queue->dequeue();
            unset($this->pending[$fiber]);
            $root  = $this->rootContexts[$this->contexts[$fiber]];
            $p     = $this->frozen[$root] ?? null;
            if (null === $p) {
                $this->resume($fiber);
            } elseif ($p !== $fiber) {
                $this->pending[$fiber] = \PHP_FLOAT_MAX; // still ready, held
                $this->held[$root][]   = $fiber;
            } else {
                $held = $this->thaw($root);
                $this->resume($fiber);
                if (($this->frozen[$root] ?? null) === $fiber) {
                    // Preempted again in its turn: the others wait on (as they would for a loop
                    // that never yields without preemption)
                    \array_push($held, ...($this->held[$root] ?? []));
                    $this->held[$root] = $held;
                } else {
                    foreach ($held as $f) {
                        unset($this->pending[$f]);
                        $this->resume($f);
                    }
                }
            }
        }
    }

    /** $root's preempted coroutine resumes (or is gone): the coroutines held for it. */
    private function thaw(object $root): array
    {
        $held = $this->held[$root] ?? [];
        unset($this->frozen[$root], $this->held[$root]);
        --$this->preempted;

        return $held;
    }

    /** One coroutine's turn: as in tick()'s own loop. */
    private function resume(\Fiber $fiber): void
    {
        try {
            $this->currentFiber   = $fiber;
            $this->currentContext = $this->contexts[$fiber];
            if ($this->switchAware && $this->currentContext instanceof SwitchAwareInterface && $this->currentContext !== $this->liveContext) {
                $this->makeLive($this->currentContext);
            }
            if (isset($this->fiberExceptionHolders[$fiber])) {
                $eh = $this->fiberExceptionHolders[$fiber];
                unset($this->fiberExceptionHolders[$fiber]);
                $exception = $eh->get();
                $eh->returnToPool();
                $fiber->throw($exception);
            } else {
                $fiber->resume();
            }
        } catch (\Throwable $e) {
            $this->fiberExceptionHolders[$fiber] = $this->makeExceptionHolder($e, $fiber);
        }
        if ($fiber->isTerminated()) {
            $this->handleTerminatedFiber($fiber);
        }
        $this->currentFiber   = null;
        $this->currentContext = null;
    }

    /**
     * phasync-ext's preempt function (set_preempt_function()): called between iterations of a
     * PHP loop, about every PREEMPT_INTERVAL seconds. The running coroutine yields when it has
     * run a whole interval and isn't in phasync's or swerve's own code, so that timers, I/O and
     * other requests get their turn (whether any is ready is only known after the loop has
     * polled); its root context stays frozen until it resumes.
     *
     * @internal phasync::run()
     */
    public function preempt(): void
    {
        $fiber = $this->currentFiber;
        if (null === $fiber) {
            return;
        }
        if ($fiber !== $this->preemptFiber || $this->ticks !== $this->preemptTick) {
            // Not running a whole interval yet: the next call decides
            $this->preemptFiber = $fiber;
            $this->preemptTick  = $this->ticks;

            return;
        }
        $caller = \debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['class'] ?? '';
        if (\str_starts_with($caller, 'phasync\\') || 'phasync' === $caller || \str_starts_with($caller, 'Swerve\\')) {
            return; // never inside the runtime's own code
        }
        $root = $this->rootContexts[$this->contexts[$fiber]];
        if (isset($this->frozen[$root])) {
            return; // its root is frozen already (another coroutine of it was preempted)
        }
        $this->frozen[$root] = $fiber;
        ++$this->preempted;
        $this->preemptFiber = null;
        $this->enqueue($fiber);
        \Fiber::suspend();
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
     * context of the current coroutine, or get a context of its own (an object).
     *
     * This function must not throw exceptions; the exception must be associated with the
     * returned Fiber, and be thrown when the coroutine is awaited.
     */
    public function create(\Closure $closure, array $args = [], ?object $context = null): \Fiber
    {
        $currentFiber   = $this->currentFiber;
        $currentContext = $this->currentContext;
        if (null !== $context) {
            if ($context !== $this->serviceContext) { // services share theirs
                $this->useContext($context, $currentContext);
            }
        } elseif (null !== $currentContext) {
            $context = $currentContext;
        } else {
            $this->useContext($context = new \stdClass(), null);
        }
        $fiber = new \Fiber($closure);
        // FiberState::register($fiber);

        $this->contexts[$fiber]     = $context;
        $this->parentFibers[$fiber] = $currentFiber;

        // The context should track all fibers associated with it. This is
        // especially useful to ensure nested phasync::run() calls complete
        // in order.
        $this->joinContext($context, $fiber);

        // Start the code in the Fiber, so that we don't have to support
        // launching of coroutines as part of the event loop.
        try {
            $this->currentFiber   = $fiber;
            $this->currentContext = $context;
            if ($context instanceof SwitchAwareInterface && $context !== $this->liveContext) {
                $this->switchAware = true;
                $this->makeLive($context);
            }
            $fiber->start(...$args);

            return $fiber;
        } catch (\Throwable $e) {
            // $e = ExceptionTool::popTrace($e, __FILE__);
            $this->fiberExceptionHolders[$fiber] = $this->makeExceptionHolder($e, $fiber);

            return $fiber;
        } finally {
            $this->currentFiber   = $currentFiber;
            $this->currentContext = $currentContext;
            if ($this->switchAware && null !== $currentFiber && $currentContext instanceof SwitchAwareInterface && $currentContext !== $this->liveContext) {
                $this->makeLive($currentContext); // the creating coroutine goes on
            }
            if ($fiber->isTerminated()) {
                $this->handleTerminatedFiber($fiber);
            }
        }
    }

    /**
     * The context of $fiber.
     */
    public function getContext(\Fiber $fiber): ?object
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
        if ([] !== $this->timeoutSlots) {
            $this->removeTimeout($fiber);
        }
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
        if ($timeout <= 0) {
            throw new TimeoutException('The timeout had run out before waiting'); // a deadline already past
        }
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
        $this->pending[$fiber] = $deadline = \microtime(true) + $timeout;
        if ($timeout < 1e9) {
            $this->addTimeout($fiber, $deadline);
        }
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
        if ($timeout <= 0) {
            throw new TimeoutException('The timeout had run out before waiting'); // a deadline already past
        }
        if (isset($this->parked[$slot])) {
            throw new \LogicException('A coroutine is parked in slot ' . $slot . ' already');
        }
        $fiber                                     = $this->currentFiber;
        $this->parked[$slot]                       = $fiber;
        $this->parkedSlots[\spl_object_id($fiber)] = $slot;
        $this->pending[$fiber]                     = $deadline = \microtime(true) + $timeout;
        if ($timeout < 1e9) {
            $this->addTimeout($fiber, $deadline);
        }
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
        if ([] !== $this->timeoutSlots) {
            $this->removeTimeout($fiber);
        }
        $this->queue->enqueue($fiber);

        return true;
    }

    /**
     * Run $fn in the current coroutine as a coroutine of $context: while $fn runs, $context is the
     * coroutine's context and counts it among its coroutines; afterwards the coroutine has its own
     * context again. Coroutines $fn starts belong to $context and keep running after $fn returns.
     */
    public function withContext(\Closure $fn, object $context): mixed
    {
        $fiber                          = $this->currentFiber;
        $id                             = \spl_object_id($fiber);
        $previous                       = $this->contexts[$fiber];
        $this->useContext($context, $previous);
        $outerFinally                   = $this->withContextFinally[$id] ?? null;
        $this->contexts[$fiber]         = $context;
        $this->currentContext           = $context;
        $this->joinContext($context, $fiber);
        if ($context instanceof SwitchAwareInterface && $context !== $this->liveContext) {
            $this->switchAware = true;
            $this->makeLive($context);
        }
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
                unset($this->contextFibers[$context][$fiber]);
                $this->leftContext($context);
                $this->contexts[$fiber] = $previous;
                $this->currentContext   = $previous;
                if ($this->switchAware && $previous instanceof SwitchAwareInterface && $previous !== $this->liveContext) {
                    $this->makeLive($previous);
                }
            }
        }
    }

    /**
     * The coroutines of $context, and of the contexts nested in it: those entered from it with
     * withContext() or given to go() by its coroutines.
     *
     * @return list<\Fiber>
     */
    public function getFibers(object $context): array
    {
        $fibers = [];
        foreach ($this->contextFibers[$context] ?? [] as $fiber => $_) {
            $fibers[] = $fiber;
        }
        foreach ($this->outerContexts as $inner => $outer) {
            if ($outer === $context) {
                \array_push($fibers, ...$this->getFibers($inner));
            }
        }

        return $fibers;
    }

    /**
     * Cancel every coroutine of $context and of the contexts nested in it (getFibers()), the
     * deepest in the tree of coroutines first, except the coroutine that cancels. Coroutines that
     * aren't waiting, because they run or are about to, are left to finish.
     */
    public function cancelContext(object $context, ?\Throwable $exception = null): void
    {
        $depths = [];
        foreach ($this->getFibers($context) as $fiber) {
            if ($fiber === $this->currentFiber || $fiber->isTerminated()) {
                continue;
            }
            for ($depth = 0, $f = $fiber; null !== ($f = $this->parentFibers[$f] ?? null); ++$depth) {
            }
            $depths[] = [$depth, $fiber];
        }
        \usort($depths, static fn (array $a, array $b) => $b[0] <=> $a[0]);
        foreach ($depths as [, $fiber]) {
            if (isset($this->pending[$fiber])) {
                $this->cancel($fiber, $exception);
            }
        }
    }

    /** Mark $context used, entered from $outer: ContextUsedException if it was used before. */
    private function useContext(object $context, ?object $outer): void
    {
        if (isset($this->usedContexts[$context])) {
            throw new ContextUsedException();
        }
        $this->usedContexts[$context] = true;
        if (null !== $outer) {
            $this->outerContexts[$context] = $outer;
        }
        if (!isset($this->rootContexts[$context])) { // a run()'s context is set by beginRun()
            $this->rootContexts[$context] = null === $outer || isset($this->runContexts[$outer]) ? $context : $this->rootContexts[$outer];
        }
    }

    private function joinContext(object $context, \Fiber $fiber): void
    {
        if (!isset($this->contextFibers[$context])) {
            $this->contextFibers[$context] = new \WeakMap();
        }
        $this->contextFibers[$context][$fiber] = true;
    }

    /**
     * The root context of $context: the one below the nearest run()'s context that $context was
     * entered from, or $context itself. See phasync::getRootContext().
     */
    public function getRootContext(object $context): object
    {
        return $this->rootContexts[$context];
    }

    /** A coroutine left $context: a root with none left drops its self-reference. */
    private function leftContext(object $context): void
    {
        // Cheapest first: coroutines of a run()'s context (most) leave it without this
        if (!isset($this->runContexts[$context]) && ($this->rootContexts[$context] ?? null) === $context && 0 === \count($this->contextFibers[$context])) {
            unset($this->rootContexts[$context]);
        }
    }

    /** $context's coroutine runs next, and another switch-aware context's ran last. */
    private function makeLive(SwitchAwareInterface $context): void
    {
        $was               = $this->liveContext;
        $this->liveContext = $context;
        $was?->suspend();
        $context->resume();
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
            if ([] !== $this->timeoutSlots) {
                $this->removeTimeout($fiber);
            }

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
    public function getCurrentContext(): ?object
    {
        return $this->currentContext;
    }

    /**
     * Cancel, with TimeoutException, the coroutines whose timeout falls in a slot up to $slot:
     * everything in those buckets has expired.
     */
    private function checkTimeouts(int $slot): void
    {
        $from                  = $this->lastTimeoutSlot + 1;
        $this->lastTimeoutSlot = $slot;
        if ([] === $this->timeoutBuckets) {
            return;
        }
        if ($slot - $from < 64) {
            for ($s = $from; $s <= $slot; ++$s) {
                if (isset($this->timeoutBuckets[$s])) {
                    $this->expire($s);
                }
            }
        } else {
            foreach ($this->timeoutBuckets as $s => $_) { // a long gap (an idle or busy loop)
                if ($s <= $slot) {
                    $this->expire($s);
                }
            }
        }
    }

    private function expire(int $slot): void
    {
        $fibers = $this->timeoutBuckets[$slot];
        unset($this->timeoutBuckets[$slot]);
        foreach ($fibers as $id => $fiber) {
            unset($this->timeoutSlots[$id]);
            // FiberState::for($fiber)->log("timeout");
            $this->cancel($fiber, new TimeoutException('Operation timed out for ' . Debug::getDebugInfo($fiber)));
        }
    }

    private function addTimeout(\Fiber $fiber, float $deadline): void
    {
        // A positive timeout always lands after the last slot handled (it is at most now)
        $slot                             = (int) \ceil($deadline * 100);
        $id                               = \spl_object_id($fiber);
        $this->timeoutBuckets[$slot][$id] = $fiber;
        $this->timeoutSlots[$id]          = $slot;
    }

    /** $fiber no longer waits (it was woken or cancelled): its timeout goes. */
    private function removeTimeout(\Fiber $fiber): void
    {
        $id = \spl_object_id($fiber);
        if (isset($this->timeoutSlots[$id])) {
            $slot = $this->timeoutSlots[$id];
            unset($this->timeoutSlots[$id], $this->timeoutBuckets[$slot][$id]);
            if ([] === $this->timeoutBuckets[$slot]) {
                unset($this->timeoutBuckets[$slot]);
            }
        }
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

        return FiberExceptionHolder::create($exception, $fiber, function (\Throwable $exception, \WeakReference $fiberRef) use ($context) {
            // Nobody took the failure: it goes to the context's handler, or fails its run()
            $this->unhandled($context, $exception);
        });
    }

    /**
     * A failure no coroutine took: to the nearest ExceptionHandlerInterface among $context and
     * the contexts it is nested in, else to the log. Cancellations while PHP shuts down (it
     * destroys the coroutines still waiting) are no failures.
     */
    private function unhandled(object $context, \Throwable $exception): void
    {
        if (self::$exiting && $exception instanceof CancelledException) {
            return;
        }
        for ($c = $context; null !== $c; $c = $this->outerContexts[$c] ?? null) {
            if ($c instanceof ExceptionHandlerInterface) {
                // This may run in a destructor: the handler runs in the loop, from the next tick
                $this->defer(static fn () => $c->handleException($exception));

                return;
            }
            if (isset($this->runContexts[$c])) {
                $this->failRun($c, $exception);

                return;
            }
        }
        if (null === $this->rootRunContext) {
            // Its run() ended before the failure was known (the Fiber object was kept): it is
            // thrown where the last reference goes, rather than lost
            throw $exception;
        }
        // A service's, which belongs to no run(): the outermost one's
        $this->failRun($this->rootRunContext, $exception);
    }

    /**
     * A phasync::run() is in progress with $context: failures no handler takes fail it.
     *
     * @internal phasync::run()
     */
    public function beginRun(object $context, bool $root): void
    {
        $this->runContexts[$context]  = [];
        $this->rootContexts[$context] = $context;
        if ($root) {
            $this->rootRunContext = $context;
        }
    }

    /**
     * The phasync::run() with $context ends: the failures that reached it, if any. Its coroutines
     * were dropped by the loop when the first arrived (failRun()).
     *
     * @internal phasync::run()
     *
     * @return list<\Throwable>
     */
    public function endRun(object $context): array
    {
        $failures = $this->runContexts[$context] ?? [];
        unset($this->runContexts[$context], $this->rootContexts[$context]);
        if ($context === $this->rootRunContext) {
            $this->rootRunContext = null;
        }

        return $failures;
    }

    /**
     * A failure no handler took reached the run() with $context: the run fails. Its coroutines,
     * those of the contexts nested in it, and for the outermost run() the services, are dropped
     * by the loop at once and never resumed: PHP destroys them as their last references go
     * (running their finally blocks, which can't suspend any more). run() then throws.
     */
    private function failRun(object $context, \Throwable $exception): void
    {
        $failures                    = $this->runContexts[$context];
        $failures[]                  = $exception;
        $this->runContexts[$context] = $failures;
        if (1 === \count($failures)) {
            // This may run in a destructor, and the loop may be iterating: from the next tick
            $this->defer(fn () => $this->tearDown($context));
        }
    }

    /** Drop the coroutines of the failed run() with $context: see failRun(). */
    private function tearDown(object $context): void
    {
        if (!isset($this->runContexts[$context])) {
            return; // the run ended meanwhile: its coroutines had all finished
        }
        $fibers = $this->getFibers($context);
        if ($context === $this->rootRunContext) {
            \array_push($fibers, ...$this->getFibers($this->serviceContext));
        }
        foreach ($fibers as $fiber) {
            if ($fiber->isTerminated() || $fiber === $this->currentFiber) {
                continue;
            }
            $this->discard($fiber);
            $context = $this->contexts[$fiber];
            if (0 !== $this->preempted && ($this->frozen[$root = $this->rootContexts[$context]] ?? null) === $fiber) {
                $this->thaw($root); // its held coroutines share its root: they go with this run too
            }
            unset($this->contextFibers[$context][$fiber]);
            $this->leftContext($context);
            unset($this->contexts[$fiber], $this->parentFibers[$fiber], $this->flagGraph[$fiber], $this->fiberExceptionHolders[$fiber]);
        }
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
        unset($this->contextFibers[$context][$fiber]);
        $this->leftContext($context);
        unset($this->contexts[$fiber], $this->parentFibers[$fiber]);
        $this->shouldGarbageCollect = true;
    }
}
