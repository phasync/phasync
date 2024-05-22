<?php
namespace phasync\Drivers;

use Closure;
use Countable;
use Fiber;
use FiberError;
use phasync\Context\ContextInterface;
use Throwable;

interface DriverInterface extends Countable {

    const STREAM_READ = 1;
    const STREAM_WRITE = 2;
    const STREAM_EXCEPT = 4;

    /**
     * When a fiber is suspended with this value `Fiber::suspend(DriverInterface::SUSPEND_TICK);`, 
     * it will be scheduled to run on the next tick.
     */
    const SUSPEND_TICK = 0;

    /**
     * Run the fibers that are ready to resume work.
     * 
     * @return void 
     */
    public function tick(): void;

    /**
     * Create a coroutine. If no `$context` is provided, the new Fiber will inherit the
     * context of the current coroutine, or receive a new DefaultContext instance.
     * 
     * This function must not throw exceptions; the exception must be associated with the
     * returned Fiber, and be thrown when the coroutine is awaited.
     * 
     * @param Closure $closure 
     * @param array $args 
     * @param null|ContextInterface $context 
     * @return Fiber 
     */
    public function create(Closure $closure, array $args=[], ?ContextInterface $context=null): Fiber;

    /**
     * Create a coroutine that will run independently of contexts. It will run in the event
     * loop until it completes its work. The intended use case is to provide services for
     * many other fibers, such as curl_multi_exec() invocations.
     * 
     * @param Closure $closure 
     * @return void 
     */
    public function runService(Closure $closure): void;

    /**
     * Returns the ContextInterface instance associated with the current fiber.
     * 
     * @param Fiber $fiber 
     * @return null|ContextInterface 
     */
    public function getContext(Fiber $fiber): ?ContextInterface;

    /**
     * Add a Fiber to the event loop.
     * 
     * @param Fiber $fiber 
     * @return void 
     */
    public function enqueue(Fiber $fiber): void;

    /**
     * Add a Fiber to the event loop with an exception to be thrown.
     * 
     * @internal
     * @param Fiber $fiber 
     * @param null|Throwable $exception 
     * @return void 
     */
    public function enqueueWithException(Fiber $fiber, Throwable $exception): void;

    /**
     * Activate the Fiber immediately after the next tick. This will
     * not affect the system sleep interval and is useful for reacting
     * to activity that may have occurred in other Fiber instances.
     * 
     * @param Fiber $fiber 
     * @return void 
     */
    public function afterNext(Fiber $fiber): void;

    /**
     * Activate the Fiber when there is no immediately pending activity or when the timeout has
     * occurred whichever comes first. The timeout should not throw a TimeoutException in the
     * coroutine.
     * 
     * @param float $timeout The number of seconds to allow the fiber to be suspended. Will raise a TimeoutException.
     * @param Fiber $fiber 
     * @return void 
     */
    public function whenIdle(float $timeout, Fiber $fiber): void;

    /**
     * Activate the Fiber instance when the stream resource becomes readable, writable or receives out of band data.
     * 
     * @param mixed $resource 
     * @param int $mode Bitmap of DriverInterface::STREAM_READ | DriverInterface::STREAM_WRITE | DriverInterface::STREAM_EXCEPTION
     * @param float $timeout The number of seconds to allow the fiber to be suspended. Will raise a TimeoutException.
     * @param Fiber $fiber The fiber that will be resumed.
     * @return void 
     */
    public function whenResourceActivity(mixed $resource, int $mode, float $timeout, Fiber $fiber): void;

    /**
     * Schedule the Fiber instance to run after the specified number of seconds.
     * 
     * @param float $seconds 
     * @param Fiber $fiber 
     * @return void 
     */
    public function whenTimeElapsed(float $seconds, Fiber $fiber): void;

    /**
     * Schedule the Fiber instance to run when the object is flagged
     * {@see DriverInterface::flag()}
     * 
     * @param object|int $flag 
     * @param float $timeout The number of seconds to allow the fiber to be suspended. Will raise a TimeoutException.
     * @param Fiber $fiber 
     * @return void 
     */
    public function whenFlagged(object $flag, float $timeout, Fiber $fiber): void;

    /**
     * Raise a flag to enable any fiber that is scheduled to activate on
     * this flag via {@see DriverInterface::whenFlagRaised()}.
     * 
     * @param object $flag 
     * @return int 
     */
    public function raiseFlag(object $flag): int;

    /**
     * Cancel a blocked fiber by throwing an exception inside the fiber.
     * The default exception is a CancelledException.
     * 
     * @param Fiber $fiber 
     * @param null|Throwable $exception 
     * @return void 
     * @throws RuntimeException if the fiber is not currently blocked.
     * @throws LogicException if the fiber is not managed by phasync.
     */
    public function cancel(Fiber $fiber, ?Throwable $exception=null): void;

    /**
     * Returns the unhandled exception thrown by a Fiber.
     * 
     * @param Fiber $fiber 
     * @return null|Throwable 
     */
    public function getException(Fiber $fiber): ?Throwable;

    /**
     * Returns the fiber that is currently being managed by the phasync
     * event loop. It does exactly the same as Fiber::getCurrent() unless
     * there is something "fishy" going on by running the fiber outside of
     * the event loop.
     * 
     * @return null|Fiber 
     */
    public function getCurrentFiber(): ?Fiber;
}
