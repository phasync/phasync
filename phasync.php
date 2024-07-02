<?php

use phasync\CancelledException;
use phasync\Context\ContextInterface;
use phasync\Context\DefaultContext;
use phasync\Debug;
use phasync\Drivers\DriverInterface;
use phasync\Drivers\StreamSelectDriver;
use phasync\Internal\AsyncStream;
use phasync\Internal\ChannelBuffered;
use phasync\Internal\ChannelUnbuffered;
use phasync\Internal\ExceptionTool;
use phasync\Internal\FiberSelector;
use phasync\Internal\ReadChannel;
use phasync\Internal\StreamSelector;
use phasync\Internal\Subscribers;
use phasync\Internal\WriteChannel;
use phasync\ReadChannelInterface;
use phasync\RethrowExceptionInterface;
use phasync\SelectableInterface;
use phasync\SelectorInterface;
use phasync\SubscribersInterface;
use phasync\TimeoutException;
use phasync\Util\WaitGroup;
use phasync\WriteChannelInterface;

/**
 * This class defines the essential API for all coroutine based applications.
 * This basic API enables implementing all forms of asynchronous programming,
 * including asynchronous CURL and database connections efficiently via the
 * use of flag signals {@see phasync::raiseFlag()} and {@see phasync::awaitFlag()}.
 *
 * The essential functions are:
 *
 * - {@see phasync::sleep()} to pause the coroutine and avoid wasting CPU cycles
 *   if there is nothing to do.
 * - {@see phasync::stream()} to pause the coroutine until a stream resource becomes
 *   readable, writable or both.
 * - {@see phasync::raiseFlag()} and {@see phasync::awaitFlag()} to pause the coroutine
 *   until an trigger occurs.
 *
 * It is bad practice for any advanced functionality to check for external events
 * on every tick, so it should use sleep(), stream() or raiseFlag()/awaitFlag() to
 * block between each poll.
 *
 * For example, to monitor curl handles using multi_curl, a separate coroutine would be
 * launched using {@see phasync::go()} which will invoke curl_multi_exec(). It should
 * invoke {@see phasync::sleep(0.1)} or so, to avoid busy loops and ideally a single
 * such service coroutine manages all the curl handles across the application. Fibers
 * that need notification would invoke phasync::awaitFlag($curlHandle) and the manager
 * coroutine would invoke phasync::raiseFlag($curlHandle) when the $curlHandle is done.
 */
final class phasync
{
    /**
     * Block the coroutine until the stream becomes readable.
     * {@see phasync::stream()}
     */
    public const READABLE = DriverInterface::STREAM_READ;

    /**
     * Block the coroutine until the stream becomes writable.
     * {@see phasync::stream()}
     */
    public const WRITABLE = DriverInterface::STREAM_WRITE;

    /**
     * Block the coroutine until the stream has an except state
     * (out-of-band date etc) {@see \stream_select()} for more
     * details.
     *
     * {@see phasync::stream()}
     */
    public const EXCEPT = DriverInterface::STREAM_EXCEPT;

    /**
     * The default timeout in seconds used throughout the library,
     * unless another timeout is configured via
     * {@see phasync::setDefaultTimeout()}
     */
    public const DEFAULT_TIMEOUT = 30.0;

    /**
     * This is the number of microseconds that a coroutine can run
     * before it is *volunteeringly* preempted by invoking the
     * {@see phasync::preempt()} function. When the coroutine has
     * run for this number of microseconds, the phasync::preempt()
     * function will suspend the coroutine and allow other
     * coroutines to run.
     *
     * Number is in nanoseconds, measured using \hrtime(true), the
     * default is 50 ms.
     */
    public const DEFAULT_PREEMPT_INTERVAL = 50000000;

    /**
     * The recursion depth of run statements that are active
     */
    private static int $runDepth = 0;

    /**
     * The currently configured timeout in seconds.
     */
    private static float $timeout = 30;

    /**
     * The currently set driver.
     */
    private static ?DriverInterface $driver = null;

    /**
     * A function that sets an onFulfilled and/or an onRejected callback on
     * a promise.
     *
     * @var null|Closure{object, ?Closure{mixed}, ?Closure{mixed}, false}
     */
    private static ?Closure $promiseHandlerFunction = null;

    /**
     * The configurable preempt interval that can be set using the
     * {@see phasync::setPreemptInterval()} function.
     *
     * @var int number of nanoseconds
     */
    private static int $preemptInterval = self::DEFAULT_PREEMPT_INTERVAL;

    /**
     * The last time that {@see phasync::preempt()) was invoked. This means
     * that the first call to phasync::preempt() will always yield.
     *
     * @var int number in nanoseconds from \hrtime(true)
     */
    private static int $lastPreemptTime = 0;

    private static array $onEnterCallbacks = [];
    private static array $onExitCallbacks  = [];

    /**
     * Register a coroutine/Fiber to run in the event loop and await the result.
     * Running a coroutine this way also ensures that the event loop will run
     * until all nested coroutines have completed. If you want to create a coroutine
     * inside this context, and leave it running after - the coroutine must be
     * created from within another coroutine outside of the context, for example by
     * using a Channel.
     *
     * @throws FiberError
     * @throws Throwable
     */
    public static function run(Closure $fn, ?array $args=[], ?ContextInterface $context=null): mixed
    {
        $driver = self::getDriver();
        try {
            $runDepth = self::$runDepth++;
            if (0 === $runDepth) {
                \gc_disable();

                // Run hooks when async context is enabled
                foreach (self::$onEnterCallbacks as $exitCallback) {
                    $exitCallback();
                }
            }

            if (null === $context) {
                $context = new DefaultContext();
            }

            $exception = null;

            try {
                $fiber = $driver->create($fn, $args, $context);
            } catch (Throwable $e) {
                unset($fiber);
                $exception = $e;
            }

            if (0 === $runDepth) {
                while ($driver->count() > 0) {
                    $driver->tick();
                }
            } else {
                while ($context->getFibers()->count() > 0) {
                    self::yield();
                }
            }

            if (null !== $exception) {
                throw $exception;
            }

            $result = self::await($fiber);

            if ($exception = $context->getContextException()) {
                throw $exception;
            }

            return $result;
        } catch (CancelledException $e) {
            if ($fiber->isTerminated()) {
                throw $e;
            }
            phasync::cancel($fiber);
            $result = phasync::await($fiber);

            if ($exception = $context->getContextException()) {
                throw $exception;
            }

            return $result;
        } finally {
            if (0 === --self::$runDepth) {
                \gc_enable();
                // Run hooks when async context is enabled
                foreach (self::$onExitCallbacks as $exitCallback) {
                    $exitCallback();
                }
            }
        }
    }

    /**
     * Creates a normal coroutine and starts running it. The coroutine will be associated
     * with the current context, and will block the current coroutine from completing
     * until it is done by returning or throwing.
     *
     * If parameter `$concurrent` is greater than 1, the returned coroutine will resolve
     * into an array of return values or exceptions from each instance of the coroutine.
     *
     * @param Closure               $fn         The function to run as a coroutine
     * @param array                 $args       The arguments to pass to the function
     * @param int                   $concurrent Run the coroutine multiple times
     * @param ContextInterface|null $context    Set a new context interface
     * @param bool                  $run        If true, the coroutine will be run in an event loop context
     *
     * @throws LogicException
     */
    public static function go(Closure $fn, array $args=[], int $concurrent = 1, ?ContextInterface $context=null, bool $run=false): Fiber
    {
        if ($concurrent > 1) {
            if (null !== $context && 0 === self::$runDepth) {
                throw new LogicException("Can't create concurrent root coroutines sharing a context");
            }

            if ($run) {
                throw new LogicException("Can't combine `run=true` with multiple concurrency.");
            }

            return self::go(fn: static function ($fn, $args, $concurrent) {
                $coroutines = [];
                for ($i = 0; $i < $concurrent; ++$i) {
                    $coroutines[] = self::go($fn, $args);
                }
                $results = [];
                foreach ($coroutines as $fiber) {
                    try {
                        $results[] = self::await($fiber);
                    } catch (Throwable $e) {
                        $results[] = $e;
                    }
                }

                return $results;
            }, args: [$fn, $args, $concurrent]);
        }
        $driver = self::getDriver();
        $fiber  = $driver->getCurrentFiber();
        if (!$fiber) {
            if ($run) {
                $result = phasync::run($fn, $args, $context);

                return new Fiber(static function () use ($result) {
                    return $result;
                });
            }
            throw ExceptionTool::popTrace(new LogicException("Can't create a coroutine outside of a context. Use `phasync::run()` to launch a context."));
        }
        $result = $driver->create($fn, $args, $context);

        // Since coroutines start immediately, launching coroutines can effectively
        // cause a busy loop. The preempt below enables coroutines to proceed while
        // this launching is going on.
        self::preempt();

        return $result;
    }

    /**
     * Launches a service coroutine independently of the context scope.
     * This service will be permitted to continue but MUST stop running
     * when it is no longer providing services to other fibers. Failing
     * to do so will cause the topmost run() context to keep running.
     */
    public static function service(Closure $coroutine): void
    {
        $driver = self::getDriver();
        $fiber  = $driver->getCurrentFiber();
        if (null === $fiber || null === $driver->getContext($fiber)) {
            throw new LogicException('Services must be started on-demand inside a coroutine.');
        }
        $driver->runService($coroutine);
    }

    /**
     * Wait for a coroutine or promise to complete and return the result.
     * If exceptions are thrown in the coroutine, they will be thrown here.
     *
     * @param float $timeout the number of seconds to wait at most
     *
     * @throws TimeoutException if the timeout is reached
     * @throws Throwable
     */
    public static function await(object $fiberOrPromise, ?float $timeout=null): mixed
    {
        $timeout      = $timeout ?? self::getDefaultTimeout();
        $startTime    = \microtime(true);
        $driver       = self::getDriver();
        $currentFiber = $driver->getCurrentFiber();

        if ($fiberOrPromise instanceof Fiber) {
            if ($fiberOrPromise->isTerminated()) {
                try {
                    return $fiberOrPromise->getReturn();
                } catch (FiberError) {
                    throw $driver->getException($fiberOrPromise);
                }
            }
            $fiber = $fiberOrPromise;
            if (!$driver->getContext($fiber)) {
                throw new LogicException("Can't await a coroutine not from phasync");
            }
        } else {
            // Convert this promise into a Fiber
            $fiber = self::go(static function () use ($fiberOrPromise) {
                // May be a Promise
                $status = null;
                $result = null;
                if (!self::handlePromise($fiberOrPromise, static function (mixed $value) use (&$status, &$result) {
                    if (null !== $status) {
                        throw new LogicException('Promise resolved or rejected twice');
                    }
                    $status = true;
                    $result = $value;
                }, static function (mixed $error) use (&$status, &$result) {
                    if (null !== $status) {
                        throw new LogicException('Promise resolved or rejected twice');
                    }
                    $status = false;
                    $result = $error;
                })) {
                    throw new InvalidArgumentException('The awaited object must be a Fiber or a promise-like object');
                }
                // Allow the promise-like object to resolve
                while (null === $status) {
                    self::yield();
                }
                if ($status) {
                    return $result;
                } elseif ($result instanceof Throwable) {
                    throw $result;
                }
                throw new Exception((string) $result);
            });
        }

        if ($currentFiber) {
            // We are in a Fiber
            while (!$fiber->isTerminated()) {
                $elapsed   = \microtime(true) - $startTime;
                $remaining = $timeout - $elapsed;
                if ($remaining < 0) {
                    throw new TimeoutException('The coroutine did not complete in time');
                }
                $driver->whenFlagged($fiber, $remaining, $currentFiber);
                self::suspend();
            }
        } else {
            /*
             * @todo Move this to the phasync::run() method.
             */
            while (!$fiber->isTerminated()) {
                $elapsed   = \microtime(true) - $startTime;
                $remaining = $timeout - $elapsed;
                if ($remaining < 0) {
                    throw new TimeoutException('The coroutine (' . Debug::getDebugInfo($fiber) . ') did not complete in time');
                }
                $driver->tick();
            }
        }
        if (null !== ($exception = $driver->getException($fiber))) {
            throw $exception;
        }

        return $fiber->getReturn();
    }

    /**
     * Block until one of the selectable objects or fibers terminate
     *
     * @param (SelectableInterface|Fiber)[] $selectables
     * @param resource[]                    $read        Wait for stream resources to become readable
     * @param resource[]                    $write       Wait for stream resources to become writable
     *
     * @throws LogicException
     * @throws FiberError
     * @throws Throwable
     *
     * @return SelectableInterface|resource|Fiber
     */
    public static function select(array $selectables, ?float $timeout=null, ?array $read=null, ?array $write=null): mixed
    {
        if (null === self::getDriver()->getCurrentFiber()) {
            throw new LogicException("Can't use phasync::select() outside of phasync. Use `phasync::run()` to launch a context.");
        }
        $returnables = [];

        $stopTime   = \microtime(true) + ($timeout ?? self::getDefaultTimeout());
        $selectFlag = new stdClass();
        try {
            /*
             * Validate that all selectables are valid and must be awaited,
             * and convert any supported alterative resources into selectors.
             */
            foreach ($selectables as $k => $selectable) {
                if ($selectable instanceof Fiber) {
                    if ($selectable->isTerminated()) {
                        return $selectable;
                    }
                    $fiberSelector   = FiberSelector::create($selectable);
                    $returnables[]   = $fiberSelector;
                    $selectables[$k] = $fiberSelector;
                } elseif ($selectable instanceof SelectableInterface) {
                    if (!$selectable->selectWillBlock()) {
                        return $selectable;
                    }
                } else {
                    throw new InvalidArgumentException('Unexpected ' . \get_debug_type($selectable) . ' in phasync::select()');
                }
            }
            /*
             * Convert any stream resources waiting to be readable into
             * StreamSelectors.
             */
            if (null !== $read) {
                foreach ($read as $r) {
                    $selector      = StreamSelector::create($r, DriverInterface::STREAM_READ);
                    $selectables[] = $selector;
                    $returnables[] = $selector;
                }
            }
            /*
             * Convert any stream resources waiting to be writable into
             * StreamSelectors.
             */
            if (null !== $write) {
                foreach ($write as $w) {
                    $selector      = StreamSelector::create($w, DriverInterface::STREAM_WRITE);
                    $selectables[] = $selector;
                    $returnables[] = $selector;
                }
            }

            foreach ($selectables as $selectable) {
                $selectable->getSelectManager()->addFlag($selectFlag);
            }
            while (true) {
                try {
                    self::awaitFlag($selectFlag, $stopTime - \microtime(true));
                    foreach ($selectables as $selectable) {
                        if (!$selectable->selectWillBlock()) {
                            if ($selectable instanceof SelectorInterface) {
                                return $selectable->getSelected();
                            }

                            return $selectable;
                        }
                    }
                } catch (TimeoutException) {
                    return null;
                }
            }
        } finally {
            foreach ($selectables as $selectable) {
                if ($selectable instanceof SelectableInterface) {
                    $selectable->getSelectManager()->removeFlag($selectFlag);
                }
            }
            foreach ($returnables as $returnable) {
                $returnable->returnToPool();
            }
        }
    }

    /**
     * Schedule a closure to run when the current coroutine completes. This function
     * is intended to be used when a coroutine uses a resource that must be cleaned
     * up when the coroutine finishes. Note that it may be more efficient to use a
     * try {} finally {} statement.
     */
    public static function finally(Closure $fn): void
    {
        static $queues = null;
        if (null === $queues) {
            /**
             * WeakMap allows this function to add more callbacks to the
             * same coroutine.
             *
             * @var WeakMap<Fiber, Closure[]>
             */
            $queues = new WeakMap();
        }
        $fiber = self::getFiber();

        if (!isset($queues[$fiber])) {
            $queues[$fiber] = [];
        }
        if (empty($queues[$fiber])) {
            self::go(static function () use ($fiber, $queues) {
                try {
                    self::await($fiber);
                } catch (Throwable) {
                }
                while (!empty($queues[$fiber])) {
                    \array_pop($queues[$fiber])();
                }
                unset($queues[$fiber]);
            });
        }
        $queues[$fiber][] = $fn;
    }

    /**
     * Cancel a suspended coroutine. This will throw an exception inside the
     * coroutine. If the coroutine handles the exception, it has the opportunity
     * to clean up any resources it is using. The coroutine MUST be suspended
     * using either {@see phasync::await()}, {@see phasync::sleep()}, {@see phasync::stream()}
     * or {@see phasync::awaitFlag()}.
     *
     * @throws RuntimeException if the fiber is not currently blocked
     */
    public static function cancel(Fiber $fiber, ?Throwable $exception=null): void
    {
        if ($fiber->isTerminated()) {
            throw new InvalidArgumentException('Fiber is already terminated');
        }
        self::getDriver()->cancel($fiber, $exception);
    }

    /**
     * Suspend the coroutine when it has been running for a configurable number of
     * microseconds. This function is designed to be invoked from within busy loops,
     * to allow other tasks to be performed. Use it at strategic places in library
     * functions that do not naturally suspend - and on strategic places in slow
     * calculations (avoiding invoking it on every iteration if possible).
     *
     * This function is highly optimized, but it benefits a lot from JIT because it
     * seems to be inlined.
     */
    public static function preempt(): void
    {
        try {
            $elapsed = ($now = \hrtime(true)) - self::$lastPreemptTime;
            if ($elapsed > self::$preemptInterval) {
                if (null === self::getDriver()->getCurrentFiber()) {
                    // Minimize cost of calling this outside of phasync
                    return;
                }
                if (0 === self::$lastPreemptTime) {
                    // This check is too costly to perform on every preempt()
                    // call, so we'll just set it here and wait for the next call.
                    self::$lastPreemptTime = $now;
                } else {
                    $driver                = self::getDriver();
                    self::$lastPreemptTime = $now;
                    $driver->enqueue($driver->getCurrentFiber());
                    self::suspend();
                }
            }
        } catch (Throwable) {
            // Ignore; this function must never throw
        }
    }

    /**
     * Yield time so that other coroutines can continue processing. Note that
     * if you intend to wait for something to happen in other coroutines, you
     * should use {@see phasync::yield()}, which will suspend the coroutine until
     * after any other fibers have done some work.
     *
     * @param float $seconds If null, the coroutine won't be resumed until another coroutine resumes
     *
     * @throws RuntimeException
     */
    public static function sleep(float $seconds=0): void
    {
        $driver = self::getDriver();
        $fiber  = $driver->getCurrentFiber();
        if ($seconds <= 0) {
            if (null === $fiber) {
                return;
            }
            $driver->enqueue($fiber);
            self::suspend();
        } else {
            if (null === $fiber) {
                \usleep((int) (1000000 * $seconds));
            } else {
                $driver->whenTimeElapsed($seconds, $fiber);
                self::suspend();
            }
        }
    }

    /**
     * Suspend the fiber until immediately after some other fibers has performed
     * work. Suspending a fiber this way will not cause a busy loop. If you intend
     * to perform work actively, you should use {@see phasync::sleep(0)}
     * instead.
     */
    public static function yield(): void
    {
        $driver = self::getDriver();
        $fiber  = $driver->getCurrentFiber();
        if (null === $fiber) {
            return;
        }
        $driver->afterNext($fiber);
        self::suspend();
    }

    /**
     * Suspend the current fiber until the event loop becomes empty or will sleeps while
     * waiting for future events.
     */
    public static function idle(?float $timeout=null): void
    {
        $driver = self::getDriver();
        $fiber  = $driver->getCurrentFiber();
        if (null === $fiber) {
            return;
        }
        $timeout = $timeout ?? self::getDefaultTimeout();
        $driver->whenIdle($timeout, $fiber);
        try {
            self::suspend();
        } catch (TimeoutException) {
            // Timeouts are not errors
        }
    }

    /**
     * Make any stream resource context switch between coroutines when
     * they would block.
     *
     * @return false|resource
     */
    public static function io($resource)
    {
        if (!\is_resource($resource) || 'stream' !== \get_resource_type($resource)) {
            return $resource;
        }

        return AsyncStream::wrap($resource);
    }

    /**
     * Utility function to suspend the current fiber until a stream resource becomes readable,
     * by wrapping `phasync::stream($resource, $timeout, phasync::READABLE)`.
     *
     * @param resource $resource
     *
     * @throws FiberError
     * @throws Throwable
     *
     * @return resource Returns the same resource for convenience
     */
    public static function readable(mixed $resource, ?float $timeout=null): mixed
    {
        self::stream($resource, self::READABLE, $timeout);

        return $resource;
    }

    /**
     * Utility function to suspend the current fiber until a stream resource becomes readable,
     * by wrapping `phasync::stream($resource, $timeout, phasync::WRITABLE)`.
     *
     * @param resource $resource
     *
     * @throws FiberError
     * @throws Throwable
     *
     * @return resource Returns the same resource for convenience
     */
    public static function writable(mixed $resource, ?float $timeout=null): mixed
    {
        self::stream($resource, self::WRITABLE, $timeout);

        return $resource;
    }

    /**
     * Block the coroutine until the stream resource becomes readable, writable or raises
     * an exception or any combination of these.
     *
     * The bitmaps use self::READABLE, self::WRITABLE and self::EXCEPT.
     *
     * @param int $mode a bitmap indicating which events on the resource that should resume the coroutine
     *
     * @return int A bitmap indicating which events on the resource that was raised
     */
    public static function stream(mixed $resource, int $mode = self::READABLE | self::WRITABLE, ?float $timeout=null): int
    {
        if (!\is_resource($resource) || 'stream' !== \get_resource_type($resource)) {
            return 0;
        }

        if (0 === self::$runDepth) {
            $metadata = \stream_get_meta_data($resource);
            if ($metadata['blocked'] ?? false) {
                // No point in blocking here; instead the fwrite/fread call will block
                return $mode & (self::READABLE | self::WRITABLE);
            }
        } else {
            \stream_set_blocking($resource, false);
        }

        try {
            // check using the event loop
            $driver = self::getDriver();
            if ($fiber = $driver->getCurrentFiber()) {
                $timeout = $timeout ?? self::getDefaultTimeout();
                $driver->whenResourceActivity($resource, $mode, $timeout, $fiber);
                self::suspend();

                return $driver->getLastResourceState($resource);
            }

            // The functionality should work on non-blocking resources even outside of phasync
            $stopTime = \microtime(true) + ($timeout ?? self::getDefaultTimeout());
            while (true) {
                $r = $w = $e = [];
                if ($mode & self::READABLE) {
                    $r[] = $resource;
                }
                if ($mode & self::WRITABLE) {
                    $w[] = $resource;
                }
                if ($mode & self::EXCEPT) {
                    $e[] = $resource;
                }
                $count = \stream_select($r, $w, $e, 0, 1000000);
                if (\is_int($count) && $count > 0) {
                    $result = 0;
                    if (!empty($r)) {
                        $result |= self::READABLE;
                    }
                    if (!empty($w)) {
                        $result |= self::WRITABLE;
                    }
                    if (!empty($e)) {
                        $result |= self::EXCEPT;
                    }

                    return $result;
                }
                if ($stopTime < \microtime(true)) {
                    throw new TimeoutException('The operation timed out');
                }
            }
        } finally {
        }
    }

    /**
     * Creates a channel pair which can be used to communicate between multiple
     * coroutines. Channels should be used to pass serializable data, to support
     * passing channels to worker processes, but it is possible to pass more
     * complex data if you are certain the data will not be passed to other
     * processes.
     *
     * If a function is passed in either argument, it will be run a coroutine
     * with the ReadChannelInterface or the WriteChannelInterface as the first
     * argument.
     */
    public static function channel(?ReadChannelInterface &$read, ?WriteChannelInterface &$write, int $bufferSize=0): void
    {
        if (0 === $bufferSize) {
            $channel = new ChannelUnbuffered();
            $read    = new ReadChannel($channel);
            $write   = new WriteChannel($channel);
        } else {
            $channel = new ChannelBuffered($bufferSize);
            $read    = new ReadChannel($channel);
            $write   = new WriteChannel($channel);
        }
    }

    /**
     * A publisher works like channels, but supports many subscribing coroutines
     * concurrently.
     */
    public static function publisher(?SubscribersInterface &$subscribers, ?WriteChannelInterface &$publisher): void
    {
        self::channel($internalReadChannel, $publisher, 0);
        $subscribers = new Subscribers($internalReadChannel);
    }

    /**
     * Wait groups are used to coordinate multiple coroutines. A coroutine can add work
     * to the wait group when the coroutine begins processing the task, and then notify
     * the wait group that the work is done.
     *
     * @deprecated it's generally better to just construct `new WaitGroup()`
     */
    public static function waitGroup(): WaitGroup
    {
        return new WaitGroup();
    }

    /**
     * Signal all coroutines that are waiting for an event represented
     * by the object $signal to resume.
     *
     * @return int the number of resumed fibers
     */
    public static function raiseFlag(object $signal): int
    {
        return self::getDriver()->raiseFlag($signal);
    }

    /**
     * Pause execution of the current coroutine until an event is signalled
     * represented by the object $signal. If the timeout is reached, this function
     * throws TimeoutException.
     *
     * @throws TimeoutException if the timeout is reached
     * @throws Throwable
     */
    public static function awaitFlag(object $signal, ?float $timeout=null): void
    {
        $driver = self::getDriver();
        $fiber  = $driver->getCurrentFiber();
        if (null === $fiber) {
            throw ExceptionTool::popTrace(new LogicException('Can only await flags from within a coroutine'));
        }

        $driver->whenFlagged($signal, $timeout ?? self::getDefaultTimeout(), $fiber);
        self::suspend();
    }

    /**
     * Returns true when called from within a coroutine context.
     */
    public static function isRunning(): bool
    {
        return self::$runDepth > 0;
    }

    /**
     * Get the currently running coroutine. If there is no currently
     * running coroutine, throws LogicException.
     *
     * @throws LogicException
     */
    public static function getFiber(): Fiber
    {
        $fiber = self::getDriver()->getCurrentFiber();
        if (!$fiber) {
            throw new LogicException('This function can not be used outside of a coroutine');
        }

        return $fiber;
    }

    /**
     * Get the context of the currently running coroutine. The there is no
     * currently running coroutine, throws LogicException.
     *
     * @throws LogicException
     */
    public static function getContext(): ContextInterface
    {
        $context = self::getDriver()->getContext(self::getFiber());
        if (!$context) {
            throw new LogicException('This function can only be used inside a `phasync` coroutine');
        }

        return $context;
    }

    /**
     * Register a callback to be invoked whenever an application enters the event
     * loop via the top level `phasync::run()` call.
     *
     * @see phasync::onExit()
     */
    public static function onEnter(Closure $enterCallback): void
    {
        self::$onEnterCallbacks[] = $enterCallback;
    }

    /**
     * Register a callback to be invoked whenever an application exits the event
     * loop after a `phasync::run()` call.
     *
     * @see phasync::onEnter()
     */
    public static function onExit(Closure $exitCallback): void
    {
        self::$onExitCallbacks[] = $exitCallback;
    }

    /**
     * Set the interval between every time the {@see phasync::preempt()}
     * function will cause the coroutine to suspend running.
     */
    public static function setPreemptInterval(int $microseconds): void
    {
        self::$preemptInterval = \max(0, $microseconds * 1000);
    }

    /**
     * Configures handling of promises from other frameworks. The
     * `$promiseHandlerFunction` returns `false` if the value in
     * the first argument is not a promise. If it is a promise,
     * it attaches the `onFulfilled` and/or `onRejected` callbacks
     * from the second and third argument and returns true.
     *
     * @param Closure{mixed, Closure?, Closure?, bool} $promiseHandlerFunction
     */
    public static function setPromiseHandler(Closure $promiseHandlerFunction): void
    {
        self::$promiseHandlerFunction = $promiseHandlerFunction;
    }

    /**
     * Returns the current promise handler function. This enables extending
     * the functionality of the existing promise handler without losing the
     * other integrations. {@see phasync::setPromiseHandler()} for documentation
     * on the function signature.
     */
    public static function getPromiseHandler(): Closure
    {
        if (null === self::$promiseHandlerFunction) {
            self::$promiseHandlerFunction = static function (mixed $promiseLike, ?Closure $onFulfilled=null, ?Closure $onRejected=null): bool {
                if (!\is_object($promiseLike) || !\method_exists($promiseLike, 'then')) {
                    return false;
                }
                $rm = new ReflectionMethod($promiseLike, 'then');
                if ($rm->isStatic()) {
                    return false;
                }
                $onRejectedHandled = false;
                foreach ($rm->getParameters() as $index => $rp) {
                    if ($rp->hasType()) {
                        $rt = $rp->getType();
                        if ($rt instanceof ReflectionNamedType) {
                            if (
                                'mixed' !== $rt->getName()
                                && 'callable' !== $rt->getName()
                                && Closure::class !== $rt->getName()
                            ) {
                                return false;
                            }
                        }
                        // mixed type apparently
                    }
                    if ($rp->isVariadic()) {
                        // Can handle many arguments of this type
                        $onRejectedHandled = true;
                        break;
                    }
                    if (1 === $index) {
                        $onRejectedHandled = true;
                        // Can handle at least two arguments of this type
                        break;
                    }
                }

                if (null !== $onRejected && !$onRejectedHandled) {
                    // The promise does not handle $onRejected in the `then`
                    // method, so see if we find a `catch` method.
                    if (\method_exists($promiseLike, 'catch')) {
                        if (null !== $onFulfilled) {
                            $promiseLike->then($onFulfilled);
                        }
                        $promiseLike->catch($onRejected);

                        return true;
                    }

                    return false;
                }

                if (null !== $onFulfilled && null !== $onRejected) {
                    $promiseLike->then($onFulfilled, $onRejected);
                } elseif (null !== $onFulfilled) {
                    $promiseLike->then($onFulfilled);
                } elseif (null !== $onRejected) {
                    $promiseLike->then(null, $onRejected);
                }

                return true;
            };
        }

        return self::$promiseHandlerFunction;
    }

    /**
     * Set the driver implementation for the event loop. This must be
     * configured before this API is used and will throw a LogicException
     * if the driver has been implicitly set.
     *
     * @throws LogicException
     */
    public static function setDriver(DriverInterface $driver): void
    {
        if (null !== self::$driver) {
            throw new LogicException('The driver must be set before any async functionality is used');
        }

        self::$driver = $driver;
    }

    /**
     * Set the default timeout for coroutine blocking operations. When
     * a coroutine blocking operation times out, a TimeoutException
     * is thrown.
     */
    public static function setDefaultTimeout(float $timeout): void
    {
        self::$timeout = $timeout;
    }

    /**
     * Get the configured default timeout, which is used by all coroutine
     * blocking functions unless a custom timeout is specified.
     */
    public static function getDefaultTimeout(): float
    {
        return self::$timeout;
    }

    /**
     * Check immediately if the stream resource can be read, written or
     * is in an except state.
     *
     * @internal
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public static function streamPoll($resource): int
    {
        if (!\is_resource($resource) || 'stream' !== \get_resource_type($resource)) {
            throw new InvalidArgumentException('Expecting a valid stream resource');
        }
        $r     = $w = $e = [$resource];
        $count = \stream_select($r, $w, $e, 0, 0);
        if (false === $count) {
            throw new RuntimeException('Unable to poll stream resource');
        }
        $result = 0;
        if (!empty($r)) {
            $result |= self::READABLE;
        }
        if (!empty($w)) {
            $result |= self::WRITABLE;
        }
        if (!empty($e)) {
            $result |= self::EXCEPT;
        }

        return $result;
    }

    /**
     * Function is used internally to suspend coroutines and ensure
     * exceptions have a proper stack trace.
     *
     * @internal
     *
     * @throws FiberError
     * @throws Throwable
     */
    private static function suspend(): void
    {
        try {
            Fiber::suspend();
        } catch (RethrowExceptionInterface $e) {
            $e->rebuildStackTrace();
            throw $e;
        }
    }

    /**
     * Enqueue a Fiber with the event loop while throwing an exception in it. This is
     * an internal function intended for advanced use cases and the API may change
     * without notice.
     *
     * @internal
     *
     * @param Throwable|null $exception
     */
    public static function enqueueWithException(Fiber $fiber, Throwable $exception): void
    {
        self::getDriver()->enqueueWithException($fiber, $exception);
    }

    /**
     * Enqueue a Fiber with the event loop. This is an internal function intended
     * for advanced use cases and the API may change without notice.
     *
     * @internal
     */
    public static function enqueue(Fiber $fiber): void
    {
        self::getDriver()->enqueue($fiber);
    }

    /**
     * There should be no unhandled exceptions. However, in certain scenarios
     * there exists a possibility for unhandled exceptions to occur. This method
     * ensures that the exception will be logged or thrown out of the event loop
     * from the `phasync::run()` function.
     *
     * @deprecated this function will be removed when it is certain that phasync handles all edge cases
     *
     * @internal
     */
    public static function logUnhandledException(Throwable $exception): void
    {
        \error_log("UNHANDLED EXCEPTION:\n" . $exception->__toString() . "\nLogged from:" . (new Exception())->getTraceAsString(), $exception->getCode());
    }

    /**
     * Returns the driver instance for the application.
     */
    private static function getDriver(): DriverInterface
    {
        if (null === self::$driver) {
            self::$driver = new StreamSelectDriver();
        }

        return self::$driver;
    }

    /**
     * Integrate with Promise like objects.
     */
    private static function handlePromise(mixed $promiseLike, ?Closure $onFulfilled=null, ?Closure $onRejected=null): bool
    {
        return (self::getPromiseHandler())($promiseLike, $onFulfilled, $onRejected);
    }
}
