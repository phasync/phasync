<?php

namespace phasync\Internal;

/**
 * The context-local state of one context: a holder, because an array cannot be bound by reference into a WeakMap.
 *
 * @internal
 */
final class ContextState
{
    public function __construct(public array $a)
    {
    }
}
