<?php

use phasync\Context\ContextInterface;
use phasync\Context\DefaultContext;
use phasync\Drivers\DriverInterface;
use phasync\Drivers\StreamSelectDriver;
use phasync\Internal\ChannelState;
use phasync\Internal\ReadChannel;
use phasync\Internal\WriteChannel;
use phasync\ReadChannelInterface;
use phasync\SelectableInterface;
use phasync\TimeoutException;
use phasync\UsageError;
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
    const READABLE = 1;

    /**
     * Block the coroutine until the stream becomes writable.
     * {@see phasync::stream()}
     */
    const WRITABLE = 2;

    /**
     * Block the coroutine until the stream has an except state
     * (out-of-band date etc) {@see \stream_select()} for more
     * details.
     * 
     * {@see phasync::stream()}
     */
    const EXCEPT = 4;

    private static float $timeout = 30;

    private static ?DriverInterface $driver = null;

    /**
     * A function that sets an onFulfilled and/or an onRejected callback on
     * a promise.
     * 
     * @var null|Closure{object, ?Closure{mixed}, ?Closure{mixed}, false}
     */
    private static ?Closure $promiseHandlerFunction = null;

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

        if ($context === null) {
            $context = new DefaultContext();
        }

        $fiber = $driver->create($coroutine, $arguments, $context);

        $result = self::await($fiber);

        while ($context->getFibers()->count() > 0) {
            if (Fiber::getCurrent()) {
                self::yield();
            } else {
                $driver->tick();
            }
        }

        if ($exception = $context->getContextException()) {
            throw $exception;
        }
        return $result;
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
        if (mt_rand(0,100)===0) {
            self::sleep(0);
        }
        self::getFiber();
        return self::getDriver()->create($coroutine, $args, null);
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
        $fiber = Fiber::getCurrent();
        $driver = self::getDriver();
        if ($fiber === null || $driver->getContext($fiber) === null) {
            throw new LogicException("Services must be started on-demand inside a coroutine.");
        }
        self::getDriver()->runService($coroutine);
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
        $currentFiber = Fiber::getCurrent();
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
                    throw new TimeoutException("The coroutine did not complete in time");
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
     * @param SelectableInterface[] $selectables 
     * @param null|float $timeout 
     * @return SelectableInterface 
     * @throws LogicException 
     * @throws FiberError 
     * @throws Throwable 
     */
    public static function select(array $selectables, ?float $timeout=null): ?SelectableInterface {
        $stopTime = \microtime(true) + ($timeout ?? self::getDefaultTimeout());
        do {
            foreach ($selectables as $selectable) {
                if (!$selectable->willBlock()) {
                    return $selectable;
                }
                self::yield();
            }
        } while ($stopTime >= \microtime(true));
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
        $fiber = Fiber::getCurrent();
        if ($fiber !== null) {
            self::getDriver()->whenTimeElapsed($seconds, $fiber);
            Fiber::suspend();
        } elseif ($seconds <= 0) {
            self::getDriver()->tick();
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
        $fiber = Fiber::getCurrent();
        if ($fiber === null) {
            throw new LogicException("No other coroutines are running");
        }
        self::getDriver()->afterNext($fiber);
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
        $fiber = Fiber::getCurrent();
        if ($fiber === null) {
            throw new LogicException("Not a fiber");
        }
        $timeout = $timeout ?? self::getDefaultTimeout();
        self::getDriver()->whenIdle($timeout, $fiber);
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
        $timeout = $timeout ?? self::getDefaultTimeout();
        $fiber = Fiber::getCurrent();
        if ($fiber !== null) {
            self::getDriver()->whenResourceActivity($resource, $mode, $timeout, $fiber);
            Fiber::suspend();
        } else {
            // Leverage a coroutine to wait for the stream resource
            self::run(function() use ($resource, $mode, $timeout) {
                self::stream($resource, $mode, $timeout);
            });
        }
    }

    public static function channel(?ReadChannelInterface &$readChannel, ?WriteChannelInterface &$writeChannel, int $bufferSize=0): void {
        $channelState = ChannelState::create(\max(0, $bufferSize), self::getFiber());
        $readChannel = new ReadChannel($channelState);
        $writeChannel = new WriteChannel($channelState);
    }

    public static function waitGroup(): WaitGroup {
        return new WaitGroup();
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
        $fiber = Fiber::getCurrent();
        if ($fiber === null) {
            throw new RuntimeException("Can only await flags from within a coroutine");
        }
        self::getDriver()->whenFlagged($signal, $timeout ?? self::getDefaultTimeout(), $fiber);
        Fiber::suspend();
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

    private static function getFiber(): Fiber {
        $fiber = Fiber::getCurrent();
        if (!$fiber) {
            throw new UsageError("This function can not be used outside of a coroutine");
        }
        return $fiber;
    }

    public static function getContext(): ContextInterface {
        $context = self::getDriver()->getContext(self::getFiber());
        if (!$context) {
            throw new UsageError("This function can only be used inside a `phasync` coroutine");
        }
        return $context;
    }
}