<?php

use phasync\AggregateException;
use phasync\CancelledException;
use phasync\Debug;
use phasync\EventLoop;
use phasync\Internal\AsyncStream;
use phasync\Internal\Channel;
use phasync\Internal\ExceptionTool;
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
 * This class defines the essential API for all coroutine based applications.
 * This basic API enables implementing all forms of asynchronous programming,
 * including asynchronous CURL and database connections efficiently via the
 * use of flag signals {@see phasync::raiseFlag()} and {@see phasync::awaitFlag()}.
 *
 * The essential functions are:
 *
 * - {@see phasync::sleep()} to pause the coroutine and avoid wasting CPU cycles
 *   if there is nothing to do.
 * - {@see phasync::readable()} and {@see phasync::writable()} to pause the coroutine
 *   until a stream resource becomes readable or writable.
 * - {@see phasync::raiseFlag()} and {@see phasync::awaitFlag()} to pause the coroutine
 *   until an trigger occurs.
 *
 * It is bad practice for any advanced functionality to check for external events
 * on every tick, so it should use sleep(), readable(), writable() or raiseFlag()/awaitFlag() to
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
     * The recursion depth of run statements that are active.
     */
    private static int $runDepth = 0;

    /**
     * The currently set driver.
     */
    private static ?EventLoop $driver = null;

    private static ?int $pid = null;

    /**
     * A function that sets an onFulfilled and/or an onRejected callback on
     * a promise.
     *
     * @var null|Closure{object, ?Closure{mixed}, ?Closure{mixed}, false}
     */
    private static ?Closure $promiseHandlerFunction = null;



    private static array $onEnterCallbacks = [];
    private static array $onExitCallbacks = [];

    /**
     * Run the function in a separate process. The calling coroutine will be blocked but
     * other coroutines can run while the function is being evaluated.
     *
     * @param Closure $function
     * @param array $args
     * @return mixed
     * @throws LogicException
     * @throws RuntimeException
     * @throws FiberError
     * @throws Throwable
     */
    public static function fork(Closure $function, mixed ...$args): mixed
    {
        if (!function_exists('pcntl_fork')) {
            throw new LogicException('This function requires the pcntl extension');
        }
        self::getFiber();

        // Create a socket pair
        $socketPair = stream_socket_pair(AF_UNIX, SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($socketPair === false) {
            throw new RuntimeException('Unable to create socket pair');
        }

        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Unable to fork');
        }

        if ($pid === 0) {
            // Remove the event loop driver (if any)
            if (self::$driver !== null) {
                self::$driver->clear();
                self::$driver = null;
            }
            // Child process
            fclose($socketPair[0]); // Close the parent's socket

            try {
                $result = $function(...$args);
                $serializedResult = serialize(['result' => $result]);
            } catch (Throwable $e) {
                $serializedResult = serialize(['exception' => $e]);
            }

            $bytesToWrite = \strlen($serializedResult);
            $offset = 0;
            while ($offset < $bytesToWrite) {
                $written = fwrite(self::writable($socketPair[1]), \substr($serializedResult, $offset), $bytesToWrite);
                if (\is_int($written)) {
                    $offset += $written;
                } else {
                    break;
                }
            }
            fclose($socketPair[1]);
            // Terminate the child process
            posix_kill(posix_getpid(), SIGTERM);
        } else {
            // Parent process
            fclose($socketPair[1]); // Close the child's socket

            $task = function () use ($socketPair) {
                $buffer = '';
                while ($data = fread(phasync::readable($socketPair[0]), 65536)) {
                    $buffer .= $data;
                }

                fclose($socketPair[0]);

                $result = unserialize($buffer);
                if (!is_array($result)) {
                    throw new RuntimeException("Invalid result from child process: '$buffer'");
                }
                if (isset($result['exception'])) {
                    throw $result['exception'];
                } else {
                    return $result['result'];
                }
            };

            return self::run($task);
        }
    }

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
    public static function run(Closure $fn, ?array $args = [], ?object $context = null): mixed
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

            // Any object: the coroutines of this run belong to it
            $context ??= new \stdClass();
            $driver->beginRun($context, 0 === $runDepth);

            $exception = null;

            $start = static function () use ($driver, $fn, $args, $context, $runDepth, &$fiber, &$exception) {
                try {
                    $fiber = $driver->create($fn, $args, $context);
                } catch (Throwable $e) {
                    $fiber     = null;
                    $exception = $e;
                }

                if (0 === $runDepth) {
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
            $preempting = 0 === $runDepth && \function_exists('phasync\ext\set_preempt_function');
            if ($preempting) {
                $previousPreempt = \phasync\ext\set_preempt_function($driver->preempt(...), EventLoop::PREEMPT_INTERVAL);
            }

            if (0 === $runDepth && \function_exists('phasync\ext\manage')) {
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

            if ([] !== ($failures = $driver->endRun($context))) {
                // A failure no handler took failed the run: its coroutines were dropped by the
                // loop, and are destroyed as their last references go (their finally blocks run)
                if ($fiber->isTerminated() && null !== ($e = $driver->getException($fiber))) {
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

            return phasync::await($fiber);
        } finally {
            if (isset($preempting) && $preempting) {
                \phasync\ext\set_preempt_function($previousPreempt);
            }
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
     * @param object|null           $context    A context of its own: any object, used once
     * @param bool                  $run        If true, the coroutine will be run in an event loop context
     *
     * @throws LogicException
     */
    public static function go(Closure $fn, array $args = [], int $concurrent = 1, ?object $context = null, bool $run = false): Fiber
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
        $fiber = $driver->getCurrentFiber();
        if (!$fiber) {
            if ($run) {
                $result = phasync::run($fn, $args, $context);

                return new Fiber(static function () use ($result) {
                    return $result;
                });
            }
            throw ExceptionTool::popTrace(new LogicException("Can't create a coroutine outside of a context. Use `phasync::run()` to launch a context."));
        }
        return $driver->create($fn, $args, $context);
    }

    /**
     * Schedule a callback to be invoked immediately after the current (or next) tick.
     */
    public static function defer(Closure $callback): void
    {
        self::getDriver()->defer($callback);
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
        $fiber = $driver->getCurrentFiber();
        if (null === $fiber || null === $driver->getContext($fiber)) {
            throw new LogicException('Services must be started on-demand inside a coroutine.');
        }
        $driver->runService($coroutine);
    }

    /**
     * Wait for a coroutine, a promise, or a SelectableInterface to complete and return the result.
     * If exceptions are thrown in the coroutine, they will be thrown here.
     *
     * @param float $timeout the number of seconds to wait at most
     *
     * @throws TimeoutException if the timeout is reached
     * @throws Throwable
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
     * Schedule a closure to run when the current coroutine completes, or, when called inside
     * {@see phasync::withContext()}, as that call returns, whichever comes first. Callbacks run
     * last registered first. This function is intended to be used when a coroutine uses a
     * resource that must be cleaned up when the coroutine finishes. Note that it may be more
     * efficient to use a try {} finally {} statement.
     *
     * Inside withContext() the callbacks run in the calling coroutine, still in the context, also
     * when the closure threw, and may suspend; no coroutine is started for them. A server that
     * runs each request in withContext() and sends the response inside it thereby runs them
     * after the response, as fastcgi_finish_request() allows under PHP-FPM.
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
        if (self::getDriver()->finallyWithContext($fiber, $fn)) {
            return; // runs as the withContext() call it was registered in returns
        }

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
     * using either {@see phasync::await()}, {@see phasync::sleep()}, {@see phasync::readable()}
     * or {@see phasync::awaitFlag()}.
     *
     * Given a context, cancels every waiting coroutine of it and of the contexts nested in it,
     * the deepest first, except the calling coroutine.
     *
     * @throws RuntimeException if the fiber is not currently blocked
     */
    public static function cancel(object $fiber, ?Throwable $exception = null): void
    {
        if (!$fiber instanceof Fiber) {
            self::getDriver()->cancelContext($fiber, $exception);

            return;
        }
        if ($fiber->isTerminated()) {
            throw new InvalidArgumentException('Fiber is already terminated');
        }
        self::getDriver()->cancel($fiber, $exception);
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
     * Suspend the fiber until immediately after some other fibers has performed
     * work. Suspending a fiber this way will not cause a busy loop. If you intend
     * to perform work actively, you should use {@see phasync::sleep(0)}
     * instead.
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
     * Suspend the current fiber until the event loop becomes empty or will sleeps while
     * waiting for future events. The timeout does not raise an exception and instead
     * resumes the coroutine normally.
     */
    public static function idle(float $timeout = \PHP_FLOAT_MAX): void
    {
        $driver = self::getDriver();
        $fiber = $driver->getCurrentFiber();
        if (null === $fiber) {
            return;
        }
        $driver->whenIdle($timeout, $fiber);
        self::suspend();
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
     * Suspend the coroutine until the stream can be read without blocking: data arrived, the
     * peer finished, or the stream failed. Outside a coroutine it blocks the process until
     * then, and returns at once for a blocking stream (the read will wait).
     *
     * One coroutine at a time may wait to read a stream, and one to write to it.
     *
     * @param resource $resource
     *
     * @return resource Returns the same resource for convenience
     *
     * @throws IOException      if $resource is not an open stream, or is closed meanwhile
     * @throws LogicException   if another coroutine is waiting to read $resource
     * @throws TimeoutException
     */
    public static function readable(mixed $resource, float $timeout = \PHP_FLOAT_MAX): mixed
    {
        self::waitForStream($resource, false, $timeout);

        return $resource;
    }

    /**
     * Suspend the coroutine until the stream can be written without blocking, as
     * {@see phasync::readable()} does for reading.
     *
     * @param resource $resource
     *
     * @return resource Returns the same resource for convenience
     *
     * @throws IOException      if $resource is not an open stream, or is closed meanwhile
     * @throws LogicException   if another coroutine is waiting to write to $resource
     * @throws TimeoutException
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
    public static function channel(?ReadChannelInterface &$read, ?WriteChannelInterface &$write, int $bufferSize = 0): void
    {
        $channel = new Channel($bufferSize);
        $read = new ReadChannel($channel);
        $write = new WriteChannel($channel);
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
     * Returns true when called from within a coroutine context.
     */
    public static function isRunning(): bool
    {
        return self::$runDepth > 0;
    }

    /**
     * Run $fn in the current coroutine as if it were a coroutine of $context, without starting a
     * coroutine: coroutines $fn starts belong to $context and keep running after $fn returns.
     * Returns what $fn returns. Unlike phasync::run(), it does not wait for them; unlike
     * phasync::go(), it costs no coroutine of its own. A server gives each request a context of its
     * own this way.
     *
     * @throws LogicException       outside a coroutine
     * @throws \phasync\ContextUsedException if $context was used before
     */
    public static function withContext(Closure $fn, object $context): mixed
    {
        $driver = self::getDriver();
        if (null === $driver->getCurrentFiber()) {
            throw ExceptionTool::popTrace(new LogicException('withContext() runs only inside a coroutine'));
        }

        return $driver->withContext($fn, $context);
    }

    /**
     * The event loop, for code that waits with its low-level API ({@see EventLoop::park()}).
     * It runs only inside phasync::run().
     *
     * @throws LogicException outside phasync::run()
     */
    public static function getLoop(): EventLoop
    {
        if (0 === self::$runDepth) {
            throw ExceptionTool::popTrace(new LogicException('The event loop runs only inside phasync::run()'));
        }

        return self::getDriver();
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
     * The root context of the running coroutine: its request, so to say. A run()'s context is its
     * own root, and so is a context entered from it (withContext(), or go() with a context of its
     * own); contexts entered from any other share that one's root. For a root context,
     * getRootContext() === getContext(). Outside a coroutine, throws LogicException.
     *
     * @throws LogicException
     */
    public static function getRootContext(): object
    {
        return self::getDriver()->getRootContext(self::getContext());
    }

    /**
     * The context of the running coroutine: the object given to run(), go() or withContext(), or
     * the one it inherited. Outside a coroutine, throws LogicException.
     *
     * @throws LogicException
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
            self::$promiseHandlerFunction = static function (mixed $promiseLike, ?Closure $onFulfilled = null, ?Closure $onRejected = null): bool {
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
     * The event loop, made at first use.
     */
    private static function getDriver(): EventLoop
    {
        if (null === self::$driver) {
            self::$driver = new EventLoop();
            // getmypid(), not posix_getpid(): they are documented aliases of each other, but
            // getmypid() is a core function, available without the posix extension -- which
            // never builds on Windows, where this line would otherwise fail to even load.
            self::$pid = \getmypid();
        }

        return self::$driver;
    }

    /**
     * Integrate with Promise like objects.
     */
    private static function handlePromise(mixed $promiseLike, ?Closure $onFulfilled = null, ?Closure $onRejected = null): bool
    {
        return (self::getPromiseHandler())($promiseLike, $onFulfilled, $onRejected);
    }
}
