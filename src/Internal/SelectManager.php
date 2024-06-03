<?php

namespace phasync\Internal;

use phasync;
use phasync\TimeoutException;
use stdClass;
use Throwable;

/**
 * SelectManager provides functionality for awaiting multiple selectables
 * simultaneously without polling.
 * 
 * @internal
 * @package phasync\Internal
 */
final class SelectManager {

    private bool $hasFlags = false;
    private array $flags   = [];

    public function addFlag(object $flag): void
    {
        $this->hasFlags                     = true;
        $this->flags[\spl_object_id($flag)] = $flag;
    }

    public function removeFlag(object $flag): void
    {

    /**
     * Block the current coroutine until the select manager becomes
     * selectable.
     * 
     * @return void 
     * @throws TimeoutException 
     * @throws Throwable 
     */
    public function await(): void {
        phasync::awaitFlag($this);
    }

    /**
     * Add a flag which will be raised when this object becomes
     * non-blocking.
     * 
     * @param object $flag 
     * @return void 
     */
    public function addFlag(object $flag): void {
        $this->hasFlags = true;
        $this->flags[\spl_object_id($flag)] = $flag;
    }

    /**
     * Remove the flag
     * 
     * @param object $flag 
     * @return void 
     */
    public function removeFlag(object $flag): void {
        unset($this->flags[\spl_object_id($flag)]);
        $this->hasFlags = !empty($this->flags);
    }

    public function notify(): void {
        phasync::raiseFlag($this);
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
