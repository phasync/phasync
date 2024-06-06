<?php

namespace phasync\Internal;

trait SelectableTrait
{
    protected ?SelectManager $selectManager = null;

    public function await(): void {
        if ($this->selectWillBlock()) {
            $this->getSelectManager()->await();
        }
    }

    public function getSelectManager(): SelectManager {
        if (!$this->selectManager) {
            $this->selectManager = new SelectManager();
        }

        return $this->selectManager;
    }

    abstract public function selectWillBlock(): bool;
}
