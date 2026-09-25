<?php

namespace phasync\Drivers;

use Fiber;
use phasync\CancelledException;
use phasync\Context\ContextInterface;
use phasync\Context\DefaultContext;
use phasync\Context\ServiceContext;
use phasync\Debug;
use phasync\Internal\ExceptionTool;
use phasync\Internal\FiberExceptionHolder;
use phasync\Internal\Flag;
use phasync\Internal\Scheduler;
use phasync\IOException;
use phasync\TimeoutException;
use WeakMap;

final class StreamSelectDriver implements DriverInterface
{
    /**
     * errno for a system call interrupted by a signal, as stream_select() reports it in its
     * warning ("Unable to select [4]: Interrupted system call").
     */
    private const EINTR = 4;

    /**
     * Holds the queue of fibers that will be activated on the next
     * invocation of {@see StreamSelectDriver::tick()}.
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
     * All fibers suspended waiting for IO: the resource, the mode and the resource id.
     *
     * @var \WeakMap<\Fiber,array{0: resource, 1: int, 2: int}>
     */
    private \WeakMap $streams;

    /**
     * The fiber waiting to read (or for out of band data) and the fiber waiting to write,
     * per resource id. A stream has at most one waiting fiber per direction, so these and
     * the stream_select() arrays below are kept up to date as waits start and end, instead
     * of being rebuilt from all waiting fibers on every tick.
     *
     * @var array<int, \Fiber>
     */
    private array $readWaiters = [];

    /**
     * @var array<int, \Fiber>
     */
    private array $writeWaiters = [];

    /**
     * The streams to pass to stream_select(), per resource id.
     *
     * @var array<int, resource>
     */
    private array $readSet = [];

    /**
     * @var array<int, resource>
     */
    private array $writeSet = [];

    /**
     * @var array<int, resource>
     */
    private array $exceptSet = [];

    /**
     * The most recent stream select result for a fiber.
     *
     * @var \WeakMap<\Fiber,int>
     */
    private \WeakMap $streamResults;

    /**
     * Holds a reference to fibers that are waiting for a flag to be
     * raised. The Flag object will automatically resume all fibers
     * if the object is garbage collected.
     *
     * @var \WeakMap<object, Flag>
     */
    private \WeakMap $flaggedFibers;

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
    private bool $useExtSelect;

    /**
     * Create a new StreamSelectDriver instance.
     */
    public function __construct()
    {
        $this->useExtSelect = \function_exists('phasync\ext\stream_select');
        $this->clear();
    }

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
        $this->fiberExceptionHolders = new \WeakMap();
        $this->fiberExceptions       = new \WeakMap();
        $this->scheduler             = new Scheduler();
        $this->flaggedFibers         = new \WeakMap();
        $this->flagGraph             = new \WeakMap();
        $this->idleFlag              = new \stdClass();
        $this->afterNextFlag         = new \stdClass();
        $this->serviceContext        = new ServiceContext();
        $this->streams               = new \WeakMap();
        $this->streamResults         = new \WeakMap();
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
            'streams'               => $this->streams->count(),
            'streamResults'         => $this->streamResults->count(),
        ];

        return $result;
    }

    public function count(): int
    {
        return $this->pending->count();
    }

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

        if ($maxSleepTime > 0 && $afterNextCount > 0 && 0 === $queue->count() && 0 === $this->streams->count() && $this->scheduler->isEmpty()) {
            // echo "Setting sleep 0 (afterNextCount=$afterNextCount streams=" . $this->streams->count() . ")\n";
            /*
            foreach ($this->flaggedFibers as $flag => $fh) {
                echo Debug::getDebugInfo($flag) . ":\n";
                $fh->listFibers();
            }
            */
            $maxSleepTime = 0;
        }

        if ($now - $this->lastIdleRun > 1 || ($idleCount > 0 && $maxSleepTime > 0)) {
            // Raise the idle flag
            $this->lastIdleRun = $now;
            $this->raiseFlag($this->idleFlag);
        }

        /*
         * Activate any Fibers waiting for stream activity
         */
        if (0 !== $this->streams->count()) {
            $reads   = $this->readSet;
            $writes  = $this->writeSet;
            $excepts = $this->exceptSet;

            $selectWarning = null;
            \set_error_handler(static function (int $code, string $message) use (&$selectWarning): bool {
                $selectWarning = $message;

                return true;
            });
            try {
                $seconds      = (int) $maxSleepTime;
                $microseconds = (int) (($maxSleepTime - $seconds) * 1000000);
                $result       = $this->useExtSelect
                    ? \phasync\ext\stream_select($reads, $writes, $excepts, $seconds, $microseconds)
                    : \stream_select($reads, $writes, $excepts, $seconds, $microseconds);
            } catch (\TypeError|\ValueError $e) {
                // A stream was closed while a fiber waited on it (ValueError when no open
                // stream is left to select on). Rare, so only now look for it.
                $closed = [];
                foreach ($this->streams as $fiber => [$resource]) {
                    if (!\is_resource($resource)) {
                        $closed[] = $fiber;
                    }
                }
                if (!$closed) {
                    throw $e; // not a closed stream, for example one stream_select() can't wait on
                }
                foreach ($closed as $fiber) {
                    $this->endStreamWait($fiber);
                    $this->streamResults[$fiber] = 0;
                    $this->enqueueWithException($fiber, new IOException('Stream closed'));
                }
                $result = 0;
            } finally {
                \restore_error_handler();
            }

            if (false === $result && \str_contains($selectWarning ?? '', '[' . self::EINTR . ']')) {
                // A signal interrupted the wait. Nothing is ready yet; the waiters keep waiting.
                $result = 0;
            }

            if (false === $result) {
                // stream_select() failed for the whole batch, not just one resource -- for
                // example every watched stream has a file descriptor number >= FD_SETSIZE
                // (1024 on a typical POSIX build). Every fiber that was waiting this tick
                // must be told, loudly, or it would wait forever with no trace of why.
                $exception = new IOException(
                    'stream_select() failed for ' . $this->streams->count() . ' watched stream(s): '
                    . ($selectWarning ?? 'no error was reported')
                );
                $failed = [];
                foreach ($this->streams as $fiber => $_) {
                    $failed[] = $fiber;
                }
                foreach ($failed as $fiber) {
                    $this->endStreamWait($fiber);
                    $this->enqueueWithException($fiber, $exception);
                }
            } elseif ($result > 0) {
                $ready = [];
                foreach ($reads as $id => $_) {
                    $fiber                       = $this->readWaiters[$id];
                    $this->streamResults[$fiber] |= DriverInterface::STREAM_READ;
                    $ready[]                     = $fiber;
                }
                foreach ($excepts as $id => $_) {
                    $fiber                       = $this->readWaiters[$id];
                    $this->streamResults[$fiber] |= DriverInterface::STREAM_EXCEPT;
                    $ready[]                     = $fiber;
                }
                foreach ($writes as $id => $_) {
                    $fiber                       = $this->writeWaiters[$id];
                    $this->streamResults[$fiber] |= DriverInterface::STREAM_WRITE;
                    $ready[]                     = $fiber;
                }
                foreach ($ready as $fiber) {
                    if (isset($this->streams[$fiber])) {
                        $this->endStreamWait($fiber);
                        $this->enqueue($fiber);
                    }
                }
            }
        } elseif ($maxSleepTime > 0) {
            // There are no fibers waiting for afterNext, and the
            \usleep((int) ($maxSleepTime * 1000000));
        }

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
        }

        while (!$this->callbackQueue->isEmpty()) {
            $callback = $this->callbackQueue->dequeue();
            $callback();
        }
    }

    public function runService(\Closure $closure): void
    {
        $fiber = $this->create(closure: $closure, context: $this->serviceContext);
        unset($this->parentFibers[$fiber]);
    }

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

    public function getContext(\Fiber $fiber): ?ContextInterface
    {
        return $this->contexts[$fiber] ?? null;
    }

    public function raiseFlag(object $flag): int
    {
        if (!isset($this->flaggedFibers[$flag])) {
            return 0;
        }

        $fiberStore = $this->flaggedFibers[$flag];
        $count      = $fiberStore->raiseFlag();

        // Clean up empty Flag stores to prevent zombie references during GC
        if (0 === $fiberStore->count()) {
            unset($this->flaggedFibers[$flag]);
            $fiberStore->returnToPool();
        }

        return $count;
    }

    public function enqueue(\Fiber $fiber): void
    {
        if ($fiber->isTerminated()) {
            throw new \LogicException("Can't enqueue a terminated fiber (" . Debug::getDebugInfo($fiber) . ')');
        }
        // FiberState::for($fiber)->log("enqueued");
        $this->pending[$fiber] = \PHP_FLOAT_MAX;
        $this->queue->enqueue($fiber);
    }

    public function enqueueWithException(\Fiber $fiber, \Throwable $exception): void
    {
        if ($fiber->isTerminated()) {
            throw new \LogicException("Can't enqueue a terminated fiber (" . Debug::getDebugInfo($fiber) . ')');
        }
        // FiberState::for($fiber)->log("enqueued with " . \get_class($exception));
        $this->fiberExceptionHolders[$fiber] = $this->makeExceptionHolder($exception, $fiber);
        $this->enqueue($fiber);
    }

    public function afterNext(\Fiber $fiber): void
    {
        if (isset($this->pending[$fiber])) {
            throw new \LogicException('Fiber is already pending when scheduling with afterNext');
        }
        // FiberState::for($fiber)->log("afterNext");
        $this->whenFlagged($this->afterNextFlag, \PHP_FLOAT_MAX, $fiber);
    }

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

    public function defer(\Closure $callback): void
    {
        $this->callbackQueue->enqueue($callback);
    }

    public function whenIdle(float $timeout, \Fiber $fiber): void
    {
        if (isset($this->pending[$fiber])) {
            throw new \LogicException('Fiber is already pending in whenIdle');
        }
        // FiberState::for($fiber)->log('whenIdle timeout=' . $timeout);
        $this->whenFlagged($this->idleFlag, $timeout, $fiber);
    }

    public function whenResourceActivity(mixed $resource, int $mode, float $timeout, \Fiber $fiber): void
    {
        if (isset($this->pending[$fiber])) {
            throw new \LogicException('Fiber is already pending in whenResourceActivity');
        }
        if (!\is_resource($resource) || 'stream' !== \get_resource_type($resource)) {
            throw new \InvalidArgumentException('Expecting a stream resource type');
        }
        // FiberState::for($fiber)->log('whenResourceActivity (mode=' . $mode . ' timeout=' . $timeout . ')');
        $id    = \get_resource_id($resource);
        $read  = 0 !== ($mode & (DriverInterface::STREAM_READ | DriverInterface::STREAM_EXCEPT));
        $write = 0 !== ($mode & DriverInterface::STREAM_WRITE);
        if ($read && isset($this->readWaiters[$id])) {
            throw new \LogicException('Another coroutine is already waiting to read from this stream');
        }
        if ($write && isset($this->writeWaiters[$id])) {
            throw new \LogicException('Another coroutine is already waiting to write to this stream');
        }
        if ($read) {
            $this->readWaiters[$id] = $fiber;
            if ($mode & DriverInterface::STREAM_READ) {
                $this->readSet[$id] = $resource;
            }
            if ($mode & DriverInterface::STREAM_EXCEPT) {
                $this->exceptSet[$id] = $resource;
            }
        }
        if ($write) {
            $this->writeWaiters[$id] = $fiber;
            $this->writeSet[$id]     = $resource;
        }
        $this->streams[$fiber]       = [$resource, $mode, $id];
        $this->streamResults[$fiber] = 0;
        $this->pending[$fiber]       = \microtime(true) + $timeout;
    }

    /**
     * End a fiber's wait for stream activity.
     */
    private function endStreamWait(\Fiber $fiber): void
    {
        [, $mode, $id] = $this->streams[$fiber];
        unset($this->streams[$fiber]);
        if ($mode & (DriverInterface::STREAM_READ | DriverInterface::STREAM_EXCEPT)) {
            unset($this->readWaiters[$id], $this->readSet[$id], $this->exceptSet[$id]);
        }
        if ($mode & DriverInterface::STREAM_WRITE) {
            unset($this->writeWaiters[$id], $this->writeSet[$id]);
        }
    }

    public function getLastResourceState(\Fiber $fiber): ?int
    {
        // FiberState::for($fiber)->log('getLastResourceState');
        if (!isset($this->streamResults[$fiber])) {
            throw ExceptionTool::popTrace(new \RuntimeException('No resource state available for ' . Debug::getDebugInfo($fiber)), __FILE__);
        }

        $result = $this->streamResults[$fiber];

        return $result;
    }

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
            // May be waiting for IO
            if (isset($this->streams[$fiber])) {
                $cancelled = true;
                $this->endStreamWait($fiber);
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
     * Gets the exception for a terminated fiber and returns the ExceptionHolder instance
     * to the pool for reuse.
     *
     * {@inheritdoc}
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
        unset($this->contexts[$fiber], $this->parentFibers[$fiber], $this->streamResults[$fiber]);
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
