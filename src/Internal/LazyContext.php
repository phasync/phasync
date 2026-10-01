<?php

namespace phasync\Internal;

use phasync\Context\ContextFactoryInterface;

/**
 * A withContext() call given a factory: until the closure needs its context, only this exists.
 *
 * @internal
 */
final class LazyContext
{
    public ?object $context = null;

    /** @var list<\Closure>|null the phasync::finally() list of the enclosing withContext(), once entered */
    public ?array $outerFinally = null;

    public function __construct(public readonly ContextFactoryInterface $factory)
    {
    }
}
