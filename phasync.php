<?php
use phasync\Context\ContextInterface;
use phasync\Context\DefaultContext;
use phasync\Debug;
use phasync\Drivers\DriverInterface;
use phasync\Drivers\StreamSelectDriver;
use phasync\Internal\BufferedProcessChannel;
use phasync\Internal\ChannelBuffered;
use phasync\Internal\ChannelUnbuffered;
use phasync\Internal\ChannelState2;
use phasync\Internal\ReadChannel;
use phasync\Internal\ReadChannel2;
use phasync\Internal\UnbufferedReadChannel;
use phasync\Internal\UnbufferedWriteChannel;
use phasync\Internal\WriteChannel;
use phasync\Internal\WriteChannel2;
use phasync\ReadChannelInterface;
use phasync\ReadSelectableInterface;
use phasync\TimeoutException;
use phasync\Util\WaitGroup;
use phasync\WriteChannelInterface;
use phasync\WriteSelectableInterface;

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
 * such service coroutine manages all the curl handles accross the application. Fibers
 * that need notification would invoke phasync::awaitFlag($curlHandle) and the manager
 * coroutine would invoke phasync::raiseFlag($curlHandle) when the $curlHandle is done.
 * 
 * @package phasync
 */
final class phasync {

    /**
     * Block the coroutine until the stream becomes readable.
     * {@see phasync::stream()}
     */
    const READABLE = DriverInterface::STREAM_READ;

    /**
     * Block the coroutine until the stream becomes writable.
     * {@see phasync::stream()}
     */
    const WRITABLE = DriverInterface::STREAM_WRITE;

    /**
     * Block the coroutine until the stream has an except state
     * (out-of-band date etc) {@see \stream_select()} for more
     * details.
     * 
     * {@see phasync::stream()}
     */
    const EXCEPT = DriverInterface::STREAM_EXCEPT;

    /**
     * The default timeout in seconds used throughout the library,
     * unless another timeout is configured via 
     * {@see phasync::setDefaultTimeout()}
     */
    const DEFAULT_TIMEOUT = 30.0;

    /**
     * This is the number of microseconds that a coroutine can run
     * before it is *volunteeringly* preempted by invoking the
     * {@see phasync::preempt()} function. When the coroutine has
     * run for this number of microseconds, the phasync::preempt()
     * function will suspend the coroutine and allow other 
     * coroutines to run.
     * 
     * Number is in nanoseconds, measured using \hrtime(true), the
     * default is 20 ms.
     */
    const DEFAULT_PREEMPT_INTERVAL = 50000000;

    /**
     * The currently configured timeout in seconds.
     * 
     * @var float
     */
    private static float $timeout = 30;

    /**
     * The currently set driver.
     * 
     * @var null|DriverInterface
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

    /**
     * Register a coroutine/Fiber to run in the event loop and await the result.
     * Running a coroutine this way also ensures that the event loop will run
     * until all nested coroutines have completed. If you want to create a coroutine
     * inside this context, and leave it running after - the coroutine must be
     * created from within another coroutine outside of the context, for example by
     * using a Channel.
     * 
     * @param Closure $coroutine 
     * @param array $arguments 
     * @return mixed 
     * @throws FiberError 
     * @throws Throwable 
     */
    public static function run(Closure $coroutine, array $arguments=[], ContextInterface $context=null): mixed {
        $driver = self::getDriver();
        try {
            if (!$driver->getCurrentFiber()) {
                // The event loop performs garbage collection at more optimal times.
                \gc_disable();
            }
    
            if ($context === null) {
                $context = new DefaultContext();
            }
    
            $fiber = $driver->create($coroutine, $arguments, $context);
    
            $result = self::await($fiber);
    
            while ($context->getFibers()->count() > 0) {
                if ($driver->getCurrentFiber()) {
                    self::yield();
                } else {
                    $driver->tick();
                }
            }
    
    
            if ($exception = $context->getContextException()) {
                throw $exception;
            }
            return $result;
        } finally {
            if (!$driver->getCurrentFiber()) {
                // Re-enable garbage collection
                \gc_enable();
            }
        }


    }

    /**
     * Creates a normal coroutine and starts running it. The coroutine will be associated
     * with the current context, and will block the current coroutine from completing
     * until it is done by returning or throwing.
     * 
     * @param Closure $coroutine 
     * @param array $args 
     * @return Fiber 
     * @throws FiberError 
     * @throws Throwable 
     */
    public static function go(Closure $coroutine, mixed ...$args): Fiber {
        $driver = self::getDriver();
        $fiber = $driver->getCurrentFiber();
        if (!$fiber) {
            throw new LogicException("Can't create a coroutine outside of a context. Use `phasync::run()` to launch a context.");
        }
        $result = $driver->create($coroutine, $args, null);
        self::preempt();
        return $result;
    }

    /**
     * Launches a service coroutine independently of the context scope.
     * This service will be permitted to continue but MUST stop running
     * when it is no longer providing services to other fibers. Failing
     * to do so will cause the topmost run() context to keep running.
     * 
     * @param Closure $coroutine 
     * @return void 
     */
    public static function service(Closure $coroutine): void {
        $driver = self::getDriver();
        $fiber = $driver->getCurrentFiber();
        if ($fiber === null || $driver->getContext($fiber) === null) {
            throw new LogicException("Services must be started on-demand inside a coroutine.");
        }
        $driver->runService($coroutine);
    }

    /**
     * Wait for a coroutine or promise to complete and return the result.
     * If exceptions are thrown in the coroutine, they will be thrown here.
     * 
     * @param object $fiberOrPromise
     * @param float $timeout The number of seconds to wait at most.
     * @return mixed 
     * @throws TimeoutException If the timeout is reached.
     * @throws Throwable 
     */
    public static function await(object $fiberOrPromise, ?float $timeout=null): mixed {
        $timeout = $timeout ?? self::getDefaultTimeout();
        $startTime = \microtime(true);

        if ($fiberOrPromise instanceof Fiber) {
            $fiber = $fiberOrPromise;
        } else {
            // Convert this promise into a Fiber
            $fiber = self::go(static function() use ($fiberOrPromise) {
                // May be a Promise
                $status = null;
                $result = null;
                if (!self::handlePromise($fiberOrPromise, static function(mixed $value) use (&$status, &$result) {
                    if ($status !== null) {
                        throw new LogicException("Promise resolved or rejected twice");
                    }
                    $status = true;
                    $result = $value;
                }, static function(mixed $error) use (&$status, &$result) {
                    if ($status !== null) {
                        throw new LogicException("Promise resolved or rejected twice");
                    }
                    $status = false;
                    $result = $error;
                })) {
                    throw new InvalidArgumentException("The awaited object must be a Fiber or a promise-like object");
                }
                // Allow the promise-like object to resolve
                while ($status === null) {
                    self::yield();
                }
                if ($status) {
                    return $result;
                } elseif ($result instanceof Throwable) {
                    throw $result;
                } else {
                    throw new Exception((string) $result);
                }
            });
        }
        
        $driver = self::getDriver();
        $currentFiber = $driver->getCurrentFiber();

        if ($currentFiber) {
            // We are in a Fiber
            while (!$fiber->isTerminated()) {
                $elapsed = \microtime(true) - $startTime;
                $remaining = $timeout - $elapsed;
                if ($remaining < 0) {
                    throw new TimeoutException("The coroutine did not complete in time");
                }
                $driver->whenFlagged($fiber, $remaining, $currentFiber);
                Fiber::suspend();    
            }    
        } else {
            // We are in the main context outside of the Fibers
            while (!$fiber->isTerminated()) {
                $elapsed = \microtime(true) - $startTime;
                $remaining = $timeout - $elapsed;
                if ($remaining < 0) {
                    throw new TimeoutException("The coroutine (" . Debug::getDebugInfo($fiber). ") did not complete in time");
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
     * @param (ReadSelectableInterface|WriteSelectableInterface)[] $selectables 
     * @param null|float $timeout 
     * @return ReadSelectableInterface 
     * @throws LogicException 
     * @throws FiberError 
     * @throws Throwable 
     */
    public static function select(array $selectables, ?float $timeout=null): ?ReadSelectableInterface {
        $stopTime = \microtime(true) + ($timeout ?? self::getDefaultTimeout());
        do {
            foreach ($selectables as $i => $selectable) {
                if ($selectable instanceof ReadSelectableInterface && !$selectable->readWillBlock()) {
                    return $selectable;
                }
                if ($selectable instanceof WriteSelectableInterface && !$selectable->writeWillBlock()) {
                    return $selectable;
                }
            }
            self::yield();
        } while ($stopTime >= \microtime(true) && !empty($selectables));
        return null;
    }

    /**
     * Cancel a suspended coroutine. This will throw an exception inside the
     * coroutine. If the coroutine handles the exception, it has the opportunity
     * to clean up any resources it is using. The coroutine MUST be suspended
     * using either {@see phasync::await()}, {@see phasync::sleep()}, {@see phasync::stream()}
     * or {@see phasync::awaitFlag()}.
     * 
     * @param Fiber $fiber 
     * @param Throwable $exception 
     * @return void 
     * @throws RuntimeException if the fiber is not currently blocked.
     */
    public static function cancel(Fiber $fiber, ?Throwable $exception=null): void {
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
     * 
     * @return void 
     * @throws RuntimeException 
     */
    public static function preempt(): void {
        $elapsed = ($now = \hrtime(true)) - self::$lastPreemptTime;
        if ($elapsed > self::$preemptInterval) {
            if (self::$lastPreemptTime === 0) {                
                // This check is too costly to perform on every preempt()
                // call, so we'll just set it here and wait for the next call.
                self::$lastPreemptTime = $now;
            } else {
                $driver = self::getDriver();
                self::$lastPreemptTime = $now;
                $driver->enqueue($driver->getCurrentFiber());
                Fiber::suspend();    
            }
        }
    }

    /**
     * Yield time so that other coroutines can continue processing. Note that
     * if you intend to wait for something to happen in other coroutines, you
     * should use {@see phasync::yield()}, which will suspend the coroutine until
     * after any other fibers have done some work.
     * 
     * @param float $seconds If null, the coroutine won't be resumed until another coroutine resumes
     * @return void 
     * @throws RuntimeException
     */
    public static function sleep(float $seconds=0): void {
        $driver = self::getDriver();
        $fiber = $driver->getCurrentFiber();
        if ($fiber !== null) {
            $driver->whenTimeElapsed($seconds, $fiber);
            Fiber::suspend();
        } elseif ($seconds <= 0) {
            $driver->tick();
        } else {
            \usleep(intval(1000000 * $seconds));
        }
    }

    /**
     * Suspend the fiber until immediately after some other fibers has performed
     * work. Suspending a fiber this way will not cause a busy loop. If you intend
     * to perform work actively, you should use {@see phasync::sleep(0)}
     * instead.
     * 
     * @return void 
     * @throws LogicException 
     * @throws FiberError 
     * @throws Throwable 
     */
    public static function yield(): void {
        $driver = self::getDriver();
        $fiber = $driver->getCurrentFiber();
        if ($fiber === null) {
            throw new LogicException("No other coroutines are running");
        }
        $driver->afterNext($fiber);
        Fiber::suspend();
    }


    /**
     * Suspend the current fiber until the event loop becomes empty or will sleeps while
     * waiting for future events.
     * 
     * @param null|float $timeout 
     * @return void 
     * @throws LogicException 
     */
    public static function idle(?float $timeout=null): void {
        $driver = self::getDriver();
        $fiber = $driver->getCurrentFiber();
        if ($fiber === null) {
            throw new LogicException("Not a fiber");
        }
        $timeout = $timeout ?? self::getDefaultTimeout();
            $driver->whenIdle($timeout, $fiber);
        try {
            Fiber::suspend();
        } catch (TimeoutException) {
            // Ignored
        }
    }

    /**
     * Utility function to suspend the current fiber until a stream resource becomes readable,
     * by wrapping `phasync::stream($resource, $timeout, phasync::READABLE)`.
     * 
     * @param resource $resource 
     * @param null|float $timeout 
     * @return void 
     * @throws FiberError 
     * @throws Throwable 
     */
    public static function readable(mixed $resource, ?float $timeout=null): void {
        self::stream($resource, self::READABLE, $timeout);
    }

    /**
     * Utility function to suspend the current fiber until a stream resource becomes readable,
     * by wrapping `phasync::stream($resource, $timeout, phasync::WRITABLE)`.
     * 
     * @param resource $resource 
     * @param null|float $timeout 
     * @return void 
     * @throws FiberError 
     * @throws Throwable 
     */
    public static function writable(mixed $resource, ?float $timeout=null): void {
        self::stream($resource, self::WRITABLE, $timeout);
    }

    /**
     * Block the coroutine until the stream resource becomes readable, writable or raises
     * an exception or any combination of these.
     * 
     * @param mixed $resource 
     * @param int $mode A bitmap indicating which events on the resource that should resume the coroutine.
     * @param float|null $timeout 
     * @return void 
     */
    public static function stream(mixed $resource, int $mode = self::READABLE|self::WRITABLE, ?float $timeout=null): void {
        $driver = self::getDriver();
        $fiber = self::getFiber();
        $timeout = $timeout ?? self::getDefaultTimeout();
        $driver->whenResourceActivity($resource, $mode, $timeout, $fiber);
        Fiber::suspend();
    }

    /**
     * Creates a channel pair which can be used to communicate between multiple
     * coroutines. Channels should be used to pass serializable data, to support
     * passing channels to worker processes, but it is possible to pass more
     * complex data if you are certain the data will not be passed to other
     * processes.
     * 
     * @param null|ReadChannelInterface $readChannel 
     * @param null|WriteChannelInterface $writeChannel 
     * @param int $bufferSize 
     * @return void 
     */
    public static function channel(?ReadChannelInterface &$readChannel, ?WriteChannelInterface &$writeChannel, int $bufferSize=0): void {
        if ($bufferSize === 0) {
            $channel = new ChannelUnbuffered();            
            $writeChannel = new WriteChannel($channel);
            $readChannel = new ReadChannel($channel);
        } else {
            $channel = new ChannelBuffered($bufferSize);
            $writeChannel = new WriteChannel($channel);
            $readChannel = new ReadChannel($channel);
        }
    }

    /**
     * Wait groups are used to coordinate multiple coroutines. A coroutine can add work
     * to the wait group when the coroutine begins processing the task, and then notify
     * the wait group that the work is done.
     * 
     * @return WaitGroup 
     */
    public static function waitGroup(): WaitGroup {
        return new WaitGroup();
    }

    /**
     * A publisher works like channels, but supports many subscribing coroutines
     * concurrently.
     * 
     * @return PublisherInterface
     */
    public static function publisher(?ReadChannelInterface &$readChannel, ?WriteChannelInterface &$writeChannel): void {

    }

    /**
     * Signal all coroutines that are waiting for an event represented
     * by the object $signal to resume.
     * 
     * @param object $signal 
     * @return int The number of resumed fibers. 
     */
    public static function raiseFlag(object $signal): int {
        return self::getDriver()->raiseFlag($signal);
    }

    /**
     * Pause execution of the current coroutine until an event is signalled
     * represented by the object $signal. If the timeout is reached, this function
     * throws TimeoutException.
     * 
     * @param object $signal 
     * @param float|null $timeout 
     * @return void 
     * @throws TimeoutException if the timeout is reached.
     * @throws Throwable 
     */
    public static function awaitFlag(object $signal, float $timeout=null): void {
        $driver = self::getDriver();
        $fiber = $driver->getCurrentFiber();
        if ($fiber === null) {
            throw new LogicException("Can only await flags from within a coroutine");
        }

        $driver->whenFlagged($signal, $timeout ?? self::getDefaultTimeout(), $fiber);
        Fiber::suspend();
    }

    /**
     * Enqueue a Fiber with the event loop while throwing an exception in it. This is
     * an internal function intended for advanced use cases and the API may change 
     * without notice.
     * 
     * @internal
     * @param Fiber $fiber 
     * @param null|Throwable $exception 
     * @return void 
     */
    public static function enqueueWithException(Fiber $fiber, Throwable $exception): void {
        self::getDriver()->enqueueWithException($fiber, $exception);
    }

    /**
     * Enqueue a Fiber with the event loop. This is an internal function intended
     * for advanced use cases and the API may change without notice.
     * 
     * @internal
     * @param Fiber $fiber 
     * @param mixed $value 
     * @return void 
     */
    public static function enqueue(Fiber $fiber): void {
        self::getDriver()->enqueue($fiber);
    }

    /**
     * Returns the driver instance for the application.
     * 
     * @return DriverInterface 
     */
    private static function getDriver(): DriverInterface {
        if (self::$driver === null) {
            self::$driver = new StreamSelectDriver();
        }
        return self::$driver;
    }

    /**
     * Set the interval between every time the {@see phasync::preempt()}
     * function will cause the coroutine to suspend running.
     * 
     * @param int $microseconds 
     * @return void 
     */
    public static function setPreemptInterval(int $microseconds): void {
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
     * @return void 
     */
    public static function setPromiseHandler(Closure $promiseHandlerFunction): void {
        self::$promiseHandlerFunction = $promiseHandlerFunction;
    }

    /**
     * Returns the current promise handler function. This enables extending
     * the functionality of the existing promise handler without losing the
     * other integrations. {@see phasync::setPromiseHandler()} for documentation
     * on the function signature.
     * 
     * @return Closure 
     */
    public static function getPromiseHandler(): Closure {
        if (self::$promiseHandlerFunction === null) {
            self::$promiseHandlerFunction = static function(mixed $promiseLike, Closure $onFulfilled=null, ?Closure $onRejected=null): bool {
                if (!\is_object($promiseLike) || !\method_exists($promiseLike, 'then')) {
                    return false;
                }
                $rm = new \ReflectionMethod($promiseLike, 'then');
                if ($rm->isStatic()) {
                    return false;
                }
                $onRejectedHandled = false;
                foreach ($rm->getParameters() as $index => $rp) {
                    if ($rp->hasType()) {
                        $rt = $rp->getType();
                        if ($rt instanceof \ReflectionNamedType) {
                            if (
                                $rt->getName() !== 'mixed' &&
                                $rt->getName() !== 'callable' &&
                                $rt->getName() !== \Closure::class
                            ) {
                                return false;
                            }
                        } else {
                            // mixed type apparently
                        }
                    }
                    if ($rp->isVariadic()) {                        
                        // Can handle many arguments of this type
                        $onRejectedHandled = true;
                        break;
                    }
                    if ($index === 1) {
                        $onRejectedHandled = true;
                        // Can handle at least two arguments of this type
                        break;
                    }
                }
                
                if ($onRejected !== null && !$onRejectedHandled) {
                    // The promise does not handle $onRejected in the `then`
                    // method, so see if we find a `catch` method.
                    if (\method_exists($promiseLike, 'catch')) {
                        if ($onFulfilled !== null) {
                            $promiseLike->then($onFulfilled);
                        }
                        $promiseLike->catch($onRejected);
                        return true;
                    }
                    return false;
                }

                if ($onFulfilled !== null && $onRejected !== null) {
                    $promiseLike->then($onFulfilled, $onRejected);
                } elseif ($onFulfilled !== null) {
                    $promiseLike->then($onFulfilled);
                } elseif ($onRejected !== null) {
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
     * @param DriverInterface $driver 
     * @return void 
     * @throws LogicException 
     */
    public static function setDriver(DriverInterface $driver): void {
        if (self::$driver !== null) {
            throw new LogicException("The driver must be set before any async functionality is used");
        }

        self::$driver = $driver;
    }

    /**
     * Set the default timeout for coroutine blocking operations. When
     * a coroutine blocking operation times out, a TimeoutException
     * is thrown.
     * 
     * @param float $timeout 
     * @return void 
     */
    public static function setDefaultTimeout(float $timeout): void {
        self::$timeout = $timeout;
    }

    /**
     * Get the configured default timeout, which is used by all coroutine
     * blocking functions unless a custom timeout is specified.
     * 
     * @return float 
     */
    public static function getDefaultTimeout(): float {
        return self::$timeout;
    }

    /**
     * There should be no unhandled exceptions. However, in certain scenarios
     * there exists a possibility for unhandled exceptions to occur. This method
     * ensures that the exception will be logged or thrown out of the event loop
     * from the `phasync::run()` function.
     *
     * @deprecated This function will be removed when it is certain that phasync handles all edge cases.
     * @internal
     * @param Throwable $exception 
     * @return void 
     */
    public static function logUnhandledException(Throwable $exception): void {
        \error_log($exception->__toString(), $exception->getCode());
    }

    /**
     * Integrate with Promise like objects.
     * 
     * @param mixed $promiseLike 
     * @param null|Closure $onFulfilled 
     * @param null|Closure $onRejected 
     * @return bool 
     */
    private static function handlePromise(mixed $promiseLike, ?Closure $onFulfilled=null, ?Closure $onRejected=null): bool {
        return (self::getPromiseHandler())($promiseLike, $onFulfilled, $onRejected);
    }

    public static function getFiber(): Fiber {
        $fiber = self::getDriver()->getCurrentFiber();
        if (!$fiber) {
            throw new LogicException("This function can not be used outside of a coroutine");
        }
        return $fiber;
    }

    public static function getContext(): ContextInterface {
        $context = self::getDriver()->getContext(self::getFiber());
        if (!$context) {
            throw new LogicException("This function can only be used inside a `phasync` coroutine");
        }
        return $context;
    }
}