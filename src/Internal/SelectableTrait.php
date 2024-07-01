<?php

namespace phasync\Internal;

/**
 * A class implementing SelectableInterface can implement this trait.
 * The class must notify the select manager whenever the class becomes selectable
 * by invoking `$this->getSelectManager()->notify()`.
 */
trait SelectableTrait
{
    protected ?SelectManager $selectManager = null;

    public function await(): void
    {
        if ($this->selectWillBlock()) {
            $this->getSelectManager()->await();
        }
    }

    public function getSelectManager(): SelectManager
    {
        if (!$this->selectManager) {
            $this->selectManager = new SelectManager();
        }

        return $this->selectManager;
    }

    abstract public function selectWillBlock(): bool;
}
