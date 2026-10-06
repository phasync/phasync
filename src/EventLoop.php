<?php

namespace phasync;

use Fiber;
use phasync\Context\ContextFactoryInterface;
use phasync\Context\ExceptionHandlerInterface;
use phasync\Context\SwitchAwareInterface;
use phasync\Internal\ContextState;
use phasync\Internal\DeadmanException;
use phasync\Internal\Debug;
use phasync\Internal\ExceptionTool;
use phasync\Internal\FiberExceptionHolder;
use phasync\Internal\Flag;
use phasync\Internal\LazyContext;
use phasync\Internal\Scheduler;
use WeakMap;

/**
 * phasync's event loop: runs coroutines, and waits for timers, flags and (through its poller)
 * streams.
 *
 * @internal not part of the public API, except getSlot(), park() and unpark() as documented for phasync::getLoop(); may change in any release
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
     * The fibers of each context: the fiber itself while it is the only one (a request's context),
     * a set once another joins.
     *
     * @var \WeakMap<object, \Fiber|\WeakMap<\Fiber, true>>
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
     * The contexts another was entered from: the others have no coroutine but their own, and
     * need no search for nested ones.
     *
     * @var \WeakMap<object, true>
     */
    private \WeakMap $hasInner;

    /**
     * The contexts of the phasync::run() calls in progress, with the failures that reached each:
     * a failure no handler took fails the nearest run() it is nested in.
     *
     * @var \WeakMap<object, list<\Throwable>>
     */
    private \WeakMap $runContexts;

    /**
     * Cancelled coroutines and contexts, with the exception each cancellation throws: sticky, so
     * every wait of a cancelled coroutine, or of one in a cancelled context (or a context nested
     * in it), throws it until the coroutine ends or leaves the context.
     *
     * @var \WeakMap<\Fiber, \Throwable>
     */
    private \WeakMap $cancelledFibers;

    /** @var \WeakMap<object, \Throwable> */
    private \WeakMap $cancelledContexts;

    /**
     * How many coroutines and contexts are cancelled: 0 keeps the check off every wait.
     *
     * @internal
     */
    public int $cancellations = 0;

    /**
     * Coroutines whose waits no cancellation reaches: those running phasync::finally() callbacks.
     *
     * @var \WeakMap<\Fiber, int>
     */
    private \WeakMap $shielded;

    /**
     * The cancellation each failed run() sent through its scope, with its failure as the previous
     * exception: the coroutines unwinding from it are no further failures.
     *
     * @var \WeakMap<object, CancelledException>
     */
    private \WeakMap $runCancellations;

    /**
     * The cancellations cancel() and a failed run() issued: a coroutine that ends with one of
     * them was cancelled, it did not fail.
     *
     * @var \WeakMap<\Throwable, true>
     */
    private \WeakMap $issued;

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
     * phasync::finally() callbacks of the coroutines that registered them outside any
     * withContext() call; run when the coroutine ends.
     *
     * @var \WeakMap<\Fiber, list<\Closure>>
     */
    private \WeakMap $fiberFinally;

    /**
     * The withContext() calls given a factory whose context does not exist yet, by spl_object_id()
     * of the fiber: the fiber's context is still the one it entered the call with.
     *
     * @var array<int, LazyContext>
     */
    private array $lazyContexts = [];

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
     * This WeakMap traces which flag a fiber is waiting for.
     *
     * @var \WeakMap<\Fiber, object>
     */
    public \WeakMap $flagGraph;

    /**
     * Callbacks to be invoked between fibers.
     *
     * @var \SplQueue<\Closure>
     */
    private \SplQueue $callbackQueue;

    /** When the loop last counted the possible cycles, see tick(). */
    private float $lastGarbageCheck = 0;

    /** When the loop last collected cycles, by either of the two ways tick() does it. */
    private float $lastGarbageCollect = 0;

    /** How long a coroutine runs in a PHP loop before it yields to other requests (phasync-ext). */
    public const PREEMPT_INTERVAL = 0.02;

    /**
     * How often the loop counts the possible cycles for the busy-loop safety net (tick()), and
     * how many make it collect: a loop that never idles makes garbage while nothing ends (a
     * server's connections), so this is the fallback for when idle-time collection (also tick(),
     * right before the poller waits) never gets the chance to run.
     */
    private const GC_CHECK_INTERVAL = 0.05;
    private const GC_ROOTS          = 40_000;
    private const GC_ROOTS_MAX      = 4_000_000;

    /** The longest a busy loop goes without collecting, while there's anything to collect. */
    private const GC_MAX_INTERVAL = 0.5;

    /** The possible cycles that make the loop collect now: GC_ROOTS, raised while collections find nothing. */
    private int $gcRoots = self::GC_ROOTS;

    private \stdClass $serviceContext;

    /** PHP is shutting down: coroutines cancelled by it did not fail. */
    private static bool $exiting = false;

    private \stdClass $afterNextFlag;

    private ?\Fiber $currentFiber             = null;
    private ?object $currentContext           = null;

    /** The switch-aware context whose coroutine ran last: see SwitchAwareInterface. */
    private ?SwitchAwareInterface $liveContext = null;

    /** Whether a switch-aware context was ever used: until then, switches check nothing. */
    private bool $switchAware = false;

    /** Whether context-local state is on (see phasync::enableContextState()): until then, switches check this only. */
    private bool $stateOn = false;

    /** The context whose state phasync::$contextState is, while the state is on. */
    private ?object $stateContext = null;

    /**
     * The context-local state of each context seen since it was turned on.
     *
     * @var \WeakMap<object, ContextState>
     */
    private \WeakMap $states;

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
        // What a failure needs, loaded now: without file descriptors left, it can't be autoloaded
        foreach ([AggregateException::class, CancelledException::class, ChannelException::class, ContextUsedException::class,
            DeadmanException::class, IOException::class, TimeoutException::class, Debug::class,
            ExceptionTool::class, FiberExceptionHolder::class] as $class) {
            \class_exists($class);
        }
        $this->clear();
    }

    /**
     * Clear the event loop driver, removing any scheduled fibers etc.
     */
    public function clear(): void
    {
        $this->states            = new \WeakMap();
        $this->contexts          = new \WeakMap();
        $this->contextFibers     = new \WeakMap();
        $this->outerContexts     = new \WeakMap();
        $this->hasInner          = new \WeakMap();
        $this->usedContexts      = new \WeakMap();
        $this->rootContexts      = new \WeakMap();
        $this->frozen            = new \WeakMap();
        $this->held              = new \WeakMap();
        $this->preempted         = 0;
        $this->runContexts       = new \WeakMap();
        $this->cancelledFibers   = new \WeakMap();
        $this->cancelledContexts = new \WeakMap();
        $this->cancellations     = 0;
        $this->shielded          = new \WeakMap();
        $this->runCancellations  = new \WeakMap();
        $this->issued            = new \WeakMap();
        if ($fiber = $this->getCurrentFiber()) {
            // The current fiber stays, alone in its context
            $context                               = $this->currentContext;
            $this->contexts[$fiber]                = $context;
            $this->contextFibers[$context]         = $fiber;
            $this->usedContexts[$context]          = true;
            $this->rootContexts[$context]          = $context;
        }
        $this->queue                               = new \SplQueue();
        $this->pending                             = new \SplObjectStorage();
        $this->parentFibers                        = new \WeakMap();
        $this->withContextFinally                  = [];
        $this->fiberFinally                        = new \WeakMap();
        $this->lazyContexts                        = [];
        $this->fiberExceptionHolders               = new \WeakMap();
        $this->fiberExceptions                     = new \WeakMap();
        $this->scheduler                           = new Scheduler();
        $this->timeoutBuckets                      = [];
        $this->timeoutSlots                        = [];
        $this->lastTimeoutSlot                     = (int) (\microtime(true) * 100);
        $this->flaggedFibers                       = new \WeakMap();
        $this->flagGraph                           = new \WeakMap();
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
        // A clean start: whatever possible cycles gathered while the loop was being built are
        // collected now, not counted toward the thresholds below.
        \gc_collect_cycles();
        $this->lastGarbageCollect = \microtime(true);
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
        $maxSleepTime = 0 === $queue->count() && $this->callbackQueue->isEmpty() ? 0.5 : 0;

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

        if ($maxSleepTime > 0 && $afterNextCount > 0 && 0 === $queue->count() && $this->scheduler->isEmpty()) {
            $maxSleepTime = 0;
        }

        if ($maxSleepTime > 0 && \gc_status()['roots'] > 0) {
            // Nothing is runnable: the loop is about to wait in the poller. Collect now, while it
            // would otherwise sit idle, so the root buffer never grows large and collections stay
            // cheap. No minimum-wait guard: even a wait shorter than the collection hides part of
            // its cost, so every idle opportunity is taken.
            \gc_collect_cycles();
            $this->lastGarbageCollect = $now;
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
                if ($this->stateOn && $this->currentContext !== $this->stateContext) {
                    $this->liveState($this->currentContext);
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

        if ($now - $this->lastGarbageCheck > self::GC_CHECK_INTERVAL) {
            // A busy loop (always some coroutine ready, never idle, see tick()'s poll() above)
            // never gets the idle-time collection above: this is its safety net, checked between
            // fibers as always. Either of two things forces a collection: too long has passed
            // since the last one (of any kind) while there's anything to collect, or as many
            // possible cycles have gathered as make PHP's own collector run.
            $this->lastGarbageCheck = $now;
            $roots                  = \gc_status()['roots'];
            if (($roots > 0 && $now - $this->lastGarbageCollect > self::GC_MAX_INTERVAL) || $roots >= $this->gcRoots) {
                // As PHP's collector adapts: a collection that finds (almost) nothing makes the next
                // wait for more possible cycles, one that finds garbage brings the threshold back
                $this->gcRoots            = \gc_collect_cycles() < 100 ? \min($this->gcRoots * 2, self::GC_ROOTS_MAX) : self::GC_ROOTS;
                $this->lastGarbageCollect = $now;
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
                    \array_push($held, ...$this->held[$root]);
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
        $held = $this->held[$root];
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
            if ($this->stateOn && $this->currentContext !== $this->stateContext) {
                $this->liveState($this->currentContext);
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
        $fiber = \Fiber::getCurrent();
        if (null === $fiber || !isset($this->contexts[$fiber])) {
            return; // not in a coroutine: the loop's own tick, or a Fiber of the coroutine's own
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
        $this->held[$root]   = [];
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
        $currentFiber = $this->currentFiber;
        if (null !== $currentFiber && isset($this->lazyContexts[$id = \spl_object_id($currentFiber)])) {
            $this->enterLazyContext($currentFiber, $id);
        }
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
            if ($this->stateOn && $context !== $this->stateContext) {
                $this->liveState($context);
            }
            $fiber->start(...$args);
        } catch (\Throwable $e) {
            // $e = ExceptionTool::popTrace($e, __FILE__);
            $this->fiberExceptionHolders[$fiber] = $this->makeExceptionHolder($e, $fiber);
        } finally {
            $this->currentFiber   = $currentFiber;
            $this->currentContext = $currentContext;
            if ($this->switchAware && null !== $currentFiber && $currentContext instanceof SwitchAwareInterface && $currentContext !== $this->liveContext) {
                $this->makeLive($currentContext); // the creating coroutine goes on
            }
            if ($this->stateOn && null !== $currentFiber && $currentContext !== $this->stateContext) {
                $this->liveState($currentContext); // likewise
            }
            if ($fiber->isTerminated()) {
                $this->handleTerminatedFiber($fiber);
            }
        }
        if (0 !== $this->preempted && null !== $currentFiber && isset($this->frozen[$root = $this->rootContexts[$currentContext]])) {
            // The new coroutine was preempted before it first suspended: its creator is of the
            // same frozen root, so it waits too (go() returns once the root thaws)
            $this->pending[$currentFiber] = \PHP_FLOAT_MAX;
            $this->held[$root][]          = $currentFiber;
            \Fiber::suspend();
        }

        return $fiber;
    }

    /**
     * The context of $fiber.
     */
    public function getContext(\Fiber $fiber): ?object
    {
        if ($fiber === $this->currentFiber && isset($this->lazyContexts[$id = \spl_object_id($fiber)])) {
            $this->enterLazyContext($fiber, $id);
        }

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
        if (0 !== $this->cancellations) {
            $this->checkCancelled($fiber);
        }
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
     *
     * Given a {@see ContextFactoryInterface} instead of a context, $fn runs in the coroutine's own
     * context until it needs the context (see enterLazyContext()); a $fn that never does costs no
     * context.
     */
    public function withContext(\Closure $fn, object $context): mixed
    {
        $fiber = $this->currentFiber ?? throw ExceptionTool::popTrace(ExceptionTool::popTrace(new \LogicException('withContext() runs only inside a coroutine')));
        $id    = \spl_object_id($fiber);
        if (isset($this->lazyContexts[$id])) {
            $this->enterLazyContext($fiber, $id); // this context is entered from the one being awaited
        }
        if ($context instanceof ContextFactoryInterface) {
            $lazy    = $this->lazyContexts[$id] = new LazyContext($context);
            $failure = null;
            try {
                return $fn();
            } catch (\Throwable $e) {
                throw $failure = $e;
            } finally {
                if (null === $lazy->context) {
                    unset($this->lazyContexts[$id]);
                } else {
                    $this->leaveContext($fiber, $id, $lazy->context, $lazy->outerFinally, $failure);
                }
            }
        }
        $outerFinally = $this->enterContext($fiber, $id, $context);
        $failure      = null;
        try {
            return $fn();
        } catch (\Throwable $e) {
            throw $failure = $e;
        } finally {
            $this->leaveContext($fiber, $id, $context, $outerFinally, $failure);
        }
    }

    /**
     * The running coroutine needs the context of the withContext() call it is in, which was given
     * a factory: create it and enter it, as withContext() enters a context it is given.
     */
    private function enterLazyContext(\Fiber $fiber, int $id): void
    {
        $lazy               = $this->lazyContexts[$id];
        $context            = $lazy->factory->createContext();
        $lazy->outerFinally = $this->enterContext($fiber, $id, $context);
        $lazy->context      = $context;
        unset($this->lazyContexts[$id]);
    }

    /**
     * The running $fiber enters $context from its own: useContext() and joinContext(), inline
     * (a request's context passes here once per request). Returns the phasync::finally() list of
     * the withContext() call it was in, for leaveContext().
     *
     * @return list<\Closure>|null
     */
    private function enterContext(\Fiber $fiber, int $id, object $context): ?array
    {
        $previous = $this->contexts[$fiber];
        if (isset($this->usedContexts[$context])) {
            throw new ContextUsedException();
        }
        $this->usedContexts[$context]   = true;
        $this->outerContexts[$context]  = $previous;
        $this->hasInner[$previous]      = true;
        if (!isset($this->rootContexts[$context])) {
            $this->rootContexts[$context] = isset($this->runContexts[$previous]) ? $context : $this->rootContexts[$previous];
        }
        $outerFinally                   = $this->withContextFinally[$id] ?? null;
        $this->contexts[$fiber]         = $context;
        $this->currentContext           = $context;
        $this->contextFibers[$context]  = $fiber;
        if ($context instanceof SwitchAwareInterface && $context !== $this->liveContext) {
            $this->switchAware = true;
            $this->makeLive($context);
        }
        if ($this->stateOn && $context !== $this->stateContext) {
            $this->liveState($context);
        }
        $this->withContextFinally[$id]  = [];

        return $outerFinally;
    }

    /**
     * The running $fiber leaves $context, which it entered with enterContext(): the
     * phasync::finally() callbacks registered in it run first, and the context it came from is
     * restored.
     *
     * @param list<\Closure>|null $outerFinally
     */
    private function leaveContext(\Fiber $fiber, int $id, object $context, ?array $outerFinally, ?\Throwable $failure): void
    {
        $callbacks = $this->withContextFinally[$id];
        try {
            if ([] !== $callbacks) {
                // phasync::finally() callbacks registered in $fn, last first, still in $context,
                // and shielded: they complete also when $context was cancelled
                $this->shield($fiber);
                try {
                    self::runFinally($callbacks);
                } finally {
                    $this->unshield($fiber);
                }
            }
            $this->endScope($fiber, $context, $failure);
        } finally {
            if (null === $outerFinally) {
                unset($this->withContextFinally[$id]);
            } else {
                $this->withContextFinally[$id] = $outerFinally;
            }
            $previous = $this->outerContexts[$context];
            $this->leftContext($context, $fiber);
            $this->contexts[$fiber] = $previous;
            $this->currentContext   = $previous;
            if ($this->switchAware && $previous instanceof SwitchAwareInterface && $previous !== $this->liveContext) {
                $this->makeLive($previous);
            }
            if ($this->stateOn && $previous !== $this->stateContext) {
                $this->liveState($previous);
            }
        }
    }

    /**
     * withContext() returns only when the coroutines of $context have ended, as run() does. When
     * $fn failed, or the caller is cancelled while waiting, they are cancelled and waited for
     * (shielded: they must unwind) before the failure goes on.
     */
    private function endScope(\Fiber $fiber, object $context, ?\Throwable $failure): void
    {
        if (self::$exiting) {
            return; // PHP destroys the suspended coroutines, which unwind here: a destroyed one can't wait
        }
        $members = $this->contextFibers[$context] ?? null;
        if ((null === $members || $members === $fiber) && !isset($this->hasInner[$context])) {
            return; // the caller alone: a request that started nothing (most)
        }
        $cancelled = null;
        if (null === $failure) {
            try {
                $this->awaitContext($context, \PHP_FLOAT_MAX);

                return;
            } catch (CancelledException $e) {
                $failure = $cancelled = $e;
            }
        }
        $this->cancelContext($context, new CancelledException('Cancelled: the context ended with ' . \get_class($failure) . ': ' . $failure->getMessage(), 0, $failure), false);
        $this->shield($fiber);
        try {
            $this->awaitContext($context, \PHP_FLOAT_MAX);
        } finally {
            $this->unshield($fiber);
        }
        if (null !== $cancelled) {
            throw $cancelled;
        }
    }

    /**
     * Wait until every coroutine of $context, and of the contexts nested in it, has ended; see
     * phasync::awaitContext().
     *
     * @throws TimeoutException
     */
    public function awaitContext(object $context, float $timeout): void
    {
        $caller   = $this->currentFiber ?? throw ExceptionTool::popTrace(new \LogicException('Can only await a context from within a coroutine'));
        $deadline = \microtime(true) + $timeout;
        while (true) {
            $pending = null;
            foreach ($this->getFibers($context) as $fiber) {
                if ($fiber !== $caller && !$fiber->isTerminated()) {
                    $pending = $fiber;
                    break;
                }
            }
            if (null === $pending) {
                return;
            }
            \phasync::awaitFlag($pending, \max(0.0, $deadline - \microtime(true)));
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
        $fibers  = [];
        $members = $this->contextFibers[$context] ?? null;
        if ($members instanceof \Fiber) {
            $fibers[] = $members;
        } elseif (null !== $members) {
            foreach ($members as $fiber => $_) {
                $fibers[] = $fiber;
            }
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
    public function cancelContext(object $context, ?\Throwable $exception = null, bool $throwInCaller = true): void
    {
        $exception ??= new CancelledException('Operation cancelled');
        $this->issued[$exception] = true;
        if (!isset($this->cancelledContexts[$context])) {
            ++$this->cancellations;
        }
        $this->cancelledContexts[$context] = $exception;
        $depths                            = [];
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
            $this->wake($fiber);
        }
        if ($throwInCaller && null !== ($caller = \Fiber::getCurrent()) && isset($this->contexts[$caller]) && null !== ($e = $this->cancellationFor($caller))) {
            throw $e; // the caller is in it: as a throw
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
            $this->hasInner[$outer]        = true;
        }
        if (!isset($this->rootContexts[$context])) { // a run()'s context is set by beginRun()
            $this->rootContexts[$context] = null === $outer || isset($this->runContexts[$outer]) ? $context : $this->rootContexts[$outer];
        }
    }

    private function joinContext(object $context, \Fiber $fiber): void
    {
        $members = $this->contextFibers[$context] ?? null;
        if (null === $members) {
            $this->contextFibers[$context] = $fiber;
        } elseif ($members instanceof \Fiber) {
            $this->contextFibers[$context]           = new \WeakMap();
            $this->contextFibers[$context][$members] = true;
            $this->contextFibers[$context][$fiber]   = true;
        } else {
            $members[$fiber] = true;
        }
    }

    /**
     * The root context of $context: the one below the nearest run()'s context that $context was
     * entered from, or $context itself. See phasync::getRootContext().
     */
    public function getRootContext(object $context): object
    {
        return $this->rootContexts[$context];
    }

    /** $fiber left $context: a root with none left drops its self-reference (the service context is kept: the driver holds it, and a later service needs its root). */
    private function leftContext(object $context, \Fiber $fiber): void
    {
        $members = $this->contextFibers[$context];
        if ($members instanceof \Fiber) {
            unset($this->contextFibers[$context]);
        } else {
            unset($members[$fiber]);
            if (0 !== \count($members)) {
                return;
            }
        }
        // Cheapest first: coroutines of a run()'s context (most) leave it without this
        if (!isset($this->runContexts[$context]) && ($this->rootContexts[$context] ?? null) === $context && $context !== $this->serviceContext) {
            unset($this->rootContexts[$context]);
        }
        if (0 !== $this->cancellations && isset($this->cancelledContexts[$context])) {
            // No coroutine can join a context that has none left
            unset($this->cancelledContexts[$context]);
            --$this->cancellations;
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

    /** $context's coroutine runs next: phasync::$contextState becomes its array, a copy of the defaults at first. */
    private function liveState(object $context): void
    {
        $this->stateContext     = $context;
        $holder                 = $this->states[$context] ??= new ContextState(\phasync::$contextStateDefaults);
        \phasync::$contextState = &$holder->a;
    }

    /**
     * Turn context-local state on; the running coroutine's context gets its array at once.
     */
    public function enableContextState(): void
    {
        if (!$this->stateOn) {
            $this->stateOn = true;
            if (null !== $this->currentContext) {
                $this->liveState($this->currentContext);
            }
        }
    }

    /**
     * Make $state, by reference, the context-local state of the running coroutine's context (which a
     * lazy withContext() creates now), and of the loop's live state at once. Outside a coroutine it
     * only becomes phasync::$contextState.
     */
    public function adoptContextState(array &$state): void
    {
        $this->stateOn = true;
        $context       = $this->getCurrentContext();
        if (null !== $context) {
            $holder             = $this->states[$context] ??= new ContextState([]);
            $holder->a          = &$state;
            $this->stateContext = $context;
        }
        \phasync::$contextState = &$state;
    }

    /**
     * Register a phasync::finally() callback: it runs as the withContext() call the running
     * coroutine is in returns, or when the coroutine ends when it is in none.
     *
     * @internal
     */
    public function finally(\Closure $fn): void
    {
        $fiber = $this->currentFiber ?? throw ExceptionTool::popTrace(ExceptionTool::popTrace(new \LogicException('This function can not be used outside of a coroutine')));
        $id    = \spl_object_id($fiber);
        if (isset($this->lazyContexts[$id])) {
            $this->enterLazyContext($fiber, $id);
        }
        if (isset($this->withContextFinally[$id])) {
            $this->withContextFinally[$id][] = $fn;

            return;
        }
        $callbacks                  = $this->fiberFinally[$fiber] ?? [];
        $callbacks[]                = $fn;
        $this->fiberFinally[$fiber] = $callbacks;
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
     * Sticky cancellation of $fiber with $exception, see phasync::cancel().
     *
     * @internal
     *
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
        $exception ??= new CancelledException('Operation cancelled');
        $this->issued[$exception] = true;
        if (!isset($this->cancelledFibers[$fiber])) {
            ++$this->cancellations;
        }
        $this->cancelledFibers[$fiber] = $exception;
        if ($fiber === \Fiber::getCurrent()) {
            throw $exception; // cancelling itself: as a throw
        }
        $this->wake($fiber);
    }

    /**
     * Interrupt the wait of $fiber with $exception, once; see phasync::throw().
     *
     * @throws \LogicException
     */
    public function throw(\Fiber $fiber, \Throwable $exception): void
    {
        if (!isset($this->contexts[$fiber])) {
            throw new \LogicException('The fiber (' . Debug::getDebugInfo($fiber) . ') is not a phasync fiber');
        }
        $this->issued[$exception] = true;
        if ($fiber === \Fiber::getCurrent()) {
            throw $exception;
        }
        if (!$this->deliver($fiber, $exception)) {
            throw new \LogicException('The coroutine (' . Debug::getDebugInfo($fiber) . ') is not waiting');
        }
    }

    /**
     * A waiting coroutine a cancellation covers resumes with it. One that was preempted, or is
     * shielded, goes on: it meets the cancellation at its next wait.
     */
    private function wake(\Fiber $fiber): void
    {
        if (null !== ($exception = $this->cancellationFor($fiber))) {
            $this->deliver($fiber, $exception);
        }
    }

    /**
     * Resume $fiber with $exception where it waits; false when it does not wait, is preempted
     * or shielded, or has an exception on its way already.
     */
    private function deliver(\Fiber $fiber, \Throwable $exception): bool
    {
        if (!isset($this->pending[$fiber]) || isset($this->fiberExceptionHolders[$fiber]) || isset($this->shielded[$fiber])) {
            return false;
        }
        if (0 !== $this->preempted && ($this->frozen[$this->rootContexts[$this->contexts[$fiber]]] ?? null) === $fiber) {
            return false;
        }
        if (!$this->discard($fiber)) {
            return false;
        }
        $this->enqueueWithException($fiber, $exception);

        return true;
    }

    /** The cancellation $fiber's waits throw, if any. */
    private function cancellationFor(\Fiber $fiber): ?\Throwable
    {
        if (isset($this->shielded[$fiber])) {
            return null;
        }
        if (null !== ($exception = $this->cancelledFibers[$fiber] ?? null)) {
            return $exception;
        }
        for ($c = $this->contexts[$fiber] ?? null; null !== $c; $c = $this->outerContexts[$c] ?? null) {
            if (null !== ($exception = $this->cancelledContexts[$c] ?? null)) {
                return $exception;
            }
        }

        return null;
    }

    /**
     * At a wait: a cancelled coroutine throws its cancellation instead of waiting (callers check
     * $cancellations first), and what it registered to wait for is dropped.
     *
     * @internal
     */
    public function checkCancelled(\Fiber $fiber): void
    {
        if (null !== ($exception = $this->cancellationFor($fiber))) {
            $this->discard($fiber);

            throw $exception;
        }
    }

    /**
     * $fiber's waits are out of every cancellation's reach until unshield(): phasync::finally()
     * callbacks complete also in a cancelled coroutine or context.
     *
     * @internal
     */
    public function shield(\Fiber $fiber): void
    {
        $this->shielded[$fiber] = ($this->shielded[$fiber] ?? 0) + 1;
    }

    /** @internal */
    public function unshield(\Fiber $fiber): void
    {
        if (0 === --$this->shielded[$fiber]) {
            unset($this->shielded[$fiber]);
        }
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

            // Held while its root is frozen
            if (0 !== $this->preempted && isset($this->held[$root = $this->rootContexts[$this->contexts[$fiber]]])
                && false !== ($i = \array_search($fiber, $held = $this->held[$root], true))) {
                \array_splice($held, $i, 1);
                $this->held[$root] = $held;
                $cancelled         = true;
                break;
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
            if ($eh->ended) {
                throw new \LogicException("Can't await a coroutine whose failure ended its run()");
            }
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
        if (null !== ($fiber = $this->currentFiber) && isset($this->lazyContexts[$id = \spl_object_id($fiber)])) {
            $this->enterLazyContext($fiber, $id);
        }

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
            // One wait timed out: thrown once, not sticky as a cancellation
            if ($this->discard($fiber)) {
                $this->enqueueWithException($fiber, new TimeoutException('Operation timed out for ' . Debug::getDebugInfo($fiber)));
            }
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
        }, $context);
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
        if ($this->isCancellation($exception)) {
            return; // it was cancelled; it did not fail
        }
        for ($c = $context; null !== $c; $c = $this->outerContexts[$c] ?? null) {
            if ($c instanceof ExceptionHandlerInterface) {
                // This may run in a destructor: the handler runs in the loop, from the next tick.
                // One that returns has handled it; what it throws goes on outward.
                $this->defer(function () use ($c, $exception) {
                    try {
                        $c->handleException($exception);
                    } catch (\Throwable $e) {
                        if (null !== ($outer = $this->outerContexts[$c] ?? null)) {
                            $this->unhandled($outer, $e);
                        } elseif (isset($this->runContexts[$c])) {
                            $this->failRun($c, $e);
                        } elseif (null !== $this->rootRunContext) {
                            $this->failRun($this->rootRunContext, $e);
                        } else {
                            throw $e;
                        }
                    }
                });

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

    /** Whether a phasync::run() is in progress. */
    public function isRunning(): bool
    {
        return null !== $this->rootRunContext;
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
    public function endRun(object $context, ?\Fiber $main = null): array
    {
        if (!isset($this->runContexts[$context])) {
            return []; // it has ended
        }
        // A failure nobody took is this run's, also while something still holds its coroutine;
        // run() takes its main coroutine's itself
        foreach ($this->fiberExceptionHolders as $fiber => $holder) {
            if ($fiber !== $main && !$holder->isHandled() && $this->within($holder->context, $context)) {
                $holder->ended = true;
                $holder->handleException();
            }
        }
        $failures = $this->runContexts[$context] ?? [];
        unset($this->runContexts[$context], $this->rootContexts[$context]);
        if (0 !== $this->cancellations) {
            // Its cancellation ends with it; the services' too, which outlive every run()
            foreach ($context === $this->rootRunContext ? [$context, $this->serviceContext] : [$context] as $c) {
                if (isset($this->cancelledContexts[$c])) {
                    unset($this->cancelledContexts[$c]);
                    --$this->cancellations;
                }
            }
        }
        if ($context === $this->rootRunContext) {
            $this->rootRunContext = null;
        }

        return $failures;
    }

    /** Whether $context is $outer or nested in it. */
    private function within(?object $context, object $outer): bool
    {
        for ($c = $context; null !== $c; $c = $this->outerContexts[$c] ?? null) {
            if ($c === $outer) {
                return true;
            }
        }

        return false;
    }

    /**
     * A failure no handler took reached the run() with $context: the run fails. Its first failure
     * cancels the run's scope (the coroutines of it and of the contexts nested in it, and for the
     * outermost run() the services): they unwind with a CancelledException whose previous
     * exception is that failure, and phasync::finally() callbacks complete. The cancellations they
     * throw on are no further failures; anything else they throw is. run() then throws.
     */
    private function failRun(object $context, \Throwable $exception): void
    {
        if (null !== ($cancellation = $this->runCancellations[$context] ?? null) && self::causedBy($exception, $cancellation)) {
            return;
        }
        $failures                    = $this->runContexts[$context];
        $failures[]                  = $exception;
        $this->runContexts[$context] = $failures;
        if (1 === \count($failures)) {
            // This may run in a destructor, and the loop may be iterating: from the next tick
            $this->defer(fn () => $this->cancelRun($context, $exception));
        }
    }

    /**
     * The run() with $context has failed with $failure: its scope is cancelled (see failRun()).
     * Also when its main coroutine fails, which run() itself then throws.
     *
     * @internal phasync::run()
     */
    public function cancelRun(object $context, \Throwable $failure): void
    {
        if (!isset($this->runContexts[$context]) || isset($this->runCancellations[$context])) {
            return;
        }
        $cancellation                     = new CancelledException('Cancelled: the run failed with ' . \get_class($failure) . ': ' . $failure->getMessage(), 0, $failure);
        $this->runCancellations[$context] = $cancellation;
        $this->cancelContext($context, $cancellation, false);
        if ($context === $this->rootRunContext) {
            $this->cancelContext($this->serviceContext, $cancellation, false);
        }
    }

    /**
     * Whether $exception comes from the cancellation of the run() with $context.
     *
     * @internal phasync::run()
     */
    public function isRunCancellation(object $context, \Throwable $exception): bool
    {
        return null !== ($cancellation = $this->runCancellations[$context] ?? null) && self::causedBy($exception, $cancellation);
    }

    /**
     * Whether $exception comes from a cancellation cancel() or a failed run() issued: a coroutine
     * ending with it was cancelled, it did not fail (CAN-9).
     *
     * @internal
     */
    public function isCancellation(\Throwable $exception): bool
    {
        if (0 !== $this->issued->count()) {
            for ($e = $exception; null !== $e; $e = $e->getPrevious()) {
                if (isset($this->issued[$e])) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function causedBy(\Throwable $exception, \Throwable $cause): bool
    {
        for ($e = $exception; null !== $e; $e = $e->getPrevious()) {
            if ($e === $cause) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whenever a fiber is terminated, this method must be used. It will ensure that any
     * deferred closures are immediately run. Cyclic garbage it leaves behind is collected by
     * tick()'s idle-time or busy-loop GC, not by this method.
     * If the Fiber threw an exception, ensure it is thrown in the parent if that is still
     * running, or.
     */
    private function handleTerminatedFiber(\Fiber $fiber): void
    {
        // FiberState::for($fiber)->log('handleTerminatedFiber');
        $context = $this->contexts[$fiber];
        if (isset($this->fiberFinally[$fiber])) {
            // The callbacks may suspend: they run in a coroutine of their own, in the context the
            // coroutine ended in (which waits for it), shielded so they complete also when that
            // context was cancelled
            $callbacks = $this->fiberFinally[$fiber];
            unset($this->fiberFinally[$fiber]);
            $outer                = $this->currentContext;
            $this->currentContext = $context;
            try {
                $this->create(function () use ($callbacks): void {
                    $fiber = $this->currentFiber;
                    $this->shield($fiber);
                    try {
                        $this->enqueue($fiber); // after whatever else the ending coroutine's tick has to do
                        \Fiber::suspend();
                        self::runFinally($callbacks);
                    } finally {
                        $this->unshield($fiber);
                    }
                });
            } finally {
                $this->currentContext = $outer;
                if ($this->stateOn && null !== $outer && $outer !== $this->stateContext) {
                    $this->liveState($outer);
                }
            }
        }
        if (0 !== $this->cancellations && isset($this->cancelledFibers[$fiber])) {
            unset($this->cancelledFibers[$fiber]);
            --$this->cancellations;
        }
        $this->raiseFlag($fiber);
        $this->leftContext($context, $fiber);
        unset($this->contexts[$fiber], $this->parentFibers[$fiber]);
    }
}
