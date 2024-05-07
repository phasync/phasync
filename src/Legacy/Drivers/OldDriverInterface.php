<?php
namespace phasync\Legacy\Drivers;

use Closure;
use Countable;
use CurlHandle;
use Fiber;
use mysqli;
use phasync\Context\ContextInterface;
use Throwable;

/**
 * The interface for the Fiber event loop
 * 
 * @package phasync
 */
interface OldDriverInterface extends Countable {

    public function setDefaultTimeout(float $defaultTimeout): void;
    public function getDefaultTimeout(): float;

    /**
     * Get the ContextInterface instance associated with the fiber.
     * 
     * @param Fiber $fiber 
     * @return ContextInterface 
     */
    public function getContext(Fiber $fiber): ContextInterface;

    /**
     * Check if a Fiber threw an exception, and return the Throwable
     * instance if it did.
     * 
     * @param Fiber $fiber 
     * @return null|Throwable 
     */
    public function getException(Fiber $fiber): ?Throwable;

    /**
     * Return true if the fiber is currently blocked and will be resumed
     * again by the event loop.
     * 
     * @param Fiber $fiber 
     * @return bool 
     */
    public function isPending(Fiber $fiber): bool;

    /**
     * Return the total number of fibers managed directly or indirectly by
     * the event loop.
     * 
     * @return int 
     */
    public function count(): int;

    /**
     * Run all fibers that are scheduled to activate. The max sleep time
     * is the maximum amount of time that the driver can spend waiting for
     * IO or scheduled tasks to activate.
     * 
     * @return void 
     */
    public function tick(float $maxSleepTime=1): void;

    /**
     * Schedule a Fiber to resume whenever there is activity on the curl
     * handle.
     * 
     * @param CurlHandle $curl 
     * @param float|null $timeout 
     * @param Fiber $fiber 
     * @return void 
     */
    public function waitForCurl(CurlHandle $curl, float $timeout=null, Fiber $fiber): void;

    /**
     * Schedule a Fiber to resume whenever there is activity on the mysqli
     * handle.
     * 
     * @param mysqli $connection 
     * @param float|null $timeout 
     * @param Fiber $fiber 
     * @return void 
     */
    public function waitForMysqli(mysqli $connection, float $timeout=null, Fiber $fiber): void;

    /**
     * Schedule a Fiber to resume whenever the flag is raised
     * via {@see DriverInterface::raiseFlag()}.
     * 
     * @param object $flag 
     * @param float $timeout A timeout for the operation, defaults to {@see self::getDefaultTimeout()} seconds. A value <= 0 will disable the timeout.
     * @param Fiber $fiber 
     * @return void 
     */
    public function waitForFlag(object $flag, float $timeout=null, Fiber $fiber): void;

    /**
     * Activate all fibers that have been scheduled to activate
     * by this flag using {@see DriverInterface::waitForFlag()}.
     * 
     * @param object $flag 
     * @return int Number of resumed fibers
     */
    public function raiseFlag(object $flag): int;

    /**
     * Schedule a Fiber to run whenever the event loop becomes
     * idle. The event loop is idle whenever there are no events
     * queued, except timeouts and stream polling.
     * 
     * @param float $timeout A timeout for the operation, defaults to {@see self::getDefaultTimeout()} seconds. A value <= 0 will disable the timeout.
     * @param Fiber $fiber 
     * @return void 
     */
    public function idle(float $timeout=null, Fiber $fiber): void;

    /**
     * Enqueue a fiber to run on the next tick.
     * 
     * @param Fiber $fiber 
     * @return void 
     */
    public function enqueue(Fiber $fiber): void;

    /**
     * Schedule a closure to run when this fiber is 
     * done running.
     * 
     * @param Closure $deferredFunction 
     * @return void 
     */
    public function defer(Closure $deferredFunction, Fiber $fiber): void;

    /**
     * Schedule a fiber to run after a number of seconds.
     * 
     * @param float $seconds 
     * @param Fiber $fiber 
     * @return void 
     */
    public function delay(float $seconds, Fiber $fiber): void;

    /**
     * Schedule the fiber as soon as the resource becomes readable or
     * an exceptional condition occurs on the stream. Closing a stream
     * resource that is pending will
     * 
     * @param mixed $resource 
     * @param float $timeout A timeout for the operation, defaults to {@see self::getDefaultTimeout()} seconds. A value <= 0 will disable the timeout.
     * @param Fiber $fiber 
     * @return void 
     */
    public function readable($resource, float $timeout=null, Fiber $fiber): void;

    /**
     * Schedule the fiber as soon as the resource becomes writable or
     * an exceptional condition occurs on the stream.
     * 
     * @param mixed $resource 
     * @param float $timeout A timeout for the operation, defaults to {@see self::getDefaultTimeout()} seconds. A value <= 0 will disable the timeout.
     * @param Fiber $fiber 
     * @return void 
     */
    public function writable($resource, float $timeout=null, Fiber $fiber): void;

    /**
     * Cancel a Fiber that is scheduled to run. The fiber should be scheduled
     * to run again in the event loop depending on the purpose of cancelling it.
     * 
     * @return bool 
     */
    public function cancel(Fiber $fiber): bool;

    /**
     * Create a Fiber
     * 
     * @param Closure $function 
     * @param array $args 
     * @return Fiber 
     */
    public function startFiber(Closure $function, array $args): Fiber;
}