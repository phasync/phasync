<?php

/*
 * The exception interfaces of fiber-interop/api, which phasync\TimeoutException and
 * phasync\CancelledException implement. Declared here, as the api declares them, only when the
 * api is not installed: code that catches async\TimeoutException catches phasync's either way.
 */

namespace async;

if (!\interface_exists(TimeoutException::class)) {
    /** Thrown by await*, readable() and writable() when the timeout expires. The awaited task keeps running. */
    interface TimeoutException extends \Throwable
    {
    }
}

if (!\interface_exists(CancelledException::class)) {
    /** Thrown by sleep() and the waits when the owning loop cancels the task. Loops implement it on their own exception class. */
    interface CancelledException extends \Throwable
    {
    }
}
