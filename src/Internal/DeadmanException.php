<?php

namespace phasync\Internal;

use phasync\RethrowExceptionInterface;

/**
 * Exception thrown when attempting to use a resource after its
 * DeadmanSwitch has been triggered (e.g., writer terminated unexpectedly).
 *
 * @internal not part of the public API; may change in any release
 */
class DeadmanException extends \RuntimeException implements RethrowExceptionInterface
{
    use RethrowExceptionTrait;
}
