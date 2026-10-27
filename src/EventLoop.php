<?php

namespace phasync;

use Fiber;
use phasync\Internal\DeadmanException;
use phasync\Internal\Debug;
use phasync\Internal\ExceptionTool;
use phasync\Internal\FiberExceptionHolder;
use phasync\Internal\Flag;
use phasync\Internal\Scheduler;
use WeakMap;

/**
 * phasync's event loop: runs coroutines, and waits for timers, flags and (through its poller)
 * streams.
 *
 * @internal not part of the public API, except getSlot(), park(), unpark() and tick() as documented for phasync::getLoop(); may change in any release
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
     * The frame of every coroutine created by this driver: that of the run() it was started in,
     * or of the nested run() it runs inline. Service coroutines have the service frame.
     *
     * @var \WeakMap<\Fiber, Frame>
     */
    private \WeakMap $frames;

    /**
     * The scope of every coroutine as it last suspended (at first, the one it was started in):
     * the scope that becomes current when the loop resumes it.
     *
     * @var \WeakMap<\Fiber, \Scope>
     */
    private \WeakMap $fiberScopes;

    /**
     * fiber-interop/scope polyfill only: fiber_interop\scope\generation() as of each coroutine's
     * last resume, read once its scope (fiberScopes[$fiber]) was settled by switchScope() for it.
     * suspending() skips Scope::getCurrent() and the write to fiberScopes when the generation has
     * not moved since: nothing changed the active scope, so fiberScopes[$fiber] is already right
     * (phasync#112). Unset means unknown (a freshly created fiber's first suspend): suspending()
     * falls back to Scope::getCurrent(), as it always did.
     *
     * @var \WeakMap<\Fiber, int>
     */
    private \WeakMap $fiberGenerations;

    /**
     * The scope the loop last made current (and reported to the onScopeChange() hooks): they
     * fire when the scope of the coroutine the loop runs next differs. A scope entered or left
     * with Scope::run() inside a coroutine is not seen until then.
     */
    private ?\Scope $liveScope = null;

    /** @var list<\Closure(\Scope, ?\Scope):void> phasync::onScopeChange() hooks */
    private array $scopeHooks = [];

    /**
     * While the loop makes a coroutine's scope current from outside it, that coroutine: the
     * polyfill's execution key (see the constructor). Null otherwise: one null check.
     */
    private ?\Fiber $scopeProbe = null;

    /** Whether \Scope is fiber-interop/scope's polyfill, whose superglobals the loop activates. */
    private bool $scopePolyfill = false;

    /**
     * Cancelled coroutines, with the exception each cancellation throws: sticky, so every wait
     * of a cancelled coroutine, or of one in a cancelled frame (or a frame nested in it), throws
     * it until the coroutine ends or leaves the frame.
     *
     * @var \WeakMap<\Fiber, \Throwable>
     */
    private \WeakMap $cancelledFibers;

    /**
     * How many coroutines and frames are cancelled: 0 keeps the check off every wait.
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
     * The cancellations cancel(), Frame::cancel() and a deadlock issued: a coroutine that ends
     * with one of them was cancelled, it did not fail.
     *
     * @var \WeakMap<\Throwable, true>
     */
    private \WeakMap $issued;

    /** The frame of the outermost phasync::run(). */
    private ?Frame $rootFrame = null;

    /** The frame of the services: no run() of its own, cancelled when the outermost run() returns. */
    private Frame $serviceFrame;

    /**
     * Makes a Frame, whose constructor is private: frames are only made here.
     *
     * @var \Closure(?Frame): Frame
     */
    private \Closure $newFrame;

    /**
     * Frames with a deadline (Frame::setTimeout()), by spl_object_id().
     *
     * @var array<int, Frame>
     */
    private array $deadlines = [];

    /** Counts failures, so that run() throws the earliest one nobody awaited. */
    private int $failureSeq = 0;

    /**
     * Preemption (with ext-phasync, a Suspension that is ready already, see suspendedIn()): the scopes frozen because one of
     * their coroutines was preempted, each with that coroutine; none of a frozen scope's other
     * coroutines runs before it resumes.
     *
     * @var \WeakMap<\Scope, \Fiber>
     */
    private \WeakMap $frozen;

    /**
     * The coroutines of each frozen scope that became ready meanwhile, in order: they run right
     * after the preempted one resumes.
     *
     * @var \WeakMap<\Scope, list<\Fiber>>
     */
    private \WeakMap $held;

    /** How many scopes are frozen: 0 keeps every check off the loop's path. */
    private int $preempted = 0;

    /**
     * Coroutines waiting in a Fiber of their own (see wait()), by spl_object_id(): null while
     * waiting, true once the loop would have resumed them. $nesting counts them: 0 keeps every
     * check off the loop's path.
     *
     * @var array<int, true|null>
     */
    private array $nested = [];
    private int $nesting  = 0;

    /** Ticks so far. */
    private int $ticks = 0;

    /**
     * phasync::finally() callbacks of the nested run() each fiber runs inline, if any, by
     * spl_object_id() of the fiber; run as that run() returns.
     *
     * @var array<int, list<\Closure>>
     */
    private array $frameFinally = [];

    /**
     * phasync::finally() callbacks of the coroutines that registered them outside any nested
     * run(); run when the coroutine ends.
     *
     * @var \WeakMap<\Fiber, list<\Closure>>
     */
    private \WeakMap $fiberFinally;

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
     * A min-heap of flag/park timeouts due sooner than {@see self::TIMEOUT_SLOT_SECONDS}:
     * delivered at their exact deadline instead of rounded up to the next 10 ms slot in
     * {@see self::$timeoutBuckets}, since that rounding would otherwise dominate a short
     * timeout (#139). Rare in practice (most timeouts are not this short), so paying
     * {@see Scheduler::cancel()}'s O(n) removal here, instead of the buckets' O(1), is fine.
     */
    private Scheduler $exactTimeouts;

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

    /**
     * The spl_object_id() of each coroutine in $timeoutSlots whose whenFlagged() wait was
     * registered with throwOnTimeout: false; see expire() and consumeTimedOutWithoutThrow().
     *
     * @var array<int, true>
     */
    private array $timeoutNoThrow = [];

    /**
     * Fibers expire() resumed without a TimeoutException (throwOnTimeout: false); read and
     * cleared once by consumeTimedOutWithoutThrow() right after the fiber resumes.
     *
     * @var \WeakMap<\Fiber, true>
     */
    private \WeakMap $timedOutWithoutThrow;

    /** The last slot whose timeouts were delivered. */
    private int $lastTimeoutSlot = 0;

    /**
     * This WeakMap traces which flag a fiber is waiting for.
     *
     * @var \WeakMap<\Fiber, object>
     */
    public \WeakMap $flagGraph;

    /**
     * Fibers whose cancellation (the one cancellationFor() returns for them) has already reached
     * them once, via wake()'s deliver() or a prior checkCancelled(); see checkCancelled().
     *
     * @var \WeakMap<\Fiber, true>
     */
    private \WeakMap $cancellationFirstSeen;

    /**
     * Fibers a checkCancelled() hit for the second time: the cancellation reached them again
     * while they were already unwinding from it (a wait in a `finally` block, most often).
     * makeExceptionHolder() warns if a CancelledException is what then terminates the fiber.
     *
     * @var \WeakMap<\Fiber, true>
     */
    private \WeakMap $cutShortCleanup;

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

    /** When the loop last collected cycles at idle time; the start-up collection doesn't count. */
    private float $lastIdleCollect = 0;

    /** GC_MIN_INTERVAL and GC_MAX_INTERVAL, or the PHASYNC_GC_MIN_INTERVAL/PHASYNC_GC_MAX_INTERVAL constants when defined. */
    private float $gcMinInterval;
    private float $gcMaxInterval;

    /** Both intervals are multiplied by this: 1, doubled by each collection that finds almost no garbage, up to GC_MAX_SCALE. */
    private float $gcScale = 1.0;

    /** How far collections that find almost no garbage stretch the intervals. */
    private const GC_MAX_SCALE = 8.0;

    /** How long a coroutine runs in a PHP loop before it yields to other requests (ext-phasync). */
    public const PREEMPT_INTERVAL = 0.02;

    /** PREEMPT_INTERVAL, or the PHASYNC_PREEMPT_INTERVAL constant when defined (read once, when the loop is built). */
    public readonly float $preemptInterval;

    /**
     * How often tick() checks the busy-loop safety net: a loop that never idles makes garbage
     * while nothing ends (a server's connections), so this is the fallback for when idle-time
     * collection (also tick(), right before the poller waits) never gets the chance to run.
     */
    private const GC_CHECK_INTERVAL = 0.05;

    /** The longest a busy loop goes without collecting, while there's anything to collect. */
    private const GC_MAX_INTERVAL = 0.5;

    /** The shortest gap between two idle-time collections (tick(), right before the poller waits). */
    private const GC_MIN_INTERVAL = 0.5;

    /** Width of a {@see self::$timeoutBuckets} slot, and the threshold below which a flag/park timeout is delivered exactly (#139) instead. */
    private const TIMEOUT_SLOT_SECONDS = 0.01;

    /** PHP is shutting down: coroutines cancelled by it did not fail. */
    private static bool $exiting = false;

    private \stdClass $afterNextFlag;

    /**
     * FIFO of pending {@see self::idle()} waiters: one flag per call, each with the idle
     * duration it needs and the fiber it belongs to (for {@see self::discard()}). tick() wakes
     * at most the oldest one per idle moment, then re-measures for the next.
     *
     * @var list<array{0: \stdClass, 1: float, 2: \Fiber}>
     */
    private array $idleWaiters = [];

    /** Raised, between coroutines, after any signal phasync handles arrived: for waits on several. */
    private \stdClass $signalFlag;

    /** @var array<int, \stdClass> one flag per signal phasync handles, raised after it arrived */
    private array $signalFlags = [];

    /** @var array<int, int> how often each signal phasync handles has arrived */
    private array $signalCounts = [];

    /** @var array<int, true> the signals that arrived since the last tick; the handler only records */
    private array $signalsArrived = [];

    /** @var array<int, list<\Closure(int):void>> phasync::onSignal() callbacks, run in the handler */
    private array $signalHandlers = [];

    /** @var array<int, callable|int> each signal's handler before phasync took it, restored after run() */
    private array $signalPrevious = [];

    /**
     * The coroutine the loop runs (getCurrentFiber()). Public for phasync::suspend(), which compares
     * it with Fiber::getCurrent() without a method call; only the loop writes it.
     *
     * @internal
     */
    public ?\Fiber $currentFiber = null;
    private ?Frame $currentFrame = null;

    /**
     * While onScopeChange() hooks run, the coroutine they run for: currentFiber, which is not
     * what runs them, so a wait there must not be registered for it (#85). Public for
     * phasync::suspend(); only switchScope() writes it.
     *
     * @internal
     */
    public ?\Fiber $hookFiber = null;

    /**
     * The poller that coroutines wait for streams with: ext-phasync's Suspensions, else
     * stream_select().
     */
    private PollerInterface $poller;

    /** ext-phasync is loaded: coroutines are managed fibers, which blocking calls suspend. */
    private bool $managed;

    /**
     * With ext-phasync: the Suspension of each coroutine whose blocking call suspended it, until it
     * resumes through it.
     *
     * @var \WeakMap<\Fiber, ext\Suspension>
     */
    private \WeakMap $suspended;

    /** wait() is suspending the running coroutine through ext-phasync (Suspension::suspend()). */
    private bool $waiting = false;

    /**
     * The Suspensions still waiting, and their coroutines, by the coroutine's object id: what
     * Suspension::poll() is given.
     *
     * @var array<int, ext\Suspension>
     */
    private array $suspensions = [];

    /** @var array<int, \Fiber> */
    private array $suspensionFibers = [];

    /**
     * Coroutines waiting through the poller ({@see self::parkForPoller()}): while there is one, the
     * loop is never stalled, since what it waits for may come from another process.
     */
    private int $pollerWaits = 0;

    /**
     * Create a new EventLoop instance.
     */
    public function __construct()
    {
        // What a failure needs, loaded now: without file descriptors left, it can't be autoloaded
        foreach ([AggregateException::class, CancelledException::class, ChannelException::class, DeadlockException::class, DeadmanException::class,
            IOException::class, TimeoutException::class, Frame::class, Debug::class, ExceptionTool::class, FiberExceptionHolder::class] as $class) {
            \class_exists($class);
        }
        $this->scopePolyfill = \defined('fiber_interop\\scope\\IS_POLYFILL') && \fiber_interop\scope\IS_POLYFILL;
        if ($this->scopePolyfill) {
            // The polyfill keys the current scope by execution unit. Ours is the coroutine: the one
            // whose scope the loop activates (switchScope()), else the running coroutine whenever
            // the code that runs is not its own Fiber: a Fiber it runs itself, which waits in
            // place (#88, #108). Else (null) the polyfill uses Fiber::getCurrent().
            \fiber_interop\scope\set_execution_key_provider(
                fn () => $this->scopeProbe ?? (null !== ($c = $this->currentFiber) && $c !== \Fiber::getCurrent() ? $c : null)
            );
        }
        $this->gcMinInterval      = \defined('PHASYNC_GC_MIN_INTERVAL') ? (float) PHASYNC_GC_MIN_INTERVAL : self::GC_MIN_INTERVAL;
        $this->gcMaxInterval      = \defined('PHASYNC_GC_MAX_INTERVAL') ? (float) PHASYNC_GC_MAX_INTERVAL : self::GC_MAX_INTERVAL;
        $this->preemptInterval    = \defined('PHASYNC_PREEMPT_INTERVAL') ? (float) PHASYNC_PREEMPT_INTERVAL : self::PREEMPT_INTERVAL;
        $this->clear();
    }

    /**
     * Clear the event loop driver, removing any scheduled fibers etc.
     */
    public function clear(): void
    {
        $this->frames            = new \WeakMap();
        $this->fiberScopes       = new \WeakMap();
        $this->fiberGenerations  = new \WeakMap();
        $this->frozen            = new \WeakMap();
        $this->held              = new \WeakMap();
        $this->preempted         = 0;
        $this->cancelledFibers   = new \WeakMap();
        $this->cancellations     = 0;
        $this->shielded          = new \WeakMap();
        $this->issued            = new \WeakMap();
        $this->deadlines         = [];
        if (\extension_loaded('phasync')) {
            \phasync\ext\_check_version();
        }
        if ($fiber = $this->getCurrentFiber()) {
            // The current fiber stays, alone in its frame
            $frame                     = $this->currentFrame;
            $this->frames[$fiber]      = $frame;
            $this->fiberScopes[$fiber] = \Scope::getCurrent();
            $frame->fibers             = $fiber;
            $frame->live               = 1;
        }
        $this->queue                               = new \SplQueue();
        $this->pending                             = new \SplObjectStorage();
        $this->parentFibers                        = new \WeakMap();
        $this->frameFinally                        = [];
        $this->fiberFinally                        = new \WeakMap();
        $this->fiberExceptionHolders               = new \WeakMap();
        $this->fiberExceptions                     = new \WeakMap();
        $this->scheduler                           = new Scheduler();
        $this->exactTimeouts                       = new Scheduler();
        $this->timeoutBuckets                      = [];
        $this->timeoutSlots                        = [];
        $this->timeoutNoThrow                      = [];
        $this->timedOutWithoutThrow                = new \WeakMap();
        $this->lastTimeoutSlot                     = (int) (\microtime(true) * 100);
        $this->flaggedFibers                       = new \WeakMap();
        $this->flagGraph                           = new \WeakMap();
        $this->cancellationFirstSeen               = new \WeakMap();
        $this->cutShortCleanup                     = new \WeakMap();
        $this->afterNextFlag                       = new \stdClass();
        $this->signalFlag                          = new \stdClass();
        $this->newFrame ??= \Closure::bind(static fn (?Frame $parent): Frame => new Frame($parent), null, Frame::class);
        $this->serviceFrame                        = ($this->newFrame)(null);
        static $shutdown                           = false;
        if (!$shutdown) {
            $shutdown = true;
            \register_shutdown_function(static function () { self::$exiting = true; });
        }
        $this->managed               = \class_exists(ext\Suspension::class, false);
        $this->poller                = $this->managed ? new SuspensionPoller($this->pollSuspensions(...)) : new StreamSelectPoller($this);
        $this->suspended             = new \WeakMap();
        $this->suspensions           = [];
        $this->suspensionFibers      = [];
        $this->pollerWaits           = 0;
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
            'frames'                => $this->frames->count(),
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
     * Collect cycles, and set how soon the next collection comes: a collection costs per
     * possible root, garbage or not, so one that freed under 1% of its roots (a long-lived
     * server's own live structures, touched by every request, make most of them) doubles
     * both intervals, up to GC_MAX_SCALE; one that found garbage puts them back.
     */
    private function collectCycles(): void
    {
        $roots          = \gc_status()['roots'];
        $freed          = \gc_collect_cycles();
        $this->gcScale  = $freed * 100 < $roots ? \min($this->gcScale * 2, self::GC_MAX_SCALE) : 1.0;
    }

    /**
     * Run the coroutines that are ready, once, and the callbacks queued meanwhile.
     *
     * Public for code that drives the loop in place from a Fiber phasync did not start (an
     * adapter's wait in a foreign Fiber): the running coroutine and its frame are put back when it
     * returns, so phasync::getFiber() and phasync::frame() are what they were before.
     */
    public function tick(): void
    {
        if ($this->signalCounts) {
            // Handlers run here, between coroutines, unless pcntl_async_signals() ran them already
            \pcntl_signal_dispatch();
            if ($this->signalsArrived) {
                $arrived              = $this->signalsArrived;
                $this->signalsArrived = [];
                foreach ($arrived as $signo => $_) {
                    $this->raiseFlag($this->signalFlags[$signo]);
                }
                $this->raiseFlag($this->signalFlag);
            }
        }
        $now   = \microtime(true);
        $queue = $this->queue;

        // Deliver the timeouts of the 10 ms slots that have passed: usually none this tick
        if (($slot = (int) ($now * 100)) > $this->lastTimeoutSlot) {
            $this->checkTimeouts($slot);
        }

        // Deliver exact (sub-slot) flag/park timeouts due now (#139)
        while (!$this->exactTimeouts->isEmpty() && $this->exactTimeouts->getNextTimestamp() <= $now) {
            $this->expireFiber($this->exactTimeouts->extract());
        }

        // Frame::setTimeout(): cancel the frames whose deadline has passed
        if ([] !== $this->deadlines) {
            foreach ($this->deadlines as $id => $frame) {
                if ($frame->deadline <= $now) {
                    unset($this->deadlines[$id]);
                    $frame->timeout = new TimeoutException('The frame timed out');
                    $this->cancelFrame($frame, new CancelledException('Cancelled: the frame timed out', 0, $frame->timeout));
                }
            }
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

        /*
         * Ensure the delay is not too long for an exact (sub-slot) timeout (#139)
         */
        if ($maxSleepTime > 0 && !$this->exactTimeouts->isEmpty()) {
            $maxSleepTime = \min($maxSleepTime, \max(0.0, $this->exactTimeouts->getNextTimestamp() - $now));
        }

        if ($maxSleepTime > 0) {
            // Wake at the next timeout slot, where timeouts are waiting
            if ([] !== $this->timeoutBuckets) {
                $maxSleepTime = \min($maxSleepTime, \max(0.0, ($this->lastTimeoutSlot + 1) / 100 - $now));
            }
            foreach ($this->deadlines as $frame) {
                $maxSleepTime = \min($maxSleepTime, \max(0.0, $frame->deadline - $now));
            }
        } else {
            // Ensure non-negative sleep time
            $maxSleepTime = 0;
        }

        $afterNextCount = isset($this->flaggedFibers[$this->afterNextFlag]) ? $this->flaggedFibers[$this->afterNextFlag]->count() : 0;

        if ($maxSleepTime > 0 && $afterNextCount > 0) {
            $maxSleepTime = 0;
        }

        if ($maxSleepTime > 0 && 0 === $this->pollerWaits && $this->isStalled()) {
            $this->stalled();
            $maxSleepTime = 0;
        }

        if ($maxSleepTime > 0 && [] !== $this->idleWaiters) {
            // Nothing is runnable: the oldest idle() waiter gets this moment, once it has lasted
            // its own $after. Poll for it directly, since the GC branch below polls at most once
            // per GC_MIN_INTERVAL and would make a short $after wait for a collection it has
            // nothing to do with.
            [$flag, $after] = $this->idleWaiters[0];
            $this->poller->poll(\min($maxSleepTime, $after));
            if (0 === $queue->count() && $this->callbackQueue->isEmpty() && \microtime(true) - $now >= $after) {
                \array_shift($this->idleWaiters);
                $this->raiseFlag($flag);
            }
            $maxSleepTime = 0;
        }

        if ($maxSleepTime > 0 && $now - $this->lastIdleCollect > $this->gcMinInterval * $this->gcScale && \gc_status()['roots'] > 0) {
            // Nothing is runnable. Ask the poller whether I/O is ready right now: if not, the CPU
            // has a spare moment, so collect, then wait for whatever is left of the wait. At most
            // once per GC_MIN_INTERVAL, since on a loaded server "nothing runnable" is often just
            // a database round trip in flight, and collecting on every such wait costs real CPU.
            $this->poller->poll(0);
            if (0 === $queue->count() && $this->callbackQueue->isEmpty()) {
                $this->collectCycles();
                $this->lastGarbageCollect = $this->lastIdleCollect = \microtime(true);
                $this->poller->poll(\max(0.0, $maxSleepTime - ($this->lastGarbageCollect - $now)));
            }
        } else {
            $this->poller->poll($maxSleepTime);
        }

        /*
         * Ensure afterNext fibers are given an opportunity to run
         */
        $this->raiseFlag($this->afterNextFlag);

        /*
         * Run enqueued fibers.
         */
        ++$this->ticks;
        $fiberCount            = $queue->count();
        // Run in place (from a Fiber phasync did not start, see wait()): the running coroutine
        // and its frame are put back when the tick is over
        $outerFiber            = $this->currentFiber;
        $outerFrame            = $this->currentFrame;
        if (0 !== $this->preempted) {
            $this->runFrozen($fiberCount);
            $fiberCount = 0;
        }
        $fiberExceptionHolders = $this->fiberExceptionHolders;
        $frames                = $this->frames;
        $fiberScopes           = $this->fiberScopes;
        // Counts down, rather than comparing against a separate loop-invariant bound: a
        // coroutine's resume() can throw (for example WorkerStoppingException at a worker
        // stop, ext only) into the catch below, and a tracing JIT that recorded $fiberCount
        // as a register-only loop invariant across that exception edge could resume the VM
        // with it undefined on the next condition check (phasync-ext-src#47). $fiberCount is
        // the thing the loop condition reads, so it is also the thing each iteration writes.
        for (; $fiberCount > 0 && !$queue->isEmpty(); --$fiberCount) {
            $fiber = $queue->dequeue();
            unset($this->pending[$fiber]);
            if (0 !== $this->nesting && \array_key_exists(\spl_object_id($fiber), $this->nested)) {
                $this->nested[\spl_object_id($fiber)] = true; // its wait is over: wait() returns
                continue;
            }

            try {
                $this->currentFiber    = $fiber;
                $this->currentFrame    = $frames[$fiber];
                if ($fiberScopes[$fiber] !== $this->liveScope) {
                    $this->switchScope($fiberScopes[$fiber], $fiber);
                }
                if ($this->scopePolyfill) {
                    $this->fiberGenerations[$fiber] = \fiber_interop\scope\generation();
                }

                // A cut-short mark from an earlier resumption only still matters if nothing ran
                // for this fiber since: it really suspended and resumed again, so whatever it was
                // unwinding from completed (see checkCancelled()).
                unset($this->cutShortCleanup[$fiber]);
                if (isset($fiberExceptionHolders[$fiber])) {
                    // We got an opportunity to throw the exception inside the coroutine
                    $eh = $fiberExceptionHolders[$fiber];
                    unset($fiberExceptionHolders[$fiber]);
                    $exception = $eh->get();
                    $eh->returnToPool();
                    $this->throwInto($fiber, $exception);
                } else {
                    $this->continueFiber($fiber);
                }
            } catch (\Throwable $e) {
                if (!$fiber->isTerminated()) {
                    throw $e; // an onScopeChange() hook threw: the coroutine stays as it was, waiting
                }
                /*
                 * In case this exception is not caught, we must store it in an
                 * exception holder which will surface the exception if the fiber
                 * is garbage collected. Ideally the exception holder will not be
                 * garbage collected.
                 */
                $fiberExceptionHolders[$fiber] = $this->makeExceptionHolder($e, $fiber);
            } finally {
                $this->currentFiber = $outerFiber;
                $this->currentFrame = $outerFrame;
            }
            /*
             * Whenever a fiber is terminated, we'll actively check it
             * here to ensure deferred closures can run as soon as possible
             */
            if ($fiber->isTerminated()) {
                $this->handleTerminatedFiber($fiber);
            }
            if (0 !== $this->preempted) {
                // A coroutine was preempted: the rest of this tick respects its frozen scope.
                // $fiberCount hasn't been decremented for this iteration yet (that's the for
                // loop's third clause, run on the next pass), so it is already "how many are
                // left after this one".
                $this->runFrozen($fiberCount - 1);
                break;
            }
        }

        if ($now - $this->lastGarbageCheck > self::GC_CHECK_INTERVAL) {
            // A busy loop (always some coroutine ready, never idle, see tick()'s poll() above)
            // never gets the idle-time collection above: this is its safety net, checked between
            // fibers as always: it collects once GC_MAX_INTERVAL has passed since the last
            // collection (of any kind) ended while there's anything to collect. Until then garbage
            // stays in memory: a loop this busy has no time to spare for collecting it.
            $this->lastGarbageCheck = $now;
            if ($now - $this->lastGarbageCollect > $this->gcMaxInterval * $this->gcScale && \gc_status()['roots'] > 0) {
                $this->collectCycles();
                // From its end: a collection slower than the interval is not followed by another at once (#104)
                $this->lastGarbageCollect = \microtime(true);
            }
        }

        while (!$this->callbackQueue->isEmpty()) {
            [$scope, $callback] = $this->callbackQueue->dequeue();
            $scope->run($callback);
        }
    }

    /**
     * The queue loop of tick() while scopes are frozen: up to $count coroutines, those of a frozen
     * scope held back until its preempted coroutine resumes, then run right after it.
     */
    private function runFrozen(int $count): void
    {
        $queue = $this->queue;
        for ($i = 0; $i < $count && !$queue->isEmpty(); ++$i) {
            $fiber = $queue->dequeue();
            unset($this->pending[$fiber]);
            $scope = $this->fiberScopes[$fiber];
            $p     = $this->frozen[$scope] ?? null;
            if (null === $p) {
                $this->resume($fiber);
            } elseif ($p !== $fiber) {
                $this->pending[$fiber] = \PHP_FLOAT_MAX; // still ready, held
                $this->held[$scope][]  = $fiber;
            } else {
                $held = $this->thaw($scope);
                $this->resume($fiber);
                if (($this->frozen[$scope] ?? null) === $fiber) {
                    // Preempted again in its turn: the others wait on (as they would for a loop
                    // that never yields without preemption)
                    \array_push($held, ...$this->held[$scope]);
                    $this->held[$scope] = $held;
                } else {
                    foreach ($held as $f) {
                        unset($this->pending[$f]);
                        $this->resume($f);
                    }
                }
            }
        }
    }

    /** $scope's preempted coroutine resumes (or is gone): the coroutines held for it. */
    private function thaw(\Scope $scope): array
    {
        $held = $this->held[$scope];
        unset($this->frozen[$scope], $this->held[$scope]);
        --$this->preempted;

        return $held;
    }

    /** One coroutine's turn: as in tick()'s own loop. */
    private function resume(\Fiber $fiber): void
    {
        if (0 !== $this->nesting && \array_key_exists(\spl_object_id($fiber), $this->nested)) {
            $this->nested[\spl_object_id($fiber)] = true;

            return;
        }
        $outerFiber = $this->currentFiber;
        $outerFrame = $this->currentFrame;
        try {
            $this->currentFiber = $fiber;
            $this->currentFrame = $this->frames[$fiber];
            if ($this->fiberScopes[$fiber] !== $this->liveScope) {
                $this->switchScope($this->fiberScopes[$fiber], $fiber);
            }
            if ($this->scopePolyfill) {
                $this->fiberGenerations[$fiber] = \fiber_interop\scope\generation();
            }
            // See the same unset() in tick()'s own loop.
            unset($this->cutShortCleanup[$fiber]);
            if (isset($this->fiberExceptionHolders[$fiber])) {
                $eh = $this->fiberExceptionHolders[$fiber];
                unset($this->fiberExceptionHolders[$fiber]);
                $exception = $eh->get();
                $eh->returnToPool();
                $this->throwInto($fiber, $exception);
            } else {
                $this->continueFiber($fiber);
            }
        } catch (\Throwable $e) {
            if (!$fiber->isTerminated()) {
                throw $e; // an onScopeChange() hook threw
            }
            $this->fiberExceptionHolders[$fiber] = $this->makeExceptionHolder($e, $fiber);
        } finally {
            $this->currentFiber = $outerFiber;
            $this->currentFrame = $outerFrame;
        }
        if ($fiber->isTerminated()) {
            $this->handleTerminatedFiber($fiber);
        }
    }

    /**
     * Run $fiber on: through the Suspension its blocking call left, if any. A blocking call it
     * makes next suspends it again (with ext-phasync).
     */
    private function continueFiber(\Fiber $fiber): void
    {
        try {
            if (null === $s = $this->suspended[$fiber] ?? null) {
                $fiber->resume();
            } else {
                unset($this->suspended[$fiber]);
                $s->resume();
            }
        } catch (ext\Suspension $next) {
            $this->suspendedIn($fiber, $next);
        }
    }

    /**
     * Throw $exception into $fiber where it waits. A blocking call that can't be abandoned yet
     * (a thread pool task) refuses: the exception goes in once the call is done.
     */
    private function throwInto(\Fiber $fiber, \Throwable $exception): void
    {
        try {
            if (null === $s = $this->suspended[$fiber] ?? null) {
                $fiber->throw($exception);

                return;
            }
            unset($this->suspended[$fiber]);
            try {
                $s->throw($exception);
            } catch (\RuntimeException $e) {
                if (!$fiber->isSuspended()) {
                    throw $e; // the coroutine ended with it
                }
                $this->suspended[$fiber]             = $s;
                $this->fiberExceptionHolders[$fiber] = $this->makeExceptionHolder($exception, $fiber);
                $this->waitFor($fiber, $s);
            }
        } catch (ext\Suspension $next) {
            $this->suspendedIn($fiber, $next);
        }
    }

    /**
     * A blocking call suspended $fiber (ext-phasync): it waits for $s in the next poll(). A
     * Suspension that is ready already is a preemption: the coroutine goes to the back of the
     * queue, and its scope is frozen until it runs again (see runFrozen()). It goes on at once
     * instead when its scope is frozen already (another coroutine of it was preempted), or inside
     * phasync's or Swerve's own code, whose bookkeeping may be half done.
     */
    private function suspendedIn(\Fiber $fiber, ext\Suspension $s): void
    {
        $this->suspended[$fiber]   = $s;
        $this->fiberScopes[$fiber] = $s->getScope();
        if ($this->waiting) {
            $this->waiting = false; // wait(): it waits for what it registered, not for the poll

            return;
        }
        if (!$s->isReady()) {
            $this->waitFor($fiber, $s);
            if (0 !== $this->cancellations) {
                $this->wake($fiber); // a cancellation it hasn't met yet ends the wait at once
            }

            return;
        }
        $scope = $this->fiberScopes[$fiber];
        $class = (new \ReflectionFiber($fiber))->getTrace(\DEBUG_BACKTRACE_IGNORE_ARGS)[0]['class'] ?? '';
        if (isset($this->frozen[$scope]) || \str_starts_with($class, 'phasync\\') || 'phasync' === $class || \str_starts_with($class, 'Swerve\\')) {
            $this->continueFiber($fiber); // its scope is frozen already, or it is in the runtime's own code

            return;
        }
        $this->frozen[$scope] = $fiber;
        $this->held[$scope]   = [];
        ++$this->preempted;
        $this->enqueueKnownLive($fiber);
    }

    /** $fiber waits for $s: polled, and counted as a wait that another process may end (DLK-3). */
    private function waitFor(\Fiber $fiber, ext\Suspension $s): void
    {
        $id                          = \spl_object_id($fiber);
        $this->suspensions[$id]      = $s;
        $this->suspensionFibers[$id] = $fiber;
        $this->pending[$fiber]       = \PHP_FLOAT_MAX;
        ++$this->pollerWaits;
    }

    /**
     * SuspensionPoller::poll(): wait up to $seconds, and queue the coroutines whose Suspension is
     * ready.
     */
    private function pollSuspensions(float $seconds): void
    {
        if ([] === $this->suspensions) {
            if ($seconds > 0) {
                \usleep((int) ($seconds * 1_000_000));
            }

            return;
        }
        foreach (ext\Suspension::poll($this->suspensions, $seconds) as $id => $_) {
            $fiber = $this->suspensionFibers[$id];
            unset($this->suspensions[$id], $this->suspensionFibers[$id]);
            --$this->pollerWaits;
            $this->queue->enqueue($fiber); // still pending: it waits in the queue, as unpark() leaves it
        }
    }

    /**
     * $scope becomes current for $fiber, which the loop is about to run (null: the code that runs
     * on after this call): with the polyfill, its superglobals are swapped in; then the
     * onScopeChange() hooks run, refusing waits (#85).
     */
    private function switchScope(\Scope $scope, ?\Fiber $fiber): void
    {
        $old             = $this->liveScope;
        $this->liveScope = $scope;
        if ($this->scopePolyfill) {
            $this->scopeProbe = $fiber;
            try {
                \fiber_interop\scope\activate();
            } finally {
                $this->scopeProbe = null;
            }
        }
        if ([] !== $this->scopeHooks) {
            $outer           = $this->hookFiber;
            $this->hookFiber = $this->currentFiber;
            try {
                foreach ($this->scopeHooks as $hook) {
                    $hook($scope, $old);
                }
            } finally {
                $this->hookFiber = $outer;
            }
        }
    }

    /**
     * The running coroutine suspends: the scope current in it is what it resumes in.
     *
     * Under the polyfill, when the resume-side already recorded fiberGenerations[$fiber] and
     * fiber_interop\scope\generation() has not moved since, nothing could have changed the active
     * scope: fiberScopes[$fiber] is already right, so Scope::getCurrent() and the write are
     * skipped (phasync#112). A moved generation is not always a real change (run() bumps on both
     * entry and exit of a no-op $scope->run($scope)), but a never-moved one never misses a real one.
     *
     * @internal phasync::suspend()
     */
    public function suspending(\Fiber $fiber): void
    {
        if ($this->scopePolyfill && ($this->fiberGenerations[$fiber] ?? null) === \fiber_interop\scope\generation()) {
            return;
        }
        $this->fiberScopes[$fiber] = \Scope::getCurrent();
    }

    /**
     * Register an onScopeChange() hook.
     *
     * @internal phasync::onScopeChange()
     */
    public function onScopeChange(\Closure $hook): void
    {
        $this->scopeHooks[] = $hook;
    }

    /**
     * Start a service coroutine: in the service frame and the root scope; see phasync::service().
     */
    public function runService(\Closure $closure, array $args): \Fiber
    {
        $fiber = $this->create($closure, $args, $this->serviceFrame, \Scope::getRootScope());
        unset($this->parentFibers[$fiber]);

        return $fiber;
    }

    /**
     * Create a coroutine of $frame running in $scope, and run it up to its first suspension.
     *
     * What the coroutine throws is kept with it, and thrown where it is awaited. An
     * onScopeChange() hook that throws before the coroutine starts throws from here, and no
     * coroutine is created (#106).
     */
    public function create(\Closure $closure, array $args, Frame $frame, \Scope $scope): \Fiber
    {
        $currentFiber = $this->currentFiber;
        $currentFrame = $this->currentFrame;
        $before       = \Scope::getCurrent();
        if ($this->scopePolyfill) {
            // The polyfill keeps a scope per Fiber only inside run()
            $fn      = $closure;
            $closure = static fn (mixed ...$args) => $scope->run($fn, ...$args);
            $fiber   = new \Fiber($closure);
        } else {
            // A Fiber belongs to the scope it is constructed in
            $fiber = $scope === $before ? new \Fiber($closure) : $scope->run(static fn () => new \Fiber($closure));
        }

        if ($this->managed) {
            ext\Suspension::manage($fiber); // its blocking calls suspend it
        }
        $this->frames[$fiber] = $frame; // a wait in a hook is undone by discard(), which needs it
        if ($scope !== $this->liveScope) {
            $this->currentFiber = $fiber;
            $this->currentFrame = $frame;
            try {
                $this->switchScope($scope, $fiber);
            } catch (\Throwable $e) {
                unset($this->frames[$fiber]);
                $this->currentFiber = $currentFiber;
                $this->currentFrame = $currentFrame;
                $this->switchScope($before, null);

                throw $e;
            }
            $this->currentFiber = $currentFiber;
            $this->currentFrame = $currentFrame;
        }

        $this->fiberScopes[$fiber]  = $scope;
        $this->parentFibers[$fiber] = $currentFiber;
        $this->joinFrame($frame, $fiber);

        // Start the code in the Fiber, so that we don't have to support
        // launching of coroutines as part of the event loop.
        try {
            $this->currentFiber = $fiber;
            $this->currentFrame = $frame;
            $fiber->start(...$args);
        } catch (ext\Suspension $s) {
            $this->suspendedIn($fiber, $s);
        } catch (\Throwable $e) {
            $this->fiberExceptionHolders[$fiber] = $this->makeExceptionHolder($e, $fiber);
        } finally {
            $this->currentFiber = $currentFiber;
            $this->currentFrame = $currentFrame;
            if ($fiber->isTerminated()) {
                $this->handleTerminatedFiber($fiber);
            }
        }
        if ($this->liveScope !== $before) {
            $this->switchScope($before, null); // the creating code goes on
        }
        if (0 !== $this->preempted && null !== $currentFiber && isset($this->frozen[$before])) {
            // The new coroutine was preempted before it first suspended: its creator is of the
            // same frozen scope, so it waits too (go() returns once the scope thaws)
            $this->pending[$currentFiber]     = \PHP_FLOAT_MAX;
            $this->held[$before][]            = $currentFiber;
            $this->fiberScopes[$currentFiber] = $before;
            \Fiber::suspend();
        }

        return $fiber;
    }

    /**
     * The frame of $fiber, null for a Fiber phasync did not start.
     */
    public function getFrame(\Fiber $fiber): ?Frame
    {
        return $this->frames[$fiber] ?? null;
    }

    /**
     * The frame of the running coroutine, null outside one.
     */
    public function getCurrentFrame(): ?Frame
    {
        return $this->currentFrame;
    }

    /**
     * The frame of the services.
     *
     * @internal phasync::frame()
     */
    public function isServiceFrame(Frame $frame): bool
    {
        return $frame === $this->serviceFrame;
    }

    /**
     * Raise a flag to enable any fiber that is scheduled to activate on
     * this flag via {@see self::whenFlagged()}.
     */
    /**
     * Take $signo over until restoreSignals(): phasync's one handler replaces the current one,
     * which it keeps, calls after its own work, and puts back afterwards.
     */
    public function listenSignal(int $signo): void
    {
        if (!isset($this->signalCounts[$signo])) {
            $this->signalPrevious[$signo] = \pcntl_signal_get_handler($signo);
            $this->signalCounts[$signo]   = 0;
            $this->signalFlags[$signo]    = new \stdClass();
            \pcntl_signal($signo, $this->handleSignal(...));
        }
    }

    /**
     * The one handler of every signal phasync handles: the onSignal() callbacks at once, then
     * a record of the signal for tick(), which wakes phasync::signal()'s waiters, then the
     * handler that was installed before phasync took the signal.
     */
    private function handleSignal(int $signo, mixed $siginfo = null): void
    {
        foreach ($this->signalHandlers[$signo] ?? [] as $handler) {
            $handler($signo);
        }
        ++$this->signalCounts[$signo];
        $this->signalsArrived[$signo] = true;
        if (\is_callable($previous = $this->signalPrevious[$signo] ?? null)) {
            $previous($signo, $siginfo);
        }
    }

    /** Give every signal back its handler from before phasync took it: when run() returns. */
    public function restoreSignals(): void
    {
        foreach ($this->signalPrevious as $signo => $previous) {
            \pcntl_signal($signo, $previous);
        }
        $this->signalPrevious = $this->signalCounts = $this->signalFlags = $this->signalsArrived = $this->signalHandlers = [];
    }

    /** The flag raised after $signo arrived. */
    public function getSignalFlagOf(int $signo): object
    {
        return $this->signalFlags[$signo];
    }

    /** Add a callback the handler of $signo runs right away, see phasync::onSignal(). */
    public function onSignal(int $signo, \Closure $handler): void
    {
        $this->listenSignal($signo);
        $this->signalHandlers[$signo][] = $handler;
    }

    /** How often $signo has arrived since listenSignal(). */
    public function signalCount(int $signo): int
    {
        return $this->signalCounts[$signo];
    }

    public function getSignalFlag(): object
    {
        return $this->signalFlag;
    }

    public function raiseFlag(object $flag): int
    {
        if (!isset($this->flaggedFibers[$flag])) {
            return 0;
        }

        // Raising resumes every waiter, so the store is empty afterwards
        $fiberStore = $this->flaggedFibers[$flag];
        unset($this->flaggedFibers[$flag]);
        $fibers = $fiberStore->takeFibers();
        $fiberStore->returnToPool();

        // enqueueKnownLive(), inlined: one loop, no per-waiter method call (perf#117)
        $queue           = $this->queue;
        $hasTimeoutSlots = [] !== $this->timeoutSlots;
        $count           = 0;
        foreach ($fibers as $fiber) {
            if ($fiber->isTerminated()) {
                continue;
            }
            $this->pending[$fiber] = \PHP_FLOAT_MAX;
            if ($hasTimeoutSlots) {
                $this->removeTimeout($fiber);
            }
            $queue->enqueue($fiber);
            ++$count;
        }

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
        $this->enqueueKnownLive($fiber);
    }

    /**
     * enqueue(), without the isTerminated() guard: for callers on the hot path (expire()'s
     * no-throw timeout) that just checked it themselves, so as not to pay for the same Fiber
     * method call twice. raiseFlag() inlines this itself instead of calling it per waiter.
     *
     * @internal
     */
    public function enqueueKnownLive(\Fiber $fiber): void
    {
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
        $this->whenFlagged($this->afterNextFlag, \PHP_FLOAT_MAX, $fiber);
    }

    /**
     * Suspend $fiber until the loop has had nothing runnable for $after seconds; see
     * phasync::idle(). Registration order decides which waiter tick() wakes for each idle
     * moment it finds (oldest first), not $after: each waiter is checked against its own
     * $after once it is at the head of the queue.
     */
    public function idle(float $after, \Fiber $fiber): void
    {
        $flag                = new \stdClass();
        $this->idleWaiters[] = [$flag, $after, $fiber];
        $this->whenFlagged($flag, \PHP_FLOAT_MAX, $fiber);
    }

    /**
     * whenFlagged()'s common case, inlined: an unbounded wait on an ordinary (non-Fiber) flag,
     * which needs none of whenFlagged()'s timeout or await-cycle checks.
     *
     * @internal phasync::awaitFlag()
     */
    public function whenFlaggedFast(object $flag, \Fiber $fiber): void
    {
        if (isset($this->pending[$fiber])) {
            throw new \LogicException('Fiber is already pending when enqueueing for flag');
        }
        $flagStore = $this->flaggedFibers[$flag] ??= Flag::create($this);
        $flagStore->add($fiber);
        $this->pending[$fiber] = \PHP_FLOAT_MAX;
    }

    /**
     * Schedule the Fiber instance to run when the object is flagged
     * {@see self::raiseFlag()}
     *
     * @param float $timeout        the number of seconds to allow the fiber to be suspended
     * @param bool  $throwOnTimeout when false, a timeout resumes $fiber without a TimeoutException
     *                              (see {@see self::consumeTimedOutWithoutThrow()}) instead of
     *                              throwing one into it; a deadline already past then makes this
     *                              method return false instead of throwing
     *
     * @return bool false only when $throwOnTimeout is false and $timeout was already past: the
     *              fiber was not registered to wait at all
     */
    public function whenFlagged(object $flag, float $timeout, \Fiber $fiber, bool $throwOnTimeout = true): bool
    {
        if ($timeout <= 0) {
            if (!$throwOnTimeout) {
                return false; // a deadline already past: the caller resolves this without waiting
            }
            throw new TimeoutException('The timeout had run out before waiting'); // a deadline already past
        }
        if (isset($this->pending[$fiber])) {
            throw new \LogicException('Fiber is already pending when enqueueing for flag');
        }
        $isFiberFlag = $flag instanceof \Fiber;
        if ($isFiberFlag && $this->isBlockedByFlag($flag, $fiber)) {
            // Detect cycles
            throw new \LogicException('Await cycle deadlock detected');
        }

        if (!isset($this->flaggedFibers[$flag])) {
            $this->flaggedFibers[$flag] = Flag::create($this, $isFiberFlag);
        }

        if ($isFiberFlag) {
            // The graph is only used to detect await cycles, which only follow fibers. It
            // must not hold ordinary flags, or a flag could never be garbage collected while
            // a coroutine waits for it.
            $this->flagGraph[$fiber] = $flag;
        }
        $this->flaggedFibers[$flag]->add($fiber);
        if ($timeout < 1e9) {
            $this->pending[$fiber] = $deadline = \microtime(true) + $timeout;
            $this->addTimeout($fiber, $deadline, $throwOnTimeout);
        } else {
            // No real deadline: the stored value is never read for an unbounded wait (only
            // its presence as a pending key is), so skip computing one (a syscall otherwise
            // paid on every unbounded wait, which is most of them).
            $this->pending[$fiber] = \PHP_FLOAT_MAX;
        }

        return true;
    }

    /**
     * Whether $fiber's most recent whenFlagged() wait ended by a $throwOnTimeout: false timeout
     * (see {@see self::whenFlagged()}); consumed once, so a later wait starts clean.
     *
     * @internal
     */
    public function consumeTimedOutWithoutThrow(\Fiber $fiber): bool
    {
        if (isset($this->timedOutWithoutThrow[$fiber])) {
            unset($this->timedOutWithoutThrow[$fiber]);

            return true;
        }

        return false;
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

        // The graph holds only fibers (see enqueueFlag()), so the walk ends at an unblocked one
        $current = $fiber;
        while (isset($this->flagGraph[$current])) {
            $current = $this->flagGraph[$current];
            if ($current === $flag) {
                return true;
            }
        }

        return false;
    }

    /**
     * Schedule a callback to be invoked after the current (or next) tick, outside of the fiber,
     * in the scope current now.
     */
    public function defer(\Closure $callback): void
    {
        $this->callbackQueue->enqueue([\Scope::getCurrent(), $callback]);
    }

    /**
     * Wait in a Fiber the running coroutine runs itself (Drupal's renderer), after registering what
     * the coroutine waits for; the callers suspend the coroutine's own Fiber directly when
     * Fiber::getCurrent() is the coroutine, and come here otherwise. Fiber::suspend() would return
     * to the coroutine's own code, which does not expect it: here the loop runs in place instead,
     * tick after tick, until it would resume the coroutine. Other coroutines run meanwhile, resumed
     * from that Fiber and suspending back to it; the Fiber stays where it is. With ext-phasync the
     * whole coroutine suspends instead (Suspension::suspend()), its Fiber where it is, and the loop
     * resumes it as it resumes any other wait.
     *
     * Invariants of a tick run in place: it never resumes a coroutine that is running (one in
     * the chain of Fibers that led here, which waits in a Fiber of its own: its turn marks its wait
     * as over instead); it restores the running coroutine, its frame and its scope when it returns; waits
     * nest last-in-first-out, so a wait deeper in the stack holds the ones below it until it is
     * over, even when theirs are over first.
     *
     * @internal phasync::suspend() and park()
     */
    public function wait(): void
    {
        if ($this->managed) {
            // ext-phasync suspends the whole coroutine, its own Fiber where it is: resumed (or
            // thrown into) through the Suspension as any other wait, see suspendedIn()
            $this->fiberScopes[$this->currentFiber] = \Scope::getCurrent();
            $this->waiting                          = true;
            ext\Suspension::suspend();

            return;
        }
        $fiber             = $this->currentFiber;
        $frame             = $this->currentFrame;
        $scope             = $this->fiberScopes[$fiber] = \Scope::getCurrent();
        $id                = \spl_object_id($fiber);
        $this->nested[$id] = null;
        ++$this->nesting;
        try {
            while (null === $this->nested[$id]) {
                $this->tick();
            }
        } finally {
            --$this->nesting;
            unset($this->nested[$id]);
            $this->currentFiber = $fiber;
            $this->currentFrame = $frame;
            if ($this->liveScope !== $scope) {
                $this->switchScope($scope, null);
            }
        }
        if (isset($this->fiberExceptionHolders[$fiber])) {
            $eh = $this->fiberExceptionHolders[$fiber];
            unset($this->fiberExceptionHolders[$fiber]);
            $exception = $eh->get();
            $eh->returnToPool();

            throw $exception;
        }
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
        if (null !== $this->hookFiber && $this->hookFiber === $this->currentFiber) {
            $this->refuseHookWait();
        }
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
        if ($timeout < 1e9) {
            $this->pending[$fiber] = $deadline = \microtime(true) + $timeout;
            $this->addTimeout($fiber, $deadline);
        } else {
            // See whenFlagged(): no real deadline, skip the microtime() syscall.
            $this->pending[$fiber] = \PHP_FLOAT_MAX;
        }
        try {
            if (\Fiber::getCurrent() === $fiber) {
                $this->fiberScopes[$fiber] = \Scope::getCurrent();
                \Fiber::suspend();
            } else {
                $this->wait();
            }
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
     * park() for a wait on the poller (a stream, or with ext-phasync any hooked blocking call):
     * counted, since such a wait can end by what another process does (DLK-3).
     *
     * @internal the pollers
     *
     * @throws TimeoutException after $timeout seconds
     */
    public function parkForPoller(int $slot, float $timeout = \PHP_FLOAT_MAX): void
    {
        ++$this->pollerWaits;
        try {
            $this->park($slot, $timeout);
        } finally {
            --$this->pollerWaits;
        }
    }

    /**
     * Whether coroutines wait and nothing can ever wake them (DLK-3): none can run, no callback is
     * queued, and no timer, timeout, frame deadline, idle() waiter or signal is left. Waits on the poller are
     * checked by the caller (tick()), as is the afterNext flag.
     */
    private function isStalled(): bool
    {
        return 0 !== $this->pending->count() && 0 === $this->queue->count() && $this->callbackQueue->isEmpty()
            && $this->scheduler->isEmpty() && [] === $this->timeoutBuckets && $this->exactTimeouts->isEmpty()
            && [] === $this->idleWaiters
            && [] === $this->signalCounts && [] === $this->deadlines;
    }

    /**
     * The loop is stalled, unless a signal handler phasync doesn't know of is installed: the outermost run() fails with a DeadlockException naming every
     * waiting coroutine, which cancels its coroutines. A stall while the run's coroutines already
     * unwind (a shielded wait) fails it again, and drops the coroutines still waiting, so the run
     * ends; they are destroyed as their last references go.
     */
    private function stalled(): void
    {
        if (\gc_status()['roots'] > 0) {
            // A flag freed with a cycle wakes its waiters (FLG-1)
            $this->collectCycles();
            $this->lastGarbageCollect = \microtime(true);
            if (!$this->isStalled()) {
                return;
            }
        }
        if (\function_exists('pcntl_signal_get_handler')) {
            // A handler of the application's own (pcntl_signal()) may raise a flag when its signal comes
            for ($signo = 1, $last = \defined('SIGRTMAX') ? \SIGRTMAX : 31; $signo <= $last; ++$signo) {
                try {
                    if (\is_callable(\pcntl_signal_get_handler($signo))) {
                        return;
                    }
                } catch (\ValueError) {
                    break; // before PHP 8.4, pcntl handles signals up to 32 only, so none above has a handler
                }
            }
        }
        $own   = [\dirname(__DIR__) . \DIRECTORY_SEPARATOR . 'phasync.php', \dirname(__DIR__) . \DIRECTORY_SEPARATOR . 'io.php'];
        $lines = [];
        foreach ($this->pending as $fiber) {
            $line = '  coroutine #' . \spl_object_id($fiber);
            if ($fiber->isSuspended()) {
                // The call it waits in: the first one made from outside phasync
                $rf    = new \ReflectionFiber($fiber);
                $where = ' at ' . $rf->getExecutingFile() . ':' . $rf->getExecutingLine();
                foreach ($rf->getTrace(\DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
                    if (isset($frame['file']) && !\str_starts_with($frame['file'], __DIR__ . \DIRECTORY_SEPARATOR) && !\in_array($frame['file'], $own, true)) {
                        $where = ' in ' . ($frame['class'] ?? '') . ($frame['type'] ?? '') . $frame['function'] . '() at ' . $frame['file'] . ':' . $frame['line'];
                        break;
                    }
                }
                $line .= $where;
            }
            if (isset($this->flagGraph[$fiber])) {
                $line .= ', for coroutine #' . \spl_object_id($this->flagGraph[$fiber]);
            }
            $lines[] = $line;
        }
        $exception = new DeadlockException((1 === \count($lines) ? '1 coroutine waits and nothing can wake it' : \count($lines) . ' coroutines wait and nothing can wake them') . ":\n" . \implode("\n", $lines));
        $root      = $this->rootFrame ?? throw $exception;
        $failing   = null !== $root->fatal;
        $this->failRun($root, $exception);
        if ($failing) {
            $dropped = [];
            foreach ($this->pending as $fiber) {
                $dropped[] = $fiber;
            }
            foreach ($dropped as $fiber) {
                $this->discard($fiber);
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
     * A nested phasync::run(): $fn runs in the calling coroutine, in a new frame nested in the
     * coroutine's own. Coroutines $fn starts join the frame. phasync::finally() callbacks
     * registered in $fn run when it returns, still in the frame, shielded; then the call waits
     * for the frame's coroutines, and returns what $fn returned or throws (see finishFrame()).
     * Entered in another scope than the loop last made current (`$scope->run(fn () =>
     * phasync::run(...))`), it makes that scope current first: the onScopeChange() hooks run
     * before $fn does, so state they swap is the scope's own from $fn's first line.
     *
     * @internal phasync::run()
     */
    public function runNested(\Closure $fn, array $args): mixed
    {
        if (($scope = \Scope::getCurrent()) !== $this->liveScope) {
            $this->switchScope($scope, $this->currentFiber);
        }
        $fiber                                = $this->currentFiber;
        $id                                   = \spl_object_id($fiber);
        $outer                                = $this->currentFrame;
        $frame                                = ($this->newFrame)($outer);
        $frame->cancellationRequested         = $outer->cancellationRequested;
        $frame->fibers                        = $fiber;
        $frame->live                          = 1;
        $outer->inner[\spl_object_id($frame)] = $frame;
        $outerFinally                         = $this->frameFinally[$id] ?? null;
        $this->frameFinally[$id]              = [];
        $this->frames[$fiber]                 = $this->currentFrame = $frame;
        $failure                              = null;
        $result                               = null;
        try {
            try {
                $result = $fn(...$args);
            } catch (\Throwable $e) {
                $failure = $e;
            }
            if ([] !== ($callbacks = $this->frameFinally[$id])) {
                $this->frameFinally[$id] = [];
                $this->shield($fiber);
                try {
                    self::runFinally($callbacks);
                } catch (\Throwable $e) {
                    $failure = $e; // as a finally block's exception
                } finally {
                    $this->unshield($fiber);
                }
            }
            try {
                $this->awaitFrame($fiber, $frame);
            } catch (\Throwable $e) {
                $failure = $e;
            }
        } finally {
            if (null === $outerFinally) {
                unset($this->frameFinally[$id]);
            } else {
                $this->frameFinally[$id] = $outerFinally;
            }
            unset($outer->inner[\spl_object_id($frame)]);
            $this->leftFrame($frame, $fiber);
            $this->frames[$fiber] = $this->currentFrame = $outer;
        }

        return $this->finishFrame($frame, null, $failure, $result);
    }

    /**
     * Wait until $fiber is the only coroutine left in $frame. When $fiber is cancelled while it
     * waits, the frame's coroutines are cancelled too, and waited for (shielded: they must
     * unwind) before the cancellation goes on.
     */
    private function awaitFrame(\Fiber $fiber, Frame $frame): void
    {
        if ($frame->live <= 1 || self::$exiting) {
            return; // the caller alone: a request that started nothing (most); or PHP destroys the coroutines
        }
        try {
            while ($frame->live > 1) {
                \phasync::awaitFlag($frame);
            }
        } catch (CancelledException $e) {
            $this->cancelFrame($frame, new CancelledException('Cancelled: the run() was cancelled with ' . $e->getMessage(), 0, $e));
            $this->shield($fiber);
            try {
                while ($frame->live > 1) {
                    \phasync::awaitFlag($frame);
                }
            } finally {
                $this->unshield($fiber);
            }

            throw $e;
        }
    }

    /**
     * The run() of $frame ends: the failures of its coroutines nobody awaited are settled. With
     * one failure to report (its deadlock, what $fn threw - a TimeoutException when the frame's
     * deadline cancelled it -, or one nobody awaited) it throws that; with several, an
     * AggregateException of all of them; else it returns $result.
     */
    private function finishFrame(Frame $frame, ?\Fiber $main, ?\Throwable $failure, mixed $result): mixed
    {
        // A failure nobody took is this frame's, also while something still holds its coroutine;
        // run() takes its main coroutine's itself
        if (0 !== $this->fiberExceptionHolders->count()) {
            foreach ($this->fiberExceptionHolders as $fiber => $holder) {
                if ($fiber !== $main && !$holder->isHandled() && ($holder->frame === $frame || ($frame === $this->rootFrame && $holder->frame === $this->serviceFrame))) {
                    $holder->ended = true;
                    $holder->handleException();
                }
            }
        }
        if ([] !== $frame->handlerQueue) {
            $this->runExceptionHandler($frame);
        }
        $frame->ended = true;
        unset($this->deadlines[\spl_object_id($frame)]);
        // Every failure is reported: a deadlock, then $fn's, then the ones nobody awaited as they happened
        $report = [];
        if (null !== $frame->fatal) {
            $report[] = $frame->fatal;
        }
        if (null !== $failure) {
            $report[] = null !== $frame->timeout && self::causedBy($failure, $frame->timeout) ? $frame->timeout : $failure;
        }
        if ([] !== $frame->failures) {
            $failures = $frame->failures;
            \usort($failures, static fn (array $x, array $y) => $x[0] <=> $y[0]);
            foreach ($failures as [, $e]) {
                $report[] = $e;
            }
        }
        if ([] !== $report) {
            throw 1 === \count($report) ? $report[0] : new AggregateException($report);
        }

        return $result;
    }

    /**
     * The coroutines of $frame, and of the frames nested in it.
     *
     * @return list<\Fiber>
     */
    public function getFibers(Frame $frame): array
    {
        $fibers  = [];
        $members = $frame->fibers;
        if ($members instanceof \Fiber) {
            $fibers[] = $members;
        } elseif (null !== $members) {
            foreach ($members as $fiber => $_) {
                $fibers[] = $fiber;
            }
        }
        foreach ($frame->inner as $inner) {
            \array_push($fibers, ...$this->getFibers($inner));
        }

        return $fibers;
    }

    /**
     * Cancel every coroutine of $frame and of the frames nested in it (getFibers()), the
     * deepest in the tree of coroutines first, except the coroutine that cancels. Coroutines
     * that aren't waiting, because they run or are about to, meet it at their next wait; so
     * does the caller.
     */
    public function cancelFrame(Frame $frame, \Throwable $exception): void
    {
        $this->requestCancellation($frame);
        $this->issued[$exception] = true;
        if (null === $frame->cancellation) {
            ++$this->cancellations;
        }
        $frame->cancellation = $exception;
        $depths              = [];
        foreach ($this->getFibers($frame) as $fiber) {
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
    }

    /** Set the cancellation request flag of $frame and of the frames nested in it, see Frame::requestCancellation(). */
    public function requestCancellation(Frame $frame): void
    {
        $frame->cancellationRequested = true;
        foreach ($frame->inner as $inner) {
            $this->requestCancellation($inner);
        }
    }

    /** Set the deadline of $frame, see Frame::setTimeout(). */
    public function setDeadline(Frame $frame, float $deadline): void
    {
        $frame->deadline                              = $deadline;
        $this->deadlines[\spl_object_id($frame)]      = $frame;
    }

    private function joinFrame(Frame $frame, \Fiber $fiber): void
    {
        $members = $frame->fibers;
        if (null === $members) {
            $frame->fibers = $fiber;
        } elseif ($members instanceof \Fiber) {
            $frame->fibers           = new \WeakMap();
            $frame->fibers[$members] = true;
            $frame->fibers[$fiber]   = true;
        } else {
            $members[$fiber] = true;
        }
        ++$frame->live;
    }

    /** $fiber left $frame: it ended, or returned from the nested run() of $frame. */
    private function leftFrame(Frame $frame, \Fiber $fiber): void
    {
        $members = $frame->fibers;
        if ($members instanceof \Fiber) {
            $frame->fibers = null;
        } elseif (null !== $members) {
            unset($members[$fiber]);
        }
        if (--$frame->live <= 1) {
            $this->raiseFlag($frame); // a nested run() waits for its coroutines
            if (0 === $frame->live && null !== $frame->cancellation) {
                // No coroutine can join a frame that has none left
                $frame->cancellation = null;
                --$this->cancellations;
            }
        }
    }

    /**
     * A wait in an onScopeChange() hook, or in a Fiber the hook runs: it is undone, then refused.
     *
     * @throws \LogicException always
     */
    public function refuseHookWait(): never
    {
        $this->discard($this->currentFiber);

        throw new \LogicException('An onScopeChange() hook must not wait: it runs in the event loop, outside any coroutine; start a coroutine with phasync::go() for work that waits');
    }

    /**
     * Register a phasync::finally() callback: it runs as the nested run() the running coroutine
     * runs inline returns, or when the coroutine ends when it is in none.
     *
     * @internal
     */
    public function finally(\Closure $fn): void
    {
        $fiber = $this->currentFiber ?? throw ExceptionTool::popTrace(ExceptionTool::popTrace(new \LogicException('This function can not be used outside of a coroutine')));
        $id    = \spl_object_id($fiber);
        if (isset($this->frameFinally[$id])) {
            $this->frameFinally[$id][] = $fn;

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
    public function getPoller(): PollerInterface
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
        if ($seconds > 0) {
            $this->pending[$fiber] = \PHP_FLOAT_MAX;
            $this->scheduler->schedule($seconds + \microtime(true), $fiber);
        } else {
            $this->enqueue($fiber);
        }
    }

    /**
     * Cancel every coroutine but the caller, whatever its frame, with $exception, see
     * phasync::shutdown().
     */
    public function shutdown(ShutdownException $exception): void
    {
        foreach ($this->frames as $fiber => $_) {
            if ($fiber !== \Fiber::getCurrent() && !$fiber->isTerminated()) {
                $this->cancel($fiber, $exception);
            }
        }
    }

    /** The coroutines still alive but the caller, cleanup ones included. */
    public function countFibers(): int
    {
        $count = 0;
        foreach ($this->frames as $fiber => $_) {
            $count += (int) ($fiber !== \Fiber::getCurrent() && !$fiber->isTerminated());
        }

        return $count;
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
        if (!isset($this->frames[$fiber])) {
            throw new \LogicException('The fiber (' . Debug::getDebugInfo($fiber) . ') is not a phasync fiber');
        }
        $exception ??= new CancelledException('Operation cancelled');
        $this->issued[$exception] = true;
        if (!isset($this->cancelledFibers[$fiber])) {
            ++$this->cancellations;
        }
        $this->cancelledFibers[$fiber] = $exception;
        $this->wake($fiber); // the caller itself waits for nothing: it meets it at its next wait
    }

    /**
     * Interrupt the wait of $fiber with $exception, once; see phasync::throw().
     *
     * @throws \LogicException
     */
    public function throw(\Fiber $fiber, \Throwable $exception): void
    {
        if (!isset($this->frames[$fiber])) {
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
            if ($this->deliver($fiber, $exception)) {
                // The first time this fiber actually meets this cancellation; see checkCancelled().
                $this->cancellationFirstSeen[$fiber] = true;
            }
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
        if (0 !== $this->preempted && ($this->frozen[$this->fiberScopes[$fiber]] ?? null) === $fiber) {
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
        for ($f = $this->frames[$fiber] ?? null; null !== $f; $f = $f->parent) {
            if (null !== ($exception = $f->cancellation)) {
                return $exception;
            }
        }

        return null;
    }

    /**
     * At a wait: a cancelled coroutine throws its cancellation instead of waiting (callers check
     * $cancellations first), and what it registered to wait for is dropped.
     *
     * A second (or later) throw here for the same fiber means the cancellation reached it once
     * already (here or via wake()) and it is waiting again regardless -- most often a `finally`
     * block cleaning up after the first one, whose wait just failed the same way: marked, so
     * makeExceptionHolder() can warn if that is what ends up terminating the fiber.
     *
     * @internal
     */
    public function checkCancelled(\Fiber $fiber): void
    {
        if (null !== ($exception = $this->cancellationFor($fiber))) {
            $this->discard($fiber);
            if (isset($this->cancellationFirstSeen[$fiber])) {
                $this->cutShortCleanup[$fiber] = true;
            } else {
                $this->cancellationFirstSeen[$fiber] = true;
            }

            throw $exception;
        }
    }

    /**
     * $fiber's waits are out of every cancellation's reach until unshield(): phasync::finally()
     * callbacks complete also in a cancelled coroutine or frame.
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
        if (!isset($this->frames[$fiber])) {
            return false;
        }

        // Search for fibers waiting for IO
        if (!isset($this->pending[$fiber])) {
            return false;
        }

        do {
            $cancelled = false;
            // Parked: the slot is emptied
            $fiberId = \spl_object_id($fiber);
            if (isset($this->suspensions[$fiberId])) {
                // Waiting in a blocking call: no longer polled; its Suspension stays, for throwInto()
                unset($this->suspensions[$fiberId], $this->suspensionFibers[$fiberId]);
                --$this->pollerWaits;
                $cancelled = true;
                break;
            }
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

            // Held while its scope is frozen
            if (0 !== $this->preempted && isset($this->held[$scope = $this->fiberScopes[$fiber]])
                && false !== ($i = \array_search($fiber, $held = $this->held[$scope], true))) {
                \array_splice($held, $i, 1);
                $this->held[$scope] = $held;
                $cancelled          = true;
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

        // An idle() waiter also sits in this FIFO, outside flaggedFibers: drop it here too, or
        // it would swallow the next idle moment for a flag nobody is waiting on any more.
        if ([] !== $this->idleWaiters) {
            foreach ($this->idleWaiters as $i => $waiter) {
                if ($waiter[2] === $fiber) {
                    \array_splice($this->idleWaiters, $i, 1);
                    break;
                }
            }
        }

        if ($cancelled) {
            unset($this->pending[$fiber]);
            if ([] !== $this->timeoutSlots || !$this->exactTimeouts->isEmpty()) {
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
            $this->expireFiber($fiber);
        }
    }

    /** Delivers $fiber's timeout: thrown once, not sticky as a cancellation. */
    private function expireFiber(\Fiber $fiber): void
    {
        $id             = \spl_object_id($fiber);
        $throwOnTimeout = !isset($this->timeoutNoThrow[$id]);
        unset($this->timeoutNoThrow[$id]);
        if ($this->discard($fiber)) {
            if ($throwOnTimeout) {
                $this->enqueueWithException($fiber, new TimeoutException('Operation timed out for ' . Debug::getDebugInfo($fiber)));
            } else {
                // whenFlagged(..., throwOnTimeout: false): resume without constructing an
                // exception; awaitFlag() reads this marker once control returns to it.
                $this->timedOutWithoutThrow[$fiber] = true;
                $this->enqueueKnownLive($fiber);
            }
        }
    }

    private function addTimeout(\Fiber $fiber, float $deadline, bool $throwOnTimeout = true): void
    {
        $id = \spl_object_id($fiber);
        if ($deadline - \microtime(true) < self::TIMEOUT_SLOT_SECONDS) {
            // Rounding up to the next 10 ms slot would add up to a whole slot of latency on
            // top of a deadline this close already -- deliver it exactly instead (#139), the
            // same way sleep() does.
            $this->exactTimeouts->schedule($deadline, $fiber);
        } else {
            // A positive timeout always lands after the last slot handled (it is at most now).
            // Rounded up, so none fires early.
            $slot                             = (int) \ceil($deadline * 100);
            $this->timeoutBuckets[$slot][$id] = $fiber;
            $this->timeoutSlots[$id]          = $slot;
        }
        if (!$throwOnTimeout) {
            $this->timeoutNoThrow[$id] = true;
        }
    }

    /** $fiber no longer waits (it was woken or cancelled): its timeout goes. */
    private function removeTimeout(\Fiber $fiber): void
    {
        if ($this->exactTimeouts->contains($fiber)) {
            $this->exactTimeouts->cancel($fiber);
            unset($this->timeoutNoThrow[\spl_object_id($fiber)]);

            return;
        }
        $id = \spl_object_id($fiber);
        if (isset($this->timeoutSlots[$id])) {
            $slot = $this->timeoutSlots[$id];
            unset($this->timeoutSlots[$id], $this->timeoutBuckets[$slot][$id], $this->timeoutNoThrow[$id]);
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
     * destructor is invoked, the failure goes to the coroutine's frame.
     */
    private function makeExceptionHolder(\Throwable $exception, \Fiber $fiber): FiberExceptionHolder
    {
        if ($fiber->isTerminated() && $exception instanceof CancelledException && isset($this->cutShortCleanup[$fiber])) {
            // The fiber ended with a CancelledException after checkCancelled() noticed the
            // cancellation reaching it a second time: whatever it was still unwinding -- a
            // finally block's own cleanup wait, most often -- did not run to completion.
            unset($this->cutShortCleanup[$fiber]);
            \trigger_error(
                'Cleanup in a finally block of ' . Debug::getDebugInfo($fiber) . ' was cut short by its cancellation reaching it again; use phasync::shielded() for cleanup that must wait',
                \E_USER_WARNING
            );
        }
        $frame = $this->frames[$fiber] ?? null;
        $seq   = ++$this->failureSeq;

        return FiberExceptionHolder::create($exception, $fiber, function (\Throwable $exception) use ($frame, $seq) {
            // Nobody took the failure: it goes to the frame's handler, or its run() throws it
            $this->unhandled($frame, $exception, $seq);
        }, $frame);
    }

    /**
     * A failure no coroutine took: to the frame's exception handler, else kept for its run() to
     * throw. A service's goes to the outermost run()'s frame. Cancellations, also while PHP shuts
     * down (it destroys the coroutines still waiting), are no failures.
     */
    private function unhandled(?Frame $frame, \Throwable $exception, int $seq): void
    {
        if (self::$exiting && $exception instanceof CancelledException) {
            return;
        }
        if ($this->isCancellation($exception)) {
            return; // it was cancelled; it did not fail
        }
        if ($frame === $this->serviceFrame) {
            $frame = $this->rootFrame;
        }
        if (null === $frame || $frame->ended) {
            // Its run() ended before the failure was known: it is thrown where the last
            // reference goes, rather than lost
            throw $exception;
        }
        if (null !== $frame->exceptionHandler) {
            $frame->handlerQueue[] = $exception;
            if (1 === \count($frame->handlerQueue)) {
                // This may run in a destructor: the handler runs in the loop, from the next tick
                $this->defer(fn () => $this->runExceptionHandler($frame));
            }

            return;
        }
        $frame->failures[] = [$seq, $exception];
    }

    /** Give the failures waiting for $frame's exception handler to it; what it throws, its run() throws. */
    private function runExceptionHandler(Frame $frame): void
    {
        while ([] !== $frame->handlerQueue) {
            $exception = \array_shift($frame->handlerQueue);
            try {
                ($frame->exceptionHandler ?? throw $exception)($exception);
            } catch (\Throwable $e) {
                $frame->failures[] = [++$this->failureSeq, $e];
            }
        }
    }

    /** Whether a phasync::run() is in progress. */
    public function isRunning(): bool
    {
        return null !== $this->rootFrame;
    }

    /**
     * The outermost phasync::run() begins: its frame. The scope current in {main} is what ran last.
     *
     * @internal phasync::run()
     */
    public function beginRun(): Frame
    {
        $this->liveScope = \Scope::getCurrent();

        return $this->rootFrame = ($this->newFrame)(null);
    }

    /**
     * Run the loop for the outermost run() until its frame has no coroutine left; then cancel the
     * services, and run it until they have ended.
     *
     * @internal phasync::run()
     */
    public function drive(Frame $frame): void
    {
        while ($frame->live > 0 && $this->pending->count() > 0) {
            $this->tick();
        }
        if ($this->serviceFrame->live > 0) {
            $this->cancelFrame($this->serviceFrame, new CancelledException('Cancelled: the outermost run() returned'));
            while ($this->serviceFrame->live > 0 && $this->pending->count() > 0) {
                $this->tick();
            }
        }
        while (!$this->callbackQueue->isEmpty()) {
            [$scope, $callback] = $this->callbackQueue->dequeue();
            $scope->run($callback);
        }
    }

    /**
     * The outermost phasync::run() ends: as a nested one, see finishFrame(). The scope of {main}
     * becomes current again.
     *
     * @internal phasync::run()
     */
    public function endRun(Frame $frame, ?\Fiber $main): mixed
    {
        try {
            if (null !== $frame->fatal) {
                // The deadlock dropped the coroutines still waiting: they unwind as their last
                // references go, here, rather than in whatever runs next
                $main = null;
                try {
                    \gc_collect_cycles();
                } catch (\Throwable) {
                    // the deadlock is what run() throws
                }

                return $this->finishFrame($frame, null, null, null);
            }
            if (!$main->isTerminated()) {
                throw new \LogicException('The main coroutine of run() did not end'); // unreachable: the loop ran until it did
            }
            $failure = $this->getException($main);

            return $this->finishFrame($frame, $main, $failure, null === $failure ? $main->getReturn() : null);
        } finally {
            $this->closeRun($frame);
        }
    }

    /**
     * The outermost phasync::run() is over, also when it failed before endRun().
     *
     * @internal phasync::run()
     */
    public function closeRun(Frame $frame): void
    {
        if ($this->rootFrame !== $frame) {
            return;
        }
        $frame->ended    = true;
        $this->rootFrame = null;
        $this->deadlines = [];
        foreach ([$frame, $this->serviceFrame] as $f) {
            if (null !== $f->cancellation) {
                // Its coroutines ended, or a deadlock dropped them
                $f->cancellation = null;
                --$this->cancellations;
            }
        }
        if (($scope = \Scope::getCurrent()) !== $this->liveScope) {
            $this->switchScope($scope, null);
        }
    }

    /**
     * The outermost run() fails with $exception (a deadlock): its frame, and the services, are
     * cancelled, with a CancelledException whose previous exception is $exception; the
     * cancellations they unwind with are no failures. run() throws $exception.
     */
    private function failRun(Frame $frame, \Throwable $exception): void
    {
        if (null !== $frame->fatal) {
            return;
        }
        $frame->fatal = $exception;
        // This may run while the loop is iterating: from the next tick
        $this->defer(function () use ($frame, $exception) {
            $cancellation = new CancelledException('Cancelled: the run failed with ' . \get_class($exception) . ': ' . $exception->getMessage(), 0, $exception);
            $this->cancelFrame($frame, $cancellation);
            $this->cancelFrame($this->serviceFrame, $cancellation);
        });
    }

    /**
     * Whether $exception comes from a cancellation cancel(), Frame::cancel() or a deadlock issued:
     * a coroutine ending with it was cancelled, it did not fail (CAN-9).
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
     * Whenever a fiber is terminated, this method must be used. It runs the coroutine's
     * phasync::finally() callbacks (in a coroutine of their own), wakes its awaiters, and takes
     * it out of its frame. Cyclic garbage it leaves behind is collected by tick()'s idle-time or
     * busy-loop GC, not by this method.
     */
    private function handleTerminatedFiber(\Fiber $fiber): void
    {
        $frame = $this->frames[$fiber];
        if (isset($this->fiberFinally[$fiber])) {
            // The callbacks may suspend: they run in a coroutine of their own, in the frame the
            // coroutine ended in (which waits for it), shielded so they complete also when that
            // frame was cancelled
            $callbacks = $this->fiberFinally[$fiber];
            unset($this->fiberFinally[$fiber]);
            $this->create(function () use ($callbacks): void {
                $fiber = $this->currentFiber;
                $this->shield($fiber);
                try {
                    $this->enqueue($fiber); // after whatever else the ending coroutine's tick has to do
                    $this->suspending($fiber);
                    \Fiber::suspend();
                    self::runFinally($callbacks);
                } finally {
                    $this->unshield($fiber);
                }
            }, [], $frame, $this->fiberScopes[$fiber]);
        }
        if (0 !== $this->cancellations && isset($this->cancelledFibers[$fiber])) {
            unset($this->cancelledFibers[$fiber]);
            --$this->cancellations;
        }
        $this->raiseFlag($fiber);
        $this->leftFrame($frame, $fiber);
        unset($this->frames[$fiber], $this->fiberScopes[$fiber], $this->parentFibers[$fiber]);
    }
}
