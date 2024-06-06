<?php

namespace phasync;

use phasync\Internal\ObjectPoolInterface;

/**
 * This interface is used to values that are not objects or
 * not extendable selectable via phasync::select().
 */
interface SelectorInterface extends SelectableInterface, ObjectPoolInterface
{
    /**
     * Returns the selected instance.
     */
    public function getSelected(): mixed;
}
