<?php

use phasync\AggregateException;
use phasync\CancelledException;
use phasync\DeadlockException;
use phasync\EventLoop;
use phasync\Frame;
use phasync\Internal\Channel;
use phasync\Internal\Debug;
use phasync\Internal\ExceptionTool;
use phasync\Internal\PromiseHandler;
use phasync\Internal\ReadChannel;
use phasync\SuspensionPoller;
use phasync\Internal\Subscribers;
use phasync\Internal\WriteChannel;
use phasync\IOException;
use phasync\ReadChannelInterface;
use phasync\SelectableInterface;
use phasync\ShutdownException;
use phasync\SubscribersInterface;
use phasync\TimeoutException;
use phasync\WriteChannelInterface;

/**
 * The static API of phasync: start coroutines, wait for them, cancel them, and wait for streams and flags.
 *
 * A coroutine is a closure that runs as a PHP Fiber in the event loop of the process. It runs until it waits (`sleep()`, `yield()`, `await()`, `readable()`, `writable()`, `awaitFlag()`, a channel operation), and then the loop runs another. Code that never waits holds up every other coroutine. A wait looks like a plain function call: it returns a value or throws, so a coroutine reads top to bottom. With the optional ext-phasync extension, blocking PHP functions wait in the same way; phasync behaves the same with and without it.
 *
 * `run()` starts the loop and returns when every coroutine it started has ended; `go()` starts a coroutine inside it. Every coroutine belongs to the frame of a `run()` (see {@see Frame}), which waits for it, and to the current `Scope` (fiber-interop/scope), the identity of the operation it serves, which it inherits from the code that started it. A coroutine that throws, with nobody awaiting it, makes its `run()` throw once the others have ended.
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
 * @see phasync\Frame
 * @see phasync\Util\WaitGroup
 *
 * @topic coroutines
 *
 * @example examples/phasync/1-fetch-two-pages.php Two waits at once take as long as the slower one
 */
final class phasync
{
    /**
     * The currently set driver.
     */
    private static ?EventLoop $driver = null;

    /**
     * The process that made the driver: a fork()ed child makes its own.
     */
    private static ?int $driverPid = null;

    /**
     * Runs `$fn` and returns its result once it and every coroutine started inside it have ended.
     *
     * Outside a coroutine, `run()` starts the event loop, runs `$fn` as a coroutine and blocks the process until then. This is how a script, a test or a controller under PHP-FPM enters phasync. Inside a coroutine it runs `$fn` in that coroutine, in a new frame: the calling coroutine waits for what `$fn` started, and the others go on. A nested `run()` costs no coroutine, so a server can give each request one.
     *
     * Every `run()` creates a {@see Frame}: the coroutines started inside it belong to it, also those started by its coroutines, and it waits for them. A failure does not cancel the others. Once they have all ended, `run()` throws every failure: what `$fn` threw and each failure of a coroutine nobody awaited (a later await counts, until `run()` ends). One is thrown as it is; several in an {@see AggregateException}, `$fn`'s first, then by the time they happened. Without failures it returns what `$fn` returned. A coroutine that ends with a cancellation phasync issued is no failure. With an exception handler on the frame ({@see Frame::setExceptionHandler()}), the failures nobody awaited go to it instead. When coroutines wait and nothing can ever wake them, the outermost `run()` cancels its coroutines and throws a DeadlockException.
     *
     * When the outermost `run()` has no coroutine left, it cancels the services ({@see phasync::service()}) and returns once they have ended. `run()` does not change the current `Scope`: `$fn` runs in the caller's.
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
     * @param callable $fn      what to run
     * @param mixed    ...$args passed to `$fn`
     *
     * @throws Throwable           what `$fn` threw, or the failure of a coroutine nobody awaited, when there is one
     * @throws AggregateException  when there are several
     * @throws TimeoutException  when the frame's deadline passed ({@see Frame::setTimeout()})
     * @throws DeadlockException from the outermost `run()`, when coroutines wait and nothing can wake them
     * @throws LogicException    inside the event loop but outside a coroutine (an onScopeChange() hook)
     *
     * @return mixed what `$fn` returned
     *
     * @see phasync::go       starts a coroutine without waiting for it
     * @see phasync::frame
     * @see phasync::await
     * @see phasync::service
     * @see phasync\DeadlockException
     *
     * @topic coroutines
     *
     * @example examples/phasync.run/1-returns-after-every-coroutine.php run() returns after every coroutine it started
     * @example examples/phasync.run/2-a-failure-is-thrown-after-the-rest.php A failure nobody awaits is thrown once the rest have ended
     */
    public static function run(callable $fn, mixed ...$args): mixed
    {
        $fn = $fn instanceof Closure ? $fn : Closure::fromCallable($fn);
        if (null !== self::$driver && self::$driverPid !== \getmypid() && !self::$driver->isRunning()) {
            self::$driver = null; // a fork()ed child: the loop and its poller are the parent's
        }
        $driver = self::getDriver();
        if ($driver->isRunning()) {
            if (null === $driver->getCurrentFiber()) {
                throw ExceptionTool::popTrace(new LogicException('phasync::run() inside the event loop must be called from a coroutine'));
            }

            return $driver->runNested($fn, $args);
        }

        $frame = $driver->beginRun();
        try {
            \gc_disable();
            $fiber     = null;
            $exception = null;
            $start     = static function () use ($driver, $fn, $args, $frame, &$fiber, &$exception) {
                try {
                    $fiber = $driver->create($fn, $args, $frame, \Scope::getCurrent());
                } catch (Throwable $e) {
                    $exception = $e; // an onScopeChange() hook threw: no coroutine was started

                    return;
                }
                $driver->drive($frame);
            };

            // With ext-phasync, blocking calls in coroutines suspend them into the loop
            // (EventLoop::suspendedIn()), and a coroutine that runs a whole interval without
            // suspending is preempted
            $preempting = $driver->getPoller() instanceof SuspensionPoller;
            if ($preempting) {
                \phasync\ext\Suspension::setPreemptInterval($driver->preemptInterval);
            }
            $start();

            if (null !== $exception) {
                throw $exception;
            }

            return $driver->endRun($frame, $fiber);
        } finally {
            if (isset($preempting) && $preempting) {
                \phasync\ext\Suspension::setPreemptInterval(null);
            }
            $driver->closeRun($frame);
            $driver->restoreSignals(); // every signal phasync took gets its own handler back
            \gc_enable();
        }
    }

    /**
     * Starts `$fn` as a coroutine in the current frame and scope, and returns it, without waiting for it.
     *
     * The coroutine runs at once, up to its first wait, and then `go()` returns it: the caller's next statement sees what the coroutine did before it waited. It belongs to the frame of the `run()` the caller is in, which does not return before it has ended, and runs in the `Scope` current where `go()` is called (`Scope::getCurrent()` inside it returns that scope, also after it waited). Pass the result to `await()` for the return value or the exception; an exception nobody awaits is thrown by the `run()`, see {@see phasync::run()}.
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
     * @param callable $fn      the coroutine
     * @param mixed    ...$args passed to `$fn`
     *
     * @throws LogicException outside a coroutine
     *
     * @return Fiber the coroutine, for `await()`, `cancel()` and `throw()`
     *
     * @see phasync::run
     * @see phasync::await
     * @see phasync::service
     * @see phasync\Util\WaitGroup  to wait for many
     *
     * @topic coroutines
     *
     * @example examples/phasync.go/1-start-and-await.php The coroutine runs up to its first wait before go() returns
     * @example examples/phasync.go/2-the-scope-it-was-started-in.php The coroutine runs in the scope it was started in
     */
    public static function go(callable $fn, mixed ...$args): Fiber
    {
        $driver = self::getDriver();
        if (null === $driver->getCurrentFiber()) {
            throw ExceptionTool::popTrace(new LogicException("Can't create a coroutine outside of a coroutine. Use `phasync::run()` to start the event loop."));
        }

        return $driver->create($fn instanceof Closure ? $fn : Closure::fromCallable($fn), $args, $driver->getCurrentFrame(), \Scope::getCurrent());
    }

    /**
     * Starts `$fn` as a service: a coroutine of no frame, in the root scope, which outlives the code that started it, and returns it.
     *
     * A service is for one coroutine that serves many others and is started on first use, such as a connection manager. It belongs to no `run()`: no `run()` waits for it, and it runs while the outermost `run()` does. When the outermost `run()` has no coroutine left, it cancels its services and returns once they have ended, so write a service to be started again by the next use. It runs in the root scope (`Scope::getRootScope()`) whatever scope starts it, and {@see phasync::frame()} throws inside it. A failure nobody awaits goes to the outermost `run()`.
     *
     * ```php
     * phasync::run(function () {
     *     phasync::service(function () {
     *         phasync::sleep(10);   // cancelled when the run has nothing else to do
     *     });
     *     phasync::sleep(0.1);
     *     echo "main ends\n";
     * });
     * // run() returns after 0.1 seconds
     * ```
     *
     * @param callable $fn      the service
     * @param mixed    ...$args passed to `$fn`
     *
     * @throws LogicException outside a coroutine
     *
     * @return Fiber the service's coroutine
     *
     * @see phasync::go
     * @see phasync::run
     *
     * @topic the-event-loop
     *
     * @example examples/phasync.service/1-a-connection-started-on-first-use.php A connection that outlives the request that started it
     */
    public static function service(callable $fn, mixed ...$args): Fiber
    {
        $driver = self::getDriver();
        if (null === $driver->getCurrentFiber()) {
            throw ExceptionTool::popTrace(new LogicException('Services must be started on-demand inside a coroutine.'));
        }

        return $driver->runService($fn instanceof Closure ? $fn : Closure::fromCallable($fn), $args);
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
     * @throws TimeoutException         if a coroutine or promise does not end within `$timeout`
     * @throws InvalidArgumentException for an object that is none of the above
     * @throws LogicException           for a Fiber that phasync did not start
     * @throws Throwable                what the coroutine threw
     *
     * @return mixed the coroutine's return value; for a SelectableInterface, the object
     *
     * @see phasync::go
     * @see phasync::cancel
     * @see phasync::run
     *
     * @topic coroutines
     *
     * @example examples/phasync.await/1-timeout-then-result.php A timeout leaves the coroutine running
     * @example examples/phasync.await/2-the-exception-it-ended-with.php Awaiting a failed coroutine throws its exception
     * @example examples/phasync.await/3-a-promise.php Await an object with a then() method
     */
    public static function await(object $fiberOrPromise, float $timeout = \PHP_FLOAT_MAX): mixed
    {
        $startTime    = \microtime(true);
        $driver       = self::getDriver();
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
            if (null === $driver->getFrame($fiber)) {
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
     * Registers `$fn` to run when the current coroutine ends, or when the nested `run()` it is in returns.
     *
     * The callbacks run last registered first, and each runs even when another threw. They are for releasing what a coroutine acquired, where a `try {} finally {}` does not fit. Their waits are not cancelled, so cleanup that does I/O completes even when the coroutine was cancelled.
     *
     * Which callbacks run when depends on where `finally()` is called. Inside a `run()` nested in a coroutine, they run as `$fn` returns, in the calling coroutine and still in the run's frame, also when `$fn` threw, and before `run()` waits for the coroutines `$fn` started. Otherwise they run when the coroutine ends, in a coroutine of their own.
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
     * @throws LogicException outside a coroutine
     *
     * @see phasync::run
     * @see phasync::cancel
     *
     * @topic cancellation-and-cleanup
     *
     * @example examples/phasync.finally/1-release-when-the-coroutine-ends.php Cleanup that runs, and may wait, after a cancellation
     */
    public static function finally(Closure $fn): void
    {
        self::getDriver()->finally($fn);
    }

    /**
     * Cancels a coroutine with a CancelledException.
     *
     * The cancellation is sticky: every wait of the coroutine throws the CancelledException from then on, so a coroutine that catches it and waits again is cancelled again. It throws nothing in the caller: a coroutine that is not waiting meets it at its next wait, and one that cancels itself finishes its current step and meets it at its next wait too. A coroutine that ends with the exception it was cancelled with is no failure of the run, but awaiting it throws it. `finally()` callbacks are not cancelled.
     *
     * Cancelling a coroutine that has already ended is a no-op: there is nothing left to cancel, and a timeout firing just after the work ended is a common race, not a misuse.
     *
     * It is a teardown, not a signal: to interrupt a wait once with an exception of your own, use {@see phasync::throw()}. It cancels that coroutine only, not the coroutines it started; to cancel a whole tree, cancel its frame ({@see Frame::cancel()}).
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
     * @param Fiber                                $fiber    a coroutine
     * @param string|Stringable|CancelledException $message  the message of the CancelledException, or the CancelledException itself (such as a ShutdownException); another Throwable is refused, see {@see phasync::throw()}
     * @param int                                  $code     the code of the CancelledException
     * @param Throwable|null                       $previous what caused the cancellation
     *
     * @throws InvalidArgumentException if the message is a Throwable
     * @throws LogicException           if the coroutine was not started by phasync
     *
     * @see phasync::throw      interrupts one wait
     * @see phasync\Frame::cancel  cancels a frame
     * @see phasync::go
     *
     * @topic cancellation-and-cleanup
     *
     * @example examples/phasync.cancel/1-cancel-a-worker.php Cancel a worker; its finally block runs
     * @example examples/phasync.cancel/2-the-cancellation-is-sticky.php Every later wait is cancelled too
     */
    public static function cancel(Fiber $fiber, string|Stringable|CancelledException $message = 'Operation cancelled', int $code = 0, ?Throwable $previous = null): void
    {
        if ($message instanceof CancelledException) {
            $cancellation = $message; // a cancellation of the caller's own, such as a ShutdownException
        } elseif ($message instanceof Throwable) {
            throw new InvalidArgumentException('cancel() takes a message or a CancelledException, not another exception: use phasync::throw() to throw an exception into a coroutine, or pass it as $previous');
        } else {
            $cancellation = new CancelledException((string) $message, $code, $previous);
        }
        if ($fiber->isTerminated()) {
            return; // no-op: nothing is left to cancel, see CAN-3 in docs/SEMANTICS.md
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
     * @throws LogicException           if the coroutine is not waiting
     *
     * @see phasync::cancel  a sticky cancellation
     *
     * @topic cancellation-and-cleanup
     *
     * @example examples/phasync.throw/1-interrupt-one-wait.php Interrupt a wait, and carry on
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
     * Inside a coroutine, PHP's `sleep()` blocks the whole process, unless ext-phasync is loaded. `phasync\sleep()` is this function under the name of the native one.
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
     *
     * @topic waiting-and-timeouts
     *
     * @example examples/phasync.sleep/1-interleaved.php Two coroutines sleeping at different intervals
     */
    public static function sleep(float $seconds = 0): void
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
     *
     * @topic coroutines
     *
     * @example examples/phasync.yield/1-take-turns.php Coroutines take turns
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
     * Suspends the current coroutine until the event loop has had nothing to run for at least `$after` seconds: the ready queue and the callback queue are both empty, and an immediate poll finds no I/O ready.
     *
     * Waiters wake one per such idle moment, oldest first: each wakes exactly one coroutine, then the loop measures the next idle moment from scratch for whoever is waiting next. That is what makes it fit an admission gate: let one more in, then re-measure, rather than a fixed number that starves whichever requests are waiting on slow I/O.
     *
     * ```php
     * phasync::run(function () {
     *     phasync::go(function () {           // keeps the loop busy for a while
     *         for ($i = 0; $i < 1000; ++$i) {
     *             phasync::yield();
     *         }
     *         echo "busy done\n";
     *     });
     *     phasync::idle();                    // returns only once the loop above has nothing left
     *     echo "idle\n";                       // after "busy done"
     * });
     * ```
     *
     * @param float $after seconds the loop must have had nothing runnable, before this coroutine wakes
     *
     * @throws CancelledException if the coroutine is cancelled while waiting
     *
     * @see phasync::yield
     * @see phasync::sleep
     *
     * @topic waiting-and-timeouts
     *
     * @example examples/phasync.idle/1-after-the-busy-work.php Run housekeeping once the loop is idle
     */
    public static function idle(float $after = 0.0): void
    {
        $driver = self::getDriver();
        $fiber  = $driver->getCurrentFiber();
        if (null === $fiber) {
            return;
        }
        $driver->idle($after, $fiber);
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
     * @throws IOException      if `$resource` is not an open stream, or is closed while waiting
     * @throws LogicException   if another coroutine is waiting to read `$resource`
     * @throws TimeoutException if the stream is not readable in time
     *
     * @return resource `$resource`, for use in the call that reads it
     *
     * @see phasync::writable
     *
     * @topic waiting-and-timeouts
     *
     * @example examples/phasync.readable/1-read-a-socket.php Read lines from a socket as they arrive
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
     * @throws IOException      if `$resource` is not an open stream, or is closed while waiting
     * @throws LogicException   if another coroutine is waiting to write to `$resource`
     * @throws TimeoutException if the stream is not writable in time
     *
     * @return resource `$resource`, for use in the call that writes it
     *
     * @see phasync::readable
     *
     * @topic waiting-and-timeouts
     *
     * @example examples/phasync.writable/1-write-until-the-buffer-is-full.php Write more than the socket buffer holds
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
     *
     * @topic channels-and-messaging
     *
     * @example examples/phasync.channel/1-a-pipeline.php Two stages connected by channels
     * @example examples/phasync.channel/2-workers-share-a-queue.php Workers sharing the jobs of one channel
     */
    public static function channel(?ReadChannelInterface &$read, ?WriteChannelInterface &$write, int $bufferSize = 0): void
    {
        $channel = new Channel($bufferSize);
        $read    = new ReadChannel($channel);
        $write   = new WriteChannel($channel);
    }

    /**
     * Creates a publisher: what is written to `$publisher` is delivered to every subscription of `$subscribers`.
     *
     * Each subscription receives the messages written after it was made, in order, and a slow subscription does not hold back the others. A subscription made later does not see earlier messages. Writing with no subscriber succeeds. Closing the publisher ends the subscriptions once they have read what was written.
     *
     * A publisher forwards messages in a {@see phasync::service()} coroutine, which ends when the publisher is closed or its write end is dropped, and is cancelled when the outermost `run()` has nothing else left to do.
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
     *
     * @topic channels-and-messaging
     *
     * @example examples/phasync.publisher/1-every-subscriber-gets-every-message.php Every subscriber gets every message
     */
    public static function publisher(?SubscribersInterface &$subscribers, ?WriteChannelInterface &$publisher): void
    {
        self::channel($internalReadChannel, $publisher, 0);
        $subscribers = new Subscribers($internalReadChannel);
    }

    /**
     * Suspends the current coroutine until one of `$signals` (POSIX signal numbers such as
     * `SIGTERM`) arrives, and returns the one that did. Any number of coroutines may wait for the
     * same signal: each is woken. The waiter runs as an ordinary coroutine afterwards, so it may
     * await, write and close connections; the signal's handler only records it, and the event
     * loop wakes the waiters between coroutines, never inside one. A signal that came before the
     * wait began doesn't end it.
     *
     * phasync takes the signal over with its own handler until the outermost `phasync::run()`
     * returns, then puts the previous handler back; meanwhile its handler still calls a previous
     * `pcntl_signal()` handler. {@see phasync::onSignal()} adds code that runs inside it. It
     * works with and without `pcntl_async_signals()`.
     *
     * ```php
     * phasync::go(function () use ($server) {
     *     phasync::signal([SIGTERM, SIGINT]);
     *     $server->close();   // stop accepting, let the requests in flight finish
     * });
     * ```
     *
     * @param int|list<int> $signals the signal, or signals, to wait for
     * @param float         $timeout seconds to wait at most
     *
     * @throws TimeoutException if none arrives in time
     * @throws LogicException   outside a coroutine
     *
     * @return int the signal that arrived
     *
     * @topic cancellation-and-cleanup
     *
     * @example examples/phasync.signal/1-stop-on-sigterm.php Stop a server on SIGTERM
     */
    public static function signal(int|array $signals, float $timeout = \PHP_FLOAT_MAX): int
    {
        $driver = self::getDriver();
        $seen   = [];
        foreach ((array) $signals as $signo) {
            $driver->listenSignal($signo);
            $seen[$signo] = $driver->signalCount($signo);
        }
        // One signal: its own flag. Several: the flag raised after any signal, then check which.
        $flag     = 1 === \count($seen) ? $driver->getSignalFlagOf(\array_key_first($seen)) : $driver->getSignalFlag();
        $deadline = \microtime(true) + $timeout;
        while (true) {
            self::awaitFlag($flag, \max(0.0, $deadline - \microtime(true)));
            foreach ($seen as $signo => $count) {
                if ($driver->signalCount($signo) > $count) {
                    return $signo;
                }
            }
        }
    }

    /**
     * Run `$handler` the moment `$signo` arrives, inside the signal handler: with
     * `pcntl_async_signals(true)` in the middle of whatever is executing, even a coroutine that
     * never yields; otherwise when the event loop dispatches signals. For what must happen at that
     * moment, such as logging where a stuck process is; it must not suspend (no await, sleep or
     * phasync I/O). Coroutines in {@see phasync::signal()} are woken for the same signal as well.
     * phasync takes the signal over until the outermost `phasync::run()` returns (a previous
     * `pcntl_signal()` handler is called as well, and put back then), so register it for a run.
     *
     * ```php
     * phasync::onSignal(SIGQUIT, function () {
     *     error_log('stuck at ' . (new Exception())->getTraceAsString());
     * });
     * ```
     *
     * @param Closure(int):void $handler called with the signal number
     *
     * @topic cancellation-and-cleanup
     *
     * @example examples/phasync.onSignal/1-run-at-the-signal.php A handler that runs inside the signal handler
     */
    public static function onSignal(int $signo, Closure $handler): void
    {
        self::getDriver()->onSignal($signo, $handler);
    }

    /**
     * Stop the program's coroutines: every coroutine but the caller, whatever frame it is in,
     * gets `$exception` (a ShutdownException, which is a CancelledException) at its wait,
     * as `phasync::cancel()` does, and has up to `$window` seconds to clean up: `finally` blocks,
     * and `phasync::finally()` callbacks, which may wait. Returns how many are still running
     * after that, for the caller to decide (for example to exit, dropping them).
     *
     * ```php
     * phasync::go(function () {
     *     phasync::signal(SIGTERM);
     *     if (phasync::shutdown(2.0) > 0) {
     *         exit(1);   // some coroutines didn't stop in time
     *     }
     * });
     * ```
     *
     * @param float                  $window    seconds to wait at most for them to end
     * @param ShutdownException|null $exception what they get; a plain ShutdownException by default
     *
     * @throws LogicException outside a coroutine
     *
     * @return int the coroutines still running after the window
     *
     * @topic cancellation-and-cleanup
     *
     * @example examples/phasync.shutdown/1-stop-every-coroutine.php Stop every coroutine, with a window for cleanup
     */
    public static function shutdown(float $window = 1.0, ?ShutdownException $exception = null): int
    {
        if (null === self::getDriver()->getCurrentFiber()) {
            throw ExceptionTool::popTrace(new LogicException('Can only shut down from within a coroutine'));
        }
        $driver   = self::getDriver();
        $driver->shutdown($exception ?? new ShutdownException('Shutting down'));
        $deadline = \microtime(true) + $window;
        // Counted afresh: phasync::finally() cleanup runs in coroutines of its own
        while (($left = $driver->countFibers()) > 0 && \microtime(true) < $deadline) {
            self::sleep(0.01);
        }

        return $left;
    }

    /**
     * Run `$fn` out of every cancellation's reach: `phasync::cancel()`, `Frame::cancel()`, and
     * `phasync::shutdown()` don't cut its waits short. A cancellation that comes meanwhile applies
     * at the coroutine's first wait after `$fn` returns. For infrastructure that must finish what
     * it started, such as a server ending a protocol cleanly while the application's coroutines
     * stop; application code rarely needs it.
     *
     * A `finally` block's own wait is not shielded by default: the cancellation is sticky, so it
     * throws again there too. When that is what ends up terminating the coroutine, phasync warns
     * (`E_USER_WARNING`) that cleanup was cut short -- wrap the wait in `shielded()` instead.
     *
     * ```php
     * phasync::shielded(function () use ($socket) {
     *     fwrite($socket, $goodbye);   // still sent during a shutdown
     *     phasync::writable($socket, 1.0);
     * });
     * ```
     *
     * @template T
     *
     * @param Closure():T $fn
     *
     * @throws LogicException outside a coroutine
     *
     * @return T what `$fn` returns
     *
     * @topic cancellation-and-cleanup
     *
     * @example examples/phasync.shielded/1-finish-what-was-started.php Finish a goodbye after a cancellation
     */
    public static function shielded(Closure $fn): mixed
    {
        $driver = self::getDriver();
        $fiber  = $driver->getCurrentFiber();
        if (null === $fiber) {
            throw ExceptionTool::popTrace(new LogicException('Can only shield from within a coroutine'));
        }
        $driver->shield($fiber);
        try {
            return $fn();
        } finally {
            $driver->unshield($fiber);
        }
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
     * @return int the number of coroutines woken
     *
     * @see phasync::awaitFlag
     *
     * @topic waiting-and-timeouts
     *
     * @example examples/phasync.raiseFlag/1-wake-every-waiter.php Wake every coroutine waiting for a flag
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
     * @param float  $timeout seconds to wait at most, delivered at its real time; most timeouts
     *                        are checked in 10 ms slots (one comparison per tick, not a scan of
     *                        every waiter), but one under 10 ms would be dominated by that
     *                        rounding, so it is delivered through the same exact scheduler
     *                        `sleep()` uses instead (#139)
     * @param bool   $throw   when false, a timeout returns false instead of throwing
     *                        TimeoutException, and never constructs one: for a wait that is
     *                        expected to time out often, where building the exception would be
     *                        the expensive part
     *
     * @return bool true once the flag is raised; false only when `throw: false` and $timeout
     *              passed first
     *
     * @throws TimeoutException   if the flag is not raised in time and `throw` is true (the default)
     * @throws CancelledException if the flag was released, or the coroutine cancelled, while waiting
     * @throws LogicException     outside a coroutine
     *
     * @see phasync::raiseFlag
     *
     * @topic waiting-and-timeouts
     *
     * @example examples/phasync.awaitFlag/1-a-gate-of-your-own.php A gate built on a flag
     * @example examples/phasync.awaitFlag/2-timeout.php A flag that is not raised in time
     * @example examples/phasync.awaitFlag/3-no-throw.php A poll that usually times out, without the cost of an exception
     */
    public static function awaitFlag(object $signal, float $timeout = \PHP_FLOAT_MAX, bool $throw = true): bool
    {
        $driver = self::getDriver();
        $fiber  = $driver->currentFiber;
        if (null === $fiber) {
            throw ExceptionTool::popTrace(new LogicException('Can only await flags from within a coroutine'));
        }

        if (\PHP_FLOAT_MAX === $timeout && !($signal instanceof Fiber)) {
            // The common case: no timeout, not a Fiber flag. whenFlagged() inlined, skipping the
            // timeout and await-cycle checks this case never needs.
            $driver->whenFlaggedFast($signal, $fiber);
        } elseif (!$driver->whenFlagged($signal, $timeout, $fiber, $throw)) {
            // throw: false, and $timeout had already passed: resolved without registering a wait.
            return false;
        }
        // A waiting coroutine must not keep its flag alive. If the owner of the flag lets go
        // of it, the Flag destructor wakes this coroutine with a CancelledException.
        unset($signal);
        self::suspend();

        return !$driver->consumeTimedOutWithoutThrow($fiber);
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
     *
     * @topic the-event-loop
     */
    public static function isRunning(): bool
    {
        return self::getDriver()->isRunning();
    }

    /**
     * Registers `$fn` to be called when the current `Scope` changes as the event loop switches coroutines.
     *
     * It is called as `$fn(Scope $new, ?Scope $old)` before a coroutine runs, when the scope that is current in it differs from the one current where the loop last switched: when a coroutine starts or resumes in another scope than the coroutine that ran before. A nested `run()` entered in another scope (`$scope->run(fn () => phasync::run($fn))`, as a server enters a request) calls it too, before `$fn` runs. Switches between coroutines of one scope call nothing. It is the place to swap state that PHP keeps globally in and out per operation (a locale, a static of a library that is not scope-aware). Otherwise `$scope->run()` entered or left inside a coroutine calls nothing: code that does so makes the change itself.
     *
     * The hooks run in the event loop, outside any coroutine, in the order registered: they must not wait (a wait there throws LogicException), and an exception from one ends `phasync::run()`. They stay registered for the life of the event loop.
     *
     * A generator runs in the scope of the code that iterates it (fiber-interop/scope's polyfill gap), and resuming one calls nothing. Reporting a generator's own scope is planned for the phasync extension.
     *
     * ```php
     * $locales = new WeakMap();   // per Scope
     * phasync::onScopeChange(function (Scope $new) use ($locales) {
     *     Locale::setDefault($locales[$new] ?? 'en');
     * });
     * ```
     *
     * @param callable(Scope, ?Scope):void $fn
     *
     * @see phasync::go
     *
     * @topic scopes-and-frames
     *
     * @example examples/phasync.onScopeChange/1-a-locale-per-request.php A locale per request
     */
    public static function onScopeChange(callable $fn): void
    {
        self::getDriver()->onScopeChange($fn instanceof Closure ? $fn : Closure::fromCallable($fn));
    }

    /**
     * Returns the frame of the running coroutine: that of the innermost `run()` it is in.
     *
     * A coroutine belongs to the frame of the `run()` that started it, and a nested `run()` gives the coroutine that calls it a new frame until it returns.
     *
     * ```php
     * phasync::run(function () {
     *     phasync::frame()->setTimeout(1.0);   // this run(), and every coroutine started inside it
     * });
     * ```
     *
     * @throws LogicException outside a coroutine, and in a service, which has no frame
     *
     * @see phasync\Frame
     * @see phasync::run
     *
     * @topic scopes-and-frames
     */
    public static function frame(): Frame
    {
        $driver = self::getDriver();
        $frame  = $driver->getCurrentFrame();
        if (null === $frame) {
            throw ExceptionTool::popTrace(new LogicException('phasync::frame() can only be used inside a coroutine'));
        }
        if ($driver->isServiceFrame($frame)) {
            throw ExceptionTool::popTrace(new LogicException('A service has no frame: it belongs to no run()'));
        }

        return $frame;
    }

    /**
     * Returns the event loop, for code that waits with its low-level API (`getSlot()`, `park()` and `unpark()` of the returned object), or drives it in place (`tick()`).
     *
     * Application code does not need it: the other methods of this class are the API of the loop. `tick()` runs the coroutines that are ready once, from a Fiber phasync did not start (an adapter's in-place wait), and puts the running coroutine and its frame back when it returns.
     *
     * @throws LogicException outside `phasync::run()`
     *
     * @topic the-event-loop
     *
     * @example examples/phasync.getLoop/1-park-and-unpark.php A mailbox on park() and unpark()
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
     * @throws LogicException outside a coroutine
     *
     * @see phasync::go
     *
     * @topic the-event-loop
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
            self::$driver->checkCancelled(\phasync\ext\current_fiber());
        }
        if (null !== self::$driver->hookFiber && self::$driver->hookFiber === self::$driver->currentFiber) {
            self::$driver->refuseHookWait();
        }
        try {
            if (Fiber::getCurrent() === self::$driver->currentFiber) {
                self::$driver->suspending(self::$driver->currentFiber);
                Fiber::suspend();
            } else {
                self::$driver->wait(); // in a Fiber the coroutine runs itself
            }
        } catch (Throwable $e) {
            if ($e instanceof phasync\RethrowExceptionInterface) {
                $e->rebuildStackTrace();
            }

            throw $e;
        }
    }

    /**
     * The event loop, made at first use.
     */
    private static function getDriver(): EventLoop
    {
        if (null === self::$driver) {
            self::$driver    = new EventLoop();
            self::$driverPid = \getmypid();
        }

        return self::$driver;
    }
}
