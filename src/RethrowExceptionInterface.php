<?php

namespace phasync;

/**
 * Exceptions implementing this interface will be rethrown by the
 * phasync class to provide a more helpful stack trace.
 */
interface RethrowExceptionInterface extends \Throwable
{
    public function rebuildStackTrace(): void;
}
