<?php

namespace phasync;

/**
 * A safety mechanism that triggers a callback when garbage collected.
 *
 * This is useful for detecting when a coroutine terminates unexpectedly,
 * allowing cleanup actions like marking a buffer as failed or closing a connection.
 *
 * Usage:
 * ```php
 * phasync::go(function() use ($sb) {
 *     $deadman = $sb->getDeadmanSwitch();
 *
 *     while ($data = fread($socket, 8192)) {
 *         $sb->write($data);
 *     }
 *     $sb->end(); // Always end properly
 *     // If coroutine exits without calling end(), $deadman triggers on GC
 * });
 * ```
 */
final class DeadmanSwitch
{
    private ?\Closure $callback;
    private bool $triggered = false;

    /**
     * Create a new DeadmanSwitch.
     *
     * @param callable $callback The callback to execute when the switch is triggered
     */
    public function __construct(callable $callback)
    {
        $this->callback = $callback(...);
    }

    /**
     * Destructor triggers the callback if not already triggered or disarmed.
     */
    public function __destruct()
    {
        if (!$this->triggered && $this->callback !== null) {
            $this->trigger();
        }
    }

    /**
     * Manually trigger the switch, executing the callback.
     * Can only be triggered once.
     */
    public function trigger(): void
    {
        if ($this->triggered) {
            return;
        }
        $this->triggered = true;
        if ($this->callback !== null) {
            ($this->callback)();
            $this->callback = null;
        }
    }

    /**
     * Disarm the switch, preventing the callback from being executed.
     * Use this when the resource was closed normally.
     */
    public function disarm(): void
    {
        $this->triggered = true;
        $this->callback = null;
    }

    /**
     * Check if the switch has been triggered.
     */
    public function isTriggered(): bool
    {
        return $this->triggered;
    }
}
