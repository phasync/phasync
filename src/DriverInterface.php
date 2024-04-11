<?php
namespace phasync;

use Countable;
use Fiber;
use Throwable;

/**
 * The interface for the Fiber event loop
 * 
 * @package phasync
 */
interface DriverInterface extends Countable {

    /**
     * Return true if the fiber is pending resume in the event
     * loop.
     * 
     * @param Fiber $fiber 
     * @return bool 
     */
    public function isPending(Fiber $fiber): bool;

    /**
     * Return number of suspended fibers in total managed by the driver
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
     * Schedule a Fiber to run whenever the event loop becomes
     * idle. The event loop is idle whenever there are no events
     * queued, except timeouts and stream polling.
     * 
     * @param Fiber $fiber 
     * @return void 
     */
    public function idle(Fiber $fiber): void;

    /**
     * Enqueue a fiber to run on the next tick.
     * 
     * @param Fiber $fiber 
     * @return void 
     */
    public function enqueue(Fiber $fiber): void;

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
     * @param Fiber $fiber 
     * @return void 
     */
    public function readable($resource, Fiber $fiber): void;

    /**
     * Schedule the fiber as soon as the resource becomes writable or
     * an exceptional condition occurs on the stream.
     * 
     * @param mixed $resource 
     * @param Fiber $fiber 
     * @return void 
     */
    public function writable($resource, Fiber $fiber): void;

    /**
     * Cancel a Fiber that is scheduled to run. Returns true
     * if a fiber was cancelled.
     * 
     * @return bool 
     */
    public function cancel(Fiber $fiber): bool;
}