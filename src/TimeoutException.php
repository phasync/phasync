<?php

namespace phasync;

use phasync\Internal\RethrowExceptionTrait;

/**
 * A wait with a timeout ran out before it was satisfied.
 *
 * Thrown in the waiting coroutine only; what it waited for is not cancelled: an awaited coroutine keeps running, and a channel keeps its values. `run()` throws it, too, when its frame's deadline passed ({@see Frame::setTimeout()}).
 *
 * ```php
 * phasync::run(function () {
 *     $slow = phasync::go(fn () => phasync::sleep(1));
 *
 *     try {
 *         phasync::await($slow, 0.01);
 *     } catch (phasync\TimeoutException $e) {
 *         echo "gave up\n";
 *     }
 *     phasync::cancel($slow);
 * });
 * ```
 *
 * @see phasync::await
 * @see phasync\CancelledException
 *
 * @topic waiting-and-timeouts
 *
 * @example examples/TimeoutException/1-give-up-waiting.php A timeout ends only that wait
 */
class TimeoutException extends \RuntimeException implements RethrowExceptionInterface
{
    use RethrowExceptionTrait;
}
