<?php

namespace phasync;

/**
 * Trait that provides deadman switch functionality to a class.
 *
 * Classes using this trait must implement the deadmanSwitchTriggered() method
 * to define what happens when the switch is triggered (e.g., close resources,
 * end buffers, etc.).
 *
 * Usage:
 * ```php
 * class MyBuffer {
 *     use DeadmanSwitchTrait;
 *
 *     private bool $failed = false;
 *
 *     protected function deadmanSwitchTriggered(): void {
 *         $this->failed = true;
 *         $this->close();
 *     }
 * }
 * ```
 */
trait DeadmanSwitchTrait
{
    /**
     * Weak reference to the deadman switch, allowing it to be GC'd
     * when the user's reference goes out of scope.
     *
     * @var \WeakReference<DeadmanSwitch>|null
     */
    private ?\WeakReference $deadmanSwitch = null;

    /**
     * Get a deadman switch for this object.
     *
     * When the returned DeadmanSwitch is garbage collected (e.g., when the
     * owning coroutine exits), the deadmanSwitchTriggered() method will be called.
     *
     * Returns the same instance if called multiple times while the switch is still alive.
     */
    public function getDeadmanSwitch(): DeadmanSwitch
    {
        $switch = $this->deadmanSwitch?->get();
        if (null !== $switch) {
            return $switch;
        }

        $switch              = new DeadmanSwitch($this->deadmanSwitchTriggered(...));
        $this->deadmanSwitch = \WeakReference::create($switch);

        return $switch;
    }

    /**
     * Called when the deadman switch is triggered.
     *
     * Implement this method to define cleanup behavior when the switch
     * owner (e.g., writer coroutine) terminates unexpectedly.
     */
    abstract protected function deadmanSwitchTriggered(): void;
}
