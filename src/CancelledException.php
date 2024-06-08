<?php

namespace phasync;

use phasync\Internal\RethrowExceptionTrait;

/**
 * The operation that was being awaited has been cancelled.
 */
class CancelledException extends \RuntimeException implements RethrowExceptionInterface
{
    use RethrowExceptionTrait;
}
