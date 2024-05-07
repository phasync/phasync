<?php
namespace phasync\Legacy\Monitors;

/**
 * Monitors are implementations that provide resume signals for
 * fibers - for example when a stream resource becomes readable
 * or writable, or if a database connection is ready or if for
 * example a curl handle has completed.
 * 
 * When only a single monitor is active, the monitor will be
 * instructed to pause for a limited time window. When multiple
 * monitors are active, each monitor must immediately poll for
 * events and return without delay.
 * 
 * @package phasync\Services
 */
interface MonitorInterface {

    /**
     * Returns true if the service is monitoring
     * 
     * @return bool 
     */
    public function isActive(): bool;

    public function tick(float $time): void;

}