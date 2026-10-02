<?php

namespace phasync;

/**
 * Marks the exceptions that phasync rethrows with a stack trace that begins in the code that called phasync, not inside it.
 *
 * Implemented by CancelledException, ChannelException and TimeoutException.
 *
 * @see phasync\CancelledException
 * @see phasync\ChannelException
 * @see phasync\TimeoutException
 */
interface RethrowExceptionInterface extends \Throwable
{
    /**
     * Replaces the stack trace with one that starts where the exception was rethrown.
     */
    public function rebuildStackTrace(): void;
}
