<?php

namespace phasync\Internal;

use phasync\TimeoutException;

/**
 * SelectManager provides functionality for awaiting multiple selectables
 * simultaneously without polling.
 *
 * @internal
 */
final class SelectManager
{
    private bool $hasFlags = false;
    private array $flags   = [];

    /**
     * Block the current coroutine until the select manager becomes
     * selectable.
     *
     * @throws TimeoutException
     * @throws \Throwable
     */
    public function await(): void
    {
        \phasync::awaitFlag($this);
    }

    /**
     * Add a flag which will be raised when this object becomes
     * non-blocking.
     */
    public function addFlag(object $flag): void
    {
        $this->hasFlags                     = true;
        $this->flags[\spl_object_id($flag)] = $flag;
    }

    /**
     * Remove the flag
     */
    public function removeFlag(object $flag): void
    {
        unset($this->flags[\spl_object_id($flag)]);
        $this->hasFlags = !empty($this->flags);
    }

    public function notify(): void
    {
        \phasync::raiseFlag($this);
        if (!$this->hasFlags) {
            return;
        }
        foreach ($this->flags as $key => $flag) {
            \phasync::raiseFlag($flag);
            unset($this->flags[$key]);
        }
        $this->hasFlags = false;
    }
}
