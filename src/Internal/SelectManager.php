<?php

namespace phasync\Internal;

final class SelectManager
{
    private bool $hasFlags = false;
    private array $flags   = [];

    public function addFlag(object $flag): void
    {
        $this->hasFlags                     = true;
        $this->flags[\spl_object_id($flag)] = $flag;
    }

    public function removeFlag(object $flag): void
    {
        unset($this->flags[\spl_object_id($flag)]);
        $this->hasFlags = !empty($this->flags);
    }

    public function notify(): void
    {
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
