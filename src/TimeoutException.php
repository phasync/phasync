<?php

namespace phasync;

use phasync\Internal\RethrowExceptionTrait;

/**
 * A wait with a timeout ran out before it was satisfied.
 *
 * Thrown in the waiting coroutine only; what it waited for is not cancelled: an awaited coroutine keeps running, and a channel keeps its values.
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
 */
class TimeoutException extends \RuntimeException implements RethrowExceptionInterface
{
    use RethrowExceptionTrait;
}
