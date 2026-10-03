<?php

use phasync\AggregateException;
use phasync\CancelledException;
use phasync\Internal\Debug;
use phasync\EventLoop;
use phasync\Internal\Channel;
use phasync\Internal\ExceptionTool;
use phasync\Internal\PromiseHandler;
use phasync\Internal\ReadChannel;
use phasync\Internal\Subscribers;
use phasync\Internal\WriteChannel;
use phasync\IOException;
use phasync\ReadChannelInterface;
use phasync\SelectableInterface;
use phasync\SubscribersInterface;
use phasync\TimeoutException;
use phasync\WriteChannelInterface;

/**
 * The static API of phasync: start coroutines, wait for them, cancel them, and wait for streams, flags and contexts.
 *
 * A coroutine is a closure that runs as a PHP Fiber in the event loop of the process. It runs until it waits (`sleep()`, `yield()`, `await()`, `readable()`, `writable()`, `awaitFlag()`, a channel operation), and then the loop runs another. Code that never waits holds up every other coroutine. A wait looks like a plain function call: it returns a value or throws, so a coroutine reads top to bottom. With the optional phasync-ext extension, blocking PHP functions wait in the same way; phasync behaves the same with and without it.
 *
 * `run()` starts the loop and returns when every coroutine it started has ended; `go()` starts a coroutine inside it. Every coroutine belongs to a context (any object), and `run()` does not return before the coroutines of its context have ended. A coroutine that throws, with nobody awaiting it, fails its `run()`.
 *
 * A wait takes a timeout in seconds and throws a TimeoutException when it passes; without one it waits for as long as it takes. `cancel()` throws a CancelledException into a coroutine, so its `finally` blocks run. To build a wait of your own, park the coroutine with `awaitFlag()` and wake it with `raiseFlag()`.
 *
 * ```php
 * phasync::run(function () {
 *     $a = phasync::go(function () { phasync::sleep(0.2); return 'a'; });
 *     $b = phasync::go(function () { phasync::sleep(0.1); return 'b'; });
 *
 *     echo phasync::await($a), phasync::await($b), "\n";   // ab, after 0.2 seconds, not 0.3
 * });
 * ```
 *
 * @see phasync::run
 * @see phasync::go
 * @see phasync::channel
 * @see phasync\Util\WaitGroup
 */
final class phasync
{
    /**
     * The context-local state: the array of the running coroutine's context, while {@see phasync::enableContextState()} or {@see phasync::adoptContextState()} has turned the swapping on.
     *
     * Every context has an array of its own, which this property is bound to by reference as a coroutine of the context runs: writes land in it, are not seen by other contexts, and are seen again after the coroutine has waited. The coroutines of a context share its array. Until the swapping is turned on this is an ordinary static array shared by all coroutines.
     *
     * ```php
     * phasync::enableContextState();
     * phasync::run(function () {
     *     phasync::go(function () { phasync::$contextState['user'] = 'ann'; phasync::sleep(0.01); echo phasync::$contextState['user']; }, [], new stdClass());
     *     phasync::go(function () { phasync::$contextState['user'] = 'bob'; phasync::sleep(0.01); echo phasync::$contextState['user']; }, [], new stdClass());
     * });   // annbob
     * ```
     *
     * @see phasync::$contextStateDefaults
     */
    public static array $contextState = [];

    /**
     * What a context's array starts as when it is first made live (a copy: arrays are copy-on-write).
     *
     * @see phasync::$contextState
     */
    public static array $contextStateDefaults = [];

    /**
     * The currently set driver.
     */
    private static ?EventLoop $driver = null;

    /**
     * Runs `$fn` as a coroutine and returns its result once it and every coroutine started inside it have ended.
     *
     * Outside a coroutine, `run()` starts the event loop and blocks the process until then. This is how a script, a test or a controller under PHP-FPM enters phasync. Inside a coroutine it opens a nested scope in the same loop: the calling coroutine waits and the others go on.
     *
     * The coroutines started inside belong to `$context`. If one of them throws and nobody awaits it, and no handler takes it (see {@see ExceptionHandlerInterface}), the run fails: its other coroutines are cancelled with a CancelledException, their `finally` blocks run, and `run()` throws the failure, or an AggregateException when several coroutines failed. A cancellation a coroutine ends with is no failure. What `$fn` itself throws is thrown from `run()` in the same way. A nested `run()` fails alone, and throws into the coroutine that called it.
     *
     * `$context` may be any object. A context can be used once: a second `run()`, `go()` or `withContext()` with the same object throws ContextUsedException. Without one, the run gets a new `stdClass`.
     *
     * ```php
     * $result = phasync::run(function () {
     *     phasync::go(function () {
     *         phasync::sleep(0.1);
     *         echo "second\n";
     *     });
     *     echo "first\n";
     *
     *     return 'done';
     * });
     * echo "third: $result\n";    // run() returned after the coroutine ended
     * ```
     *
     * @param Closure     $fn      the main coroutine
     * @param array       $args    passed to `$fn`
     * @param object|null $context the context of the run; used once
     *
     * @return mixed what `$fn` returned
     *
     * @throws \Throwable                what `$fn` threw, or the failure of a coroutine nobody awaited
     * @throws AggregateException        when several coroutines failed
     * @throws ContextUsedException      if `$context` was used before
     *
     * @see phasync::go           starts a coroutine without waiting for it
     * @see phasync::withContext  the same, without starting a coroutine
     * @see phasync::await
     * @see phasync::service
     */
    public static function run(Closure $fn, ?array $args = [], ?object $context = null): mixed
    {
        $driver = self::getDriver();
        $root   = !$driver->isRunning();
        // Any object: the coroutines of this run belong to it
        $context ??= new \stdClass();
        $driver->beginRun($context, $root);
        try {
            if ($root) {
                \gc_disable();
            }

            $exception = null;

            // The main coroutine failing fails the run: its other coroutines are cancelled
            $main = static function (mixed ...$args) use ($driver, $fn, $context) {
                try {
                    return $fn(...$args);
                } catch (Throwable $e) {
                    if (!$driver->isCancellation($e)) {
                        $driver->cancelRun($context, $e); // cancelled, it did not fail (CAN-9)
                    }

                    throw $e;
                }
            };

            $start = static function () use ($driver, $main, $args, $context, $root, &$fiber, &$exception) {
                try {
                    $fiber = $driver->create($main, $args, $context);
                } catch (Throwable $e) {
                    $fiber     = null;
                    $exception = $e;
                }

                if ($root) {
                    while ($driver->count() > 0) {
                        $driver->tick();
                    }
                } else {
                    while ([] !== $driver->getFibers($context)) {
                        self::yield();
                    }
                }
            };

            // With phasync-ext, a coroutine that runs a whole interval in a PHP loop yields to
            // other requests (EventLoop::preempt())
            $preempting = $root && \function_exists('phasync\ext\set_preempt_function');
            if ($preempting) {
                $previousPreempt = \phasync\ext\set_preempt_function($driver->preempt(...), EventLoop::PREEMPT_INTERVAL);
            }

            if ($root && \function_exists('phasync\ext\manage')) {
                // With phasync-ext, blocking I/O and sleeps inside coroutines park them in the
                // event loop instead of blocking the process. When PHP's own call has a timeout
                // and the park times out, the extension finishes the call the way PHP does.
                \phasync\ext\manage(
                    $start,
                    $driver->getPoller(),
                    static fn (int $microseconds) => self::sleep($microseconds / 1_000_000),
                    TimeoutException::class,
                );
            } else {
                $start();
            }

            if (null !== $exception) {
                throw $exception;
            }

            if ([] !== ($failures = $driver->endRun($context, $fiber))) {
                // A failure no handler took failed the run: its coroutines were dropped by the
                // loop, and are destroyed as their last references go (their finally blocks run)
                if ($fiber->isTerminated() && null !== ($e = $driver->getException($fiber)) && !$driver->isRunCancellation($context, $e)) {
                    \array_unshift($failures, $e);
                }
                $fiber = $start = null;
                try {
                    \gc_collect_cycles();
                } catch (Throwable $e) {
                    $failures[] = $e; // thrown while a coroutine unwound
                }

                throw 1 === \count($failures) ? $failures[0] : new AggregateException($failures);
            }

            return self::await($fiber);
        } catch (CancelledException $e) {
            if (null === $fiber || $fiber->isTerminated()) {
                throw $e; // a failure of the run (null: its coroutines were dropped), or the main coroutine's own
            }
            phasync::cancel($fiber);
            // Shielded: the run returns once its main coroutine has unwound, though the calling
            // coroutine was cancelled
            $driver->shield($caller = Fiber::getCurrent());
            try {
                return phasync::await($fiber);
            } finally {
                $driver->unshield($caller);
            }
        } finally {
            if (isset($preempting) && $preempting) {
                \phasync\ext\set_preempt_function($previousPreempt);
            }
            $driver->endRun($context, $fiber ?? null);
            if ($root) {
                \gc_enable();
            }
        }
    }

    /**
     * Starts `$fn` as a coroutine in the current context and returns it, without waiting for it.
     *
     * The coroutine runs at once, up to its first wait, and then `go()` returns it: the caller's next statement sees what the coroutine did before it waited. The run (or `withContext()` call) the caller is in does not end before the coroutine has. Pass the result to `await()` for the return value or the exception; an exception nobody awaits fails the run, see {@see phasync::run()}.
     *
     * With `$context` the coroutine gets a context of its own, nested in the caller's: `getContext()` returns it inside, and `cancel($context)` cancels it. A context can be used once.
     *
     * ```php
     * phasync::run(function () {
     *     $fiber = phasync::go(function () {
     *         echo "child\n";
     *         phasync::sleep(0.1);
     *         return 42;
     *     });
     *     echo "parent\n";                // after "child": it ran up to its first wait
     *     echo phasync::await($fiber), "\n";   // 42
     * });
     * ```
     *
     * @param Closure     $fn      the coroutine
     * @param array       $args    passed to `$fn`
     * @param object|null $context a context of its own
     *
     * @throws \LogicException       outside a coroutine
     * @throws ContextUsedException  if `$context` was used before
     *
     * @return Fiber the coroutine, for `await()`, `cancel()` and `throw()`
     *
     * @see phasync::run
     * @see phasync::await
     * @see phasync::service
     * @see phasync\Util\WaitGroup  to wait for many
     */
    public static function go(Closure $fn, array $args = [], ?object $context = null): Fiber
    {
        $driver = self::getDriver();
        $fiber = $driver->getCurrentFiber();
        if (!$fiber) {
            throw ExceptionTool::popTrace(new LogicException("Can't create a coroutine outside of a context. Use `phasync::run()` to launch a context."));
        }

        return $driver->create($fn, $args, $context);
    }

    /**
     * Starts `$coroutine` as a service: a coroutine that belongs to no context, so that it outlives the scope that started it.
     *
     * A service is for one coroutine that serves many others and is started on first use, such as a connection manager. The scope that started it can end while it runs, but the outermost `run()` does not return before it has ended: a service that never ends keeps `run()` waiting. A service that throws fails the outermost `run()`.
     *
     * ```php
     * phasync::run(function () {
     *     phasync::service(function () {
     *         phasync::sleep(0.1);
     *         echo "service ends\n";
     *     });
     *     echo "main ends\n";
     * });
     * // run() returns after "service ends"
     * ```
     *
     * @throws \LogicException outside a coroutine
     *
     * @see phasync::go
     * @see phasync::run
     */
    public static function service(Closure $coroutine): void
    {
        $driver = self::getDriver();
        $fiber = $driver->getCurrentFiber();
        if (null === $fiber || null === $driver->getContext($fiber)) {
            throw new LogicException('Services must be started on-demand inside a coroutine.');
        }
        $driver->runService($coroutine);
    }

    /**
     * Waits for a coroutine or a promise, and returns its result or throws the exception it ended with.
     *
     * A coroutine can be awaited any number of times, by any number of coroutines, and each call returns the same value or throws the same exception. Awaiting a failed coroutine counts as handling its failure: it no longer fails the run. A timeout throws in the caller only: the awaited coroutine keeps running.
     *
     * Given a {@see SelectableInterface} (a channel end, a WaitGroup, a StringBuffer, a RateLimiter), it waits until the object `isReady()` and returns the object. How a timeout ends depends on the object, see its `await()`. Given an object with a `then()` method, such as a promise from another library, it waits until the promise settles, and returns its value or throws its rejection; a rejection that is not a Throwable is thrown as an Exception with its string form as message.
     *
     * ```php
     * phasync::run(function () {
     *     $fiber = phasync::go(function () { phasync::sleep(0.1); return 'result'; });
     *
     *     try {
     *         phasync::await($fiber, 0.01);
     *     } catch (phasync\TimeoutException) {
     *         echo "not yet\n";
     *     }
     *     echo phasync::await($fiber), "\n";   // result
     * });
     * ```
     *
     * @param object $fiberOrPromise a coroutine from `go()` or `run()`, a SelectableInterface, or a promise-like object
     * @param float  $timeout        seconds to wait at most
     *
     * @return mixed the coroutine's return value; for a SelectableInterface, the object
     *
     * @throws TimeoutException        if a coroutine or promise does not end within `$timeout`
     * @throws \InvalidArgumentException for an object that is none of the above
     * @throws \LogicException         for a Fiber that phasync did not start
     * @throws \Throwable              what the coroutine threw
     *
     * @see phasync::go
     * @see phasync::cancel
     * @see phasync::awaitContext
     */
    public static function await(object $fiberOrPromise, float $timeout = PHP_FLOAT_MAX): mixed
    {
        $startTime = \microtime(true);
        $driver = self::getDriver();
        $currentFiber = $driver->getCurrentFiber();

        if ($fiberOrPromise instanceof SelectableInterface) {
            $fiberOrPromise->await($timeout);

            return $fiberOrPromise;
        }

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
                if (!PromiseHandler::handle($fiberOrPromise, static function (mixed $value) use (&$status, &$result) {
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
                $elapsed = \microtime(true) - $startTime;
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
                $elapsed = \microtime(true) - $startTime;
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
     * Registers `$fn` to run when the current coroutine ends, or when the `withContext()` call it is in returns.
     *
     * The callbacks run last registered first, and each runs even when another threw. They are for releasing what a coroutine acquired, where a `try {} finally {}` does not fit. Their waits are not cancelled, so cleanup that does I/O completes even when the coroutine was cancelled.
 *
 * Which callbacks run when depends on where `finally()` is called. Inside a `withContext()` call, they run as it returns, in the calling coroutine and still in the context, also when the closure threw, and before `withContext()` waits for the coroutines the closure started. Otherwise they run when the coroutine ends, in a coroutine of their own.
 *
 * ```php
     * phasync::run(function () {
     *     phasync::go(function () {
     *         phasync::finally(fn () => print("registered first\n"));
     *         phasync::finally(fn () => print("registered second\n"));   // prints first
     *         phasync::sleep(0.1);
     *     });
     * });
     * ```
     *
     * @throws \LogicException outside a coroutine
     *
     * @see phasync::withContext
     * @see phasync::cancel
     */
    public static function finally(Closure $fn): void
    {
        self::getDriver()->finally($fn);
    }

    /**
     * Cancels a coroutine, or every coroutine of a context, with a CancelledException.
     *
     * The cancellation is sticky: every wait of the coroutine throws the CancelledException from then on, so a coroutine that catches it and waits again is cancelled again. A coroutine that is not waiting meets it at its next wait; one that cancels itself gets it at once. A coroutine that ends with the exception it was cancelled with is no failure of the run, but awaiting it throws it. `finally()` callbacks are not cancelled.
     *
     * It is a teardown, not a signal: to interrupt a wait once with an exception of your own, use {@see phasync::throw()}. Given a context, it cancels every coroutine of it and of the contexts nested in it, the deepest first, and cancels the coroutines that join it until it has none left; the calling coroutine, if it is in the context, gets the exception thrown. A coroutine that is running, and not waiting, meets it at its next wait.
     *
     * ```php
     * phasync::run(function () {
     *     $worker = phasync::go(function () {
     *         try {
     *             while (true) {
     *                 phasync::sleep(1);
     *             }
     *         } finally {
     *             echo "cleaned up\n";
     *         }
     *     });
     *     phasync::sleep(0.1);
     *     phasync::cancel($worker);
     *
     *     try {
     *         phasync::await($worker);
     *     } catch (phasync\CancelledException) {
     *         echo "cancelled\n";
     *     }
     * });
     * ```
     *
     * @param object            $fiber    a coroutine, or a context
     * @param string|Stringable $message  the message of the CancelledException; a Throwable is refused, see {@see phasync::throw()}
     * @param int               $code     the code of the CancelledException
     * @param \Throwable|null   $previous what caused the cancellation, such as the failure that tears a context down
     *
     * @throws InvalidArgumentException if the coroutine has ended, or the message is a Throwable
 * @throws \LogicException          if the coroutine was not started by phasync
     *
     * @see phasync::throw      interrupts one wait
     * @see phasync::go
     * @see phasync::awaitContext
     */
    public static function cancel(object $fiber, string|Stringable $message = 'Operation cancelled', int $code = 0, ?Throwable $previous = null): void
    {
        if ($message instanceof Throwable) {
            throw new InvalidArgumentException('cancel() takes a message, not an exception: use phasync::throw() to throw an exception into a coroutine, or pass it as $previous');
        }
        $cancellation = new CancelledException((string) $message, $code, $previous);
        if (!$fiber instanceof Fiber) {
            self::getDriver()->cancelContext($fiber, $cancellation);

            return;
        }
        if ($fiber->isTerminated()) {
            throw new InvalidArgumentException('Fiber is already terminated');
        }
        self::getDriver()->cancel($fiber, $cancellation);
    }

    /**
     * Interrupts the wait of a suspended coroutine with `$exception`, once.
     *
     * The coroutine can catch it and carry on: nothing is remembered, and its next wait is an ordinary wait. A coroutine that ends with the exception is no failure of the run, as with {@see phasync::cancel()}. The coroutine must be waiting (in `await()`, `sleep()`, `readable()`, `awaitFlag()` and the like) and not be shielded, such as inside a `finally()` callback. Throwing into the calling coroutine throws at once.
     *
     * ```php
     * phasync::run(function () {
     *     $fiber = phasync::go(function () {
     *         try {
     *             phasync::sleep(10);
     *         } catch (RuntimeException $e) {
     *             echo "interrupted: ", $e->getMessage(), "\n";
     *         }
     *         phasync::sleep(0.01);   // an ordinary wait again
     *         return 'carried on';
     *     });
     *     phasync::throw($fiber, new RuntimeException('wake up'));
     *     echo phasync::await($fiber), "\n";
     * });
     * ```
     *
     * @throws InvalidArgumentException if the coroutine has ended
     * @throws \LogicException          if the coroutine is not waiting
     *
     * @see phasync::cancel  a sticky cancellation
     */
    public static function throw(Fiber $fiber, Throwable $exception): void
    {
        if ($fiber->isTerminated()) {
            throw new InvalidArgumentException('Fiber is already terminated');
        }
        self::getDriver()->throw($fiber, $exception);
    }

    /**
     * Pauses the current coroutine for `$seconds`, and lets the others run meanwhile.
     *
     * With the default of 0 the coroutine goes to the back of the line: every coroutine that is ready runs before it resumes. A timer wakes the coroutine after `$seconds`, never earlier. Outside a coroutine it blocks the process for that long, and returns at once for 0.
     *
     * Inside a coroutine, PHP's `sleep()` blocks the whole process, unless phasync-ext is loaded. `phasync\sleep()` is this function under the name of the native one.
     *
     * ```php
     * phasync::run(function () {
     *     phasync::go(function () { phasync::sleep(0.2); echo "slow\n"; });
     *     phasync::go(function () { phasync::sleep(0.1); echo "fast\n"; });
     * });   // fast, slow
     * ```
     *
     * @param float $seconds how long to pause; 0 lets the others run once
     *
     * @see phasync::yield
     * @see phasync\sleep
     */
    public static function sleep(float $seconds = 0): void
    {
        $driver = self::getDriver();
        $fiber = $driver->getCurrentFiber();
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
     * Suspends the current coroutine until the loop has made its next round, after the coroutines that were ready have run.
     *
     * Like `sleep(0)`, it lets the others run, but it resumes after the coroutines that called `sleep(0)` in the same round. It returns at once outside a coroutine.
     *
     * ```php
     * phasync::run(function () {
     *     phasync::go(function () { phasync::yield(); echo "yielded\n"; });
     *     phasync::go(function () { phasync::sleep(); echo "slept\n"; });
     * });   // slept, yielded
     * ```
     *
     * @see phasync::sleep
     */
    public static function yield(): void
    {
        $driver = self::getDriver();
        $fiber = $driver->getCurrentFiber();
        if (null === $fiber) {
            return;
        }
        $driver->afterNext($fiber);
        self::suspend();
    }

    /**
     * Suspends the coroutine until the stream can be read without blocking, and returns the stream.
     *
     * The stream is readable when data has arrived, the peer has finished, or the stream failed, so the read that follows does not wait. Set the stream to non-blocking with `stream_set_blocking()` first, or the read that follows blocks the process. Outside a coroutine it returns at once for a stream in blocking mode, and otherwise blocks until the stream is readable.
     *
     * One coroutine at a time may wait to read a stream, while another waits to write to it.
     *
     * ```php
     * phasync::run(function () {
     *     [$a, $b] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
     *     stream_set_blocking($a, false);
     *     phasync::go(function () use ($b) { phasync::sleep(0.1); fwrite($b, "hello"); });
     *
     *     echo fread(phasync::readable($a), 1024), "\n";   // hello, after 0.1 seconds
     * });
     * ```
     *
     * @param resource $resource a stream
     * @param float    $timeout  seconds to wait at most
     *
     * @return resource `$resource`, for use in the call that reads it
     *
     * @throws IOException      if `$resource` is not an open stream, or is closed while waiting
     * @throws \LogicException  if another coroutine is waiting to read `$resource`
     * @throws TimeoutException if the stream is not readable in time
     *
     * @see phasync::writable
     */
    public static function readable(mixed $resource, float $timeout = \PHP_FLOAT_MAX): mixed
    {
        self::waitForStream($resource, false, $timeout);

        return $resource;
    }

    /**
     * Suspends the coroutine until the stream can be written without blocking, and returns the stream.
     *
     * As {@see phasync::readable()} does for reading. One coroutine at a time may wait to write to a stream, while another waits to read from it.
     *
     * ```php
     * stream_set_blocking($socket, false);
     * $written = fwrite(phasync::writable($socket), $data);   // may write less than all of $data
     * ```
     *
     * @param resource $resource a stream
     * @param float    $timeout  seconds to wait at most
     *
     * @return resource `$resource`, for use in the call that writes it
     *
     * @throws IOException      if `$resource` is not an open stream, or is closed while waiting
     * @throws \LogicException  if another coroutine is waiting to write to `$resource`
     * @throws TimeoutException if the stream is not writable in time
     *
     * @see phasync::readable
     */
    public static function writable(mixed $resource, float $timeout = \PHP_FLOAT_MAX): mixed
    {
        self::waitForStream($resource, true, $timeout);

        return $resource;
    }

    private static function waitForStream(mixed $resource, bool $write, float $timeout): void
    {
        if (!\is_resource($resource) || 'stream' !== \get_resource_type($resource)) {
            throw ExceptionTool::popTrace(new IOException('Not a valid stream resource'));
        }

        $driver = self::getDriver();
        if ($driver->getCurrentFiber()) {
            $poller = $driver->getPoller();
            if ($write) {
                $poller->writable($resource, $timeout);
            } else {
                $poller->readable($resource, $timeout);
            }
            if (!\is_resource($resource)) {
                throw ExceptionTool::popTrace(new IOException('Stream closed'));
            }

            return;
        }

        if (\stream_get_meta_data($resource)['blocked'] ?? true) {
            return; // the read or write blocks instead (memory streams never wait)
        }
        $reads   = $write ? [] : [$resource];
        $writes  = $write ? [$resource] : [];
        $excepts = [];
        $seconds = $timeout < 2147483647 ? (int) $timeout : null; // null: until ready
        if (!\stream_select($reads, $writes, $excepts, $seconds, null === $seconds ? 0 : (int) (($timeout - $seconds) * 1000000))) {
            throw ExceptionTool::popTrace(new TimeoutException('Timeout'));
        }
    }

    /**
     * Creates a channel and returns its two ends in `$read` and `$write`.
     *
     * A channel passes values from coroutines that write to coroutines that read, in the order written, each value to exactly one reader. Any PHP value can be sent: nothing is serialized. With a `$bufferSize` of 0, `write()` returns when a reader has taken the value. With a buffer, `write()` returns at once while the buffer has room, and waits when it is full. `read()` waits while the channel is empty.
     *
     * When the writer closes the channel, readers read what is left, and then see the end: `foreach` over the reader stops, and `read()` sets its `$eof` argument. Writing to a closed channel throws a ChannelException. Dropping the last reference to an end closes the other: that is how a coroutine that serves a channel stops when its peer is gone.
     *
     * The coroutine that calls `channel()` is not expected to wait on it itself: if it does, and no other coroutine has waited on the channel within about 100 ms, the wait throws a ChannelException (a likely deadlock). Call `activate()` on an end to switch that off.
     *
     * ```php
     * phasync::run(function () {
     *     phasync::channel($reader, $writer, 10);   // a buffer of 10 values
     *
     *     phasync::go(function () use ($writer) {
     *         foreach (['a.txt', 'b.txt', 'c.txt'] as $file) {
     *             $writer->write($file);
     *         }
     *         $writer->close();
     *     });
     *
     *     foreach ($reader as $file) {              // ends when the writer closes
     *         echo "processing $file\n";
     *     }
     * });
     * ```
     *
     * @param ReadChannelInterface|null  $read       receives the reading end
     * @param WriteChannelInterface|null $write      receives the writing end
     * @param int                        $bufferSize values the channel holds before `write()` waits; 0: none
     *
     * @see phasync::publisher  to deliver every value to many readers
     * @see ReadChannelInterface
     * @see WriteChannelInterface
     */
    public static function channel(?ReadChannelInterface &$read, ?WriteChannelInterface &$write, int $bufferSize = 0): void
    {
        $channel = new Channel($bufferSize);
        $read = new ReadChannel($channel);
        $write = new WriteChannel($channel);
    }

    /**
     * Creates a publisher: what is written to `$publisher` is delivered to every subscription of `$subscribers`.
     *
     * Each subscription receives the messages written after it was made, in order, and a slow subscription does not hold back the others. A subscription made later does not see earlier messages. Writing with no subscriber succeeds. Closing the publisher ends the subscriptions once they have read what was written.
     *
     * A publisher runs a {@see phasync::service()} coroutine to forward messages, so the outermost `run()` does not return before the publisher is closed or its write end is dropped.
     *
     * ```php
     * phasync::run(function () {
     *     phasync::publisher($subscribers, $publisher);
     *
     *     foreach ([1, 2] as $i) {
     *         $subscription = $subscribers->subscribe();
     *         phasync::go(function () use ($subscription, $i) {
     *             foreach ($subscription as $event) {
     *                 echo "subscriber $i got $event\n";
     *             }
     *         });
     *     }
     *     $publisher->write('deployed');
     *     $publisher->close();
     * });
     * ```
     *
     * @param SubscribersInterface|null  $subscribers receives the object to subscribe with
     * @param WriteChannelInterface|null $publisher   receives the writing end
     *
     * @see phasync::channel  when each value goes to one reader
     * @see SubscribersInterface
     */
    public static function publisher(?SubscribersInterface &$subscribers, ?WriteChannelInterface &$publisher): void
    {
        self::channel($internalReadChannel, $publisher, 0);
        $subscribers = new Subscribers($internalReadChannel);
    }

    /**
     * Wakes every coroutine waiting for `$signal` in {@see phasync::awaitFlag()}, and returns how many it woke.
     *
     * A flag is any object: the one the waiters and the raiser share is the signal. Raising a flag nobody waits for does nothing, and is not remembered. This and `awaitFlag()` are what the other waits of phasync are built on.
     *
     * ```php
     * phasync::run(function () {
     *     $ready = new stdClass();
     *     phasync::go(function () use ($ready) {
     *         phasync::awaitFlag($ready);
     *         echo "ready\n";
     *     });
     *     echo phasync::raiseFlag($ready), " woken\n";   // 1 woken
     * });
     * ```
     *
     * @param object $signal the object the waiters wait for
     *
     * @return int the number of coroutines woken
     *
     * @see phasync::awaitFlag
     */
    public static function raiseFlag(object $signal): int
    {
        return self::getDriver()->raiseFlag($signal);
    }

    /**
     * Suspends the current coroutine until `$signal` is raised with {@see phasync::raiseFlag()}.
     *
     * The wait does not keep `$signal` alive: hold a reference to it elsewhere for as long as the coroutine waits. When the last other reference goes, the coroutine is woken with a CancelledException, because nothing can raise the flag any more. Waiting on an object nothing else references is undefined.
     *
     * ```php
     * $done = new stdClass();
     * phasync::go(function () use ($done) {
     *     phasync::sleep(0.1);
     *     phasync::raiseFlag($done);
     * });
     * phasync::awaitFlag($done, 1.0);   // returns after 0.1 seconds
     * ```
     *
     * @param object $signal  the object a raiser will pass to `raiseFlag()`
     * @param float  $timeout seconds to wait at most
     *
     * @throws TimeoutException  if the flag is not raised in time
     * @throws CancelledException if the flag was released while waiting
     * @throws \LogicException   outside a coroutine
     *
     * @see phasync::raiseFlag
     */
    public static function awaitFlag(object $signal, float $timeout = PHP_FLOAT_MAX): void
    {
        $driver = self::getDriver();
        $fiber = $driver->getCurrentFiber();
        if (null === $fiber) {
            throw ExceptionTool::popTrace(new LogicException('Can only await flags from within a coroutine'));
        }

        $driver->whenFlagged($signal, $timeout, $fiber);
        // A waiting coroutine must not keep its flag alive. If the owner of the flag lets go
        // of it, the Flag destructor wakes this coroutine with a CancelledException.
        unset($signal);
        self::suspend();
    }

    /**
     * Waits until every coroutine of `$context`, and of the contexts nested in it, has ended.
     *
     * A server that lets requests start coroutines of their own waits for them this way before it exits. Coroutines started in the meantime are waited for too. Nothing is thrown for their failures: those go where they always go, see {@see phasync::run()}. The calling coroutine does not wait for itself.
     *
     * ```php
     * phasync::run(function () {
     *     $context = new stdClass();
     *     phasync::go(function () { phasync::sleep(0.1); echo "ended\n"; }, [], $context);
     *
     *     phasync::awaitContext($context);
     *     echo "all ended\n";
     * });
     * ```
     *
     * @param object $context a context given to `run()`, `go()` or `withContext()`
     * @param float  $timeout seconds to wait at most
     *
     * @throws \LogicException  outside a coroutine
     * @throws TimeoutException if coroutines of the context still run after `$timeout` seconds
     *
     * @see phasync::cancel  given a context, cancels its coroutines
     * @see phasync::withContext
     */
    public static function awaitContext(object $context, float $timeout = PHP_FLOAT_MAX): void
    {
        self::getDriver()->awaitContext($context, $timeout);
    }

    /**
     * Returns true while a {@see phasync::run()} is in progress, and false otherwise.
     *
     * ```php
     * var_dump(phasync::isRunning());   // false
     * phasync::run(fn () => var_dump(phasync::isRunning()));   // true
     * ```
     *
     * @see phasync::getFiber
     */
    public static function isRunning(): bool
    {
        return self::getDriver()->isRunning();
    }

    /**
     * Runs `$fn` in the current coroutine as the main coroutine of a `run()` of `$context`, without starting a coroutine.
     *
     * It returns what `$fn` returns, once the coroutines `$fn` started in the context have ended too. When `$fn` throws, those are cancelled first and the exception is rethrown. A coroutine of the context that fails with nobody awaiting it fails the call as it fails a `run()`: the rest are cancelled, and the failure is thrown from `withContext()`. Starting a coroutine costs far more than this: a server gives each request a context of its own this way.
     *
     * Given a {@see Context\ContextFactoryInterface} instead of a context, `$fn` runs in the coroutine's own context until it needs a context of its own: `getContext()`, `getRootContext()`, `go()`, `finally()`, `run()` and `withContext()` called inside create it, once, with the factory. A request handler that does none of these costs no context at all.
     *
     * ```php
     * phasync::run(function () {
     *     $request = new stdClass();
     *     $result  = phasync::withContext(function () use ($request) {
     *         phasync::go(function () { phasync::sleep(0.1); echo "child ended\n"; });
     *         var_dump(phasync::getContext() === $request);   // true
     *         return 'response';
     *     }, $request);
     *     echo "$result\n";   // after "child ended"
     * });
     * ```
     *
     * @param Closure $fn      what to run
     * @param object  $context a context, or a ContextFactoryInterface; used once
     *
     * @return mixed what `$fn` returned
     *
     * @throws \LogicException       outside a coroutine
     * @throws ContextUsedException if `$context` was used before
     *
     * @see phasync::run
     * @see phasync::finally
     * @see phasync::awaitContext
     */
    public static function withContext(Closure $fn, object $context): mixed
    {
        return self::getDriver()->withContext($fn, $context);
    }

    /**
     * Turns the swapping of the context-local state on; calling it again does nothing.
     *
     * From then on, whenever the loop runs a coroutine of another context than the one that ran last, `phasync::$contextState` is bound to that context's array. Before it, a switch checks one flag. Inside a coroutine, its context gets its array at once.
     *
     * @see phasync::adoptContextState
     */
    public static function enableContextState(): void
    {
        self::getDriver()->enableContextState();
    }

    /**
     * Makes `$state`, by reference, the context-local state of the running coroutine's context, effective at once and for every later resume of it.
     *
     * The caller keeps `$state` and may adopt it into another context later. A `withContext()` given a factory creates its context here. It also turns the swapping on. Outside a coroutine it only binds `phasync::$contextState` to `$state`.
     *
     * ```php
     * $shared = ['count' => 0];
     * phasync::run(function () use (&$shared) {
     *     phasync::adoptContextState($shared);
     *     phasync::$contextState['count']++;
     *     echo $shared['count'];   // 1
     * });
     * ```
     *
     */
    public static function adoptContextState(array &$state): void
    {
        self::getDriver()->adoptContextState($state);
    }

    /**
     * Returns the event loop, for code that waits with its low-level API (`getSlot()`, `park()` and `unpark()` of the returned object).
     *
     * Application code does not need it: the other methods of this class are the API of the loop.
     *
     * @throws \LogicException outside `phasync::run()`
     */
    public static function getLoop(): EventLoop
    {
        if (!self::getDriver()->isRunning()) {
            throw ExceptionTool::popTrace(new LogicException('The event loop runs only inside phasync::run()'));
        }

        return self::getDriver();
    }

    /**
     * Returns the running coroutine.
     *
     * ```php
     * phasync::run(function () {
     *     $fiber = phasync::getFiber();
     *     var_dump($fiber->isRunning());   // true
     * });
     * ```
     *
     * @throws \LogicException outside a coroutine
     *
     * @see phasync::go
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
     * Returns the root context of the running coroutine.
     *
     * The context of a `run()` is its own root, and so is a context entered from it (`withContext()`, or `go()` with a context of its own); contexts entered from any other share that one's root. For a root context, `getRootContext() === getContext()`.
     *
     * ```php
     * phasync::run(function () {
     *     var_dump(phasync::getRootContext() === phasync::getContext());   // true
     * });
     * ```
     *
     * @throws \LogicException outside a coroutine
     *
     * @see phasync::getContext
     */
    public static function getRootContext(): object
    {
        return self::getDriver()->getRootContext(self::getContext());
    }

    /**
     * Returns the context of the running coroutine: the object given to `run()`, `go()` or `withContext()`, or the one it inherited.
     *
     * A run without a context object gets a `stdClass`.
     *
     * ```php
     * phasync::run(function () {
     *     $context = phasync::getContext();
     *     phasync::go(fn () => var_dump(phasync::getContext() === $context));   // true: inherited
     * }, [], new stdClass());
     * ```
     *
     * @throws \LogicException outside a coroutine
     *
     * @see phasync::getRootContext
     * @see phasync::withContext
     */
    public static function getContext(): object
    {
        $context = self::getDriver()->getContext(self::getFiber());
        if (!$context) {
            throw new LogicException('This function can only be used inside a `phasync` coroutine');
        }

        return $context;
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
        if (0 !== self::$driver->cancellations) {
            self::$driver->checkCancelled(Fiber::getCurrent());
        }
        try {
            Fiber::suspend();
        } catch (Throwable $e) {
            try {
                $className = \get_class($e);
                throw new $className($e->getMessage(), $e->getCode(), $e);
            } catch (Throwable) {
                throw $e;
            }
        }
    }

    /**
     * The event loop, made at first use.
     */
    private static function getDriver(): EventLoop
    {
        if (null === self::$driver) {
            self::$driver = new EventLoop();
        }

        return self::$driver;
    }
}
