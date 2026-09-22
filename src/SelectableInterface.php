<?php

namespace phasync;

/**
 * Selectable objects give their own direct callers a non-blocking readiness check
 * (isReady()) and a blocking wait (await()), for example {@see Util\WaitGroup}
 * or {@see Util\StringBuffer}.
 */
interface SelectableInterface
{
    /**
     * Wait for the resource to be non-blocking.
     */
    public function await(float $timeout = \PHP_FLOAT_MAX): void;

    /**
     * Returns true when accessing the object will not block (for example if
     * data is available or if the source is closed or in a failed state).
     */
    public function isReady(): bool;
}
