<?php

namespace phasync;

use phasync\Internal\RethrowExceptionTrait;

/**
 * Thrown in a coroutine whose wait was cancelled: with `phasync::cancel()`, or with its frame (`Frame::cancel()`, the frame's deadline, a deadlock).
 *
 * The coroutine meets it at its next wait, or at once if it is waiting, and its `finally` blocks run as it unwinds. It is sticky: waits after it throw it again, so cleanup that must wait belongs in `phasync::finally()`. A coroutine that ends with it does not fail its `run()`. The failure that caused a cancellation, when there is one, is `getPrevious()`.
 *
 * A wait on a flag that can no longer be raised throws it too ("The flag no longer exists").
 *
 * ```php
 * phasync::run(function () {
 *     $worker = phasync::go(function () {
 *         try {
 *             phasync::sleep(10);
 *         } catch (phasync\CancelledException $e) {
 *             echo "cancelled: ", $e->getMessage(), "\n";   // shutting down
 *             throw $e;
 *         }
 *     });
 *     phasync::sleep(0.01);
 *     phasync::cancel($worker, 'shutting down');
 * });
 * ```
 *
 * @see phasync::cancel
 * @see phasync::finally
 * @see phasync\TimeoutException
 *
 * @topic cancellation-and-cleanup
 *
 * @example examples/CancelledException/1-clean-up-and-let-it-through.php Clean up, then let the cancellation end the coroutine
 */
class CancelledException extends \RuntimeException implements RethrowExceptionInterface, \async\CancelledException
{
    use RethrowExceptionTrait;
}
