<?php

namespace phasync\Drivers;

use Closure;
use Fiber;
use phasync\Context\ContextInterface;

interface DriverInterface extends \Countable
{
    public const STREAM_READ   = 1;
    public const STREAM_WRITE  = 2;
    public const STREAM_EXCEPT = 4;

    /**
     * When a fiber is suspended with this value `Fiber::suspend(DriverInterface::SUSPEND_TICK);`,
     * it will be scheduled to run on the next tick.
     */
    public const SUSPEND_TICK = 0;

    /**
     * Run the fibers that are ready to resume work.
     */
    public function tick(): void;

    /**
     * Create a coroutine. If no `$context` is provided, the new Fiber will inherit the
     * context of the current coroutine, or receive a new DefaultContext instance.
     *
     * This function must not throw exceptions; the exception must be associated with the
     * returned Fiber, and be thrown when the coroutine is awaited.
     */
    public function create(\Closure $closure, array $args = [], ?ContextInterface $context = null): \Fiber;

    /**
     * Schedule a callback to be invoked after the current (or next) tick, outside of the fiber.
     *
     * @param Closure $closure
     */
    public function defer(\Closure $closure): void;

    /**
     * Create a coroutine that will run independently of contexts. It will run in the event
     * loop until it completes its work. The intended use case is to provide services for
     * many other fibers, such as curl_multi_exec() invocations.
     */
    public function runService(\Closure $closure): void;

    /**
     * Returns the ContextInterface instance associated with the current fiber.
     */
    public function getContext(\Fiber $fiber): ?ContextInterface;

    /**
     * Add a Fiber to the event loop.
     */
    public function enqueue(\Fiber $fiber): void;

    /**
     * Add a Fiber to the event loop with an exception to be thrown.
     *
     * @internal
     *
     * @param \Throwable|null $exception
     */
    public function enqueueWithException(\Fiber $fiber, \Throwable $exception): void;

    /**
     * Activate the Fiber immediately after the next tick. This will
     * not affect the system sleep interval and is useful for reacting
     * to activity that may have occurred in other Fiber instances.
     */
    public function afterNext(\Fiber $fiber): void;

    /**
     * Activate the Fiber when there is no immediately pending activity or when the timeout has
     * occurred whichever comes first. The timeout should not throw a TimeoutException in the
     * coroutine.
     *
     * @param float $timeout The number of seconds to allow the fiber to be suspended. Will raise a TimeoutException.
     */
    public function whenIdle(float $timeout, \Fiber $fiber): void;

    /**
     * Activate the Fiber instance when the stream resource becomes readable, writable or receives out of band data.
     *
     * @param int    $mode    Bitmap of DriverInterface::STREAM_READ | DriverInterface::STREAM_WRITE | DriverInterface::STREAM_EXCEPTION
     * @param float  $timeout The number of seconds to allow the fiber to be suspended. Will raise a TimeoutException.
     * @param \Fiber $fiber   the fiber that will be resumed
     */
    public function whenResourceActivity(mixed $resource, int $mode, float $timeout, \Fiber $fiber): void;

    /**
     * Returns the last resource state result for a fiber that called {@see self::whenResourceActivity()}
     * The result is a bitmap of DriverInterface::STREAM_* constants.
     */
    public function getLastResourceState(Fiber $fiber): ?int;

    /**
     * Schedule the Fiber instance to run after the specified number of seconds.
     */
    public function whenTimeElapsed(float $seconds, \Fiber $fiber): void;

    /**
     * Schedule the Fiber instance to run when the object is flagged
     * {@see DriverInterface::flag()}
     *
     * @param object|int $flag
     * @param float      $timeout The number of seconds to allow the fiber to be suspended. Will raise a TimeoutException.
     */
    public function whenFlagged(object $flag, float $timeout, \Fiber $fiber): void;

    /**
     * Raise a flag to enable any fiber that is scheduled to activate on
     * this flag via {@see DriverInterface::whenFlagRaised()}.
     */
    public function raiseFlag(object $flag): int;

    /**
     * Cancel a blocked fiber by throwing an exception inside the fiber.
     * The default exception is a CancelledException.
     *
     * @throws RuntimeException if the fiber is not currently blocked
     * @throws LogicException   if the fiber is not managed by phasync
     */
    public function cancel(\Fiber $fiber, ?\Throwable $exception = null): void;

    /**
     * Removes a fiber from the event loop completely, without throwing any exception.
     * This is for advanced use cases where coroutines can be discarded without
     * further notice or error handling.
     *
     * @param Fiber $fiber
     * @return bool True if a fiber was discarded.
     */
    public function discard(Fiber $fiber): bool;

    /**
     * Returns the unhandled exception thrown by a Fiber.
     */
    public function getException(\Fiber $fiber): ?\Throwable;

    /**
     * Returns the fiber that is currently being managed by the phasync
     * event loop. It does exactly the same as Fiber::getCurrent() unless
     * there is something "fishy" going on by running the fiber outside of
     * the event loop.
     */
    public function getCurrentFiber(): ?\Fiber;
}
