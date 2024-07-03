<?php

namespace phasync\Internal;

use phasync\SelectorInterface;

final class Selector
{
    public static function create(mixed $value): ?SelectorInterface
    {
        if ($value instanceof \Fiber) {
            return FiberSelector::create($value);
        } elseif ($value instanceof \Closure) {
            return ClosureSelector::create($value);
        }

        return null;
    }
}
