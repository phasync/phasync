<?php

namespace phasync;

use phasync\Internal\RethrowExceptionTrait;

/**
 * Exception thrown when attempting to use a resource after its
 * DeadmanSwitch has been triggered (e.g., writer terminated unexpectedly).
 */
class DeadmanException extends \RuntimeException implements RethrowExceptionInterface
{
    use RethrowExceptionTrait;
}
