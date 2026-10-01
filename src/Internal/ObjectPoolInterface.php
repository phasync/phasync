<?php

namespace phasync\Internal;

/**
 * @internal not part of the public API; may change in any release
 */
interface ObjectPoolInterface
{
    public function returnToPool(): void;
}
