<?php

namespace phasync;

use phasync\Internal\RethrowExceptionTrait;

/**
 * A channel operation failed: it was written to after it was closed, or the wait looked like a deadlock.
 *
 * `write()` throws it on a closed channel. The creator-coroutine check throws it from the coroutine that called `phasync::channel()`, when it waits on the channel and no other coroutine has done so within about 100 ms; see {@see phasync::channel()}, and `activate()` to switch the check off. A timeout is a TimeoutException, not this.
 *
 * ```php
 * phasync::run(function () {
 *     phasync::channel($read, $write, 1);
 *     phasync::go(fn () => $read->close());
 *     phasync::sleep(0.01);
 *
 *     try {
 *         $write->write('late');
 *     } catch (phasync\ChannelException $e) {
 *         echo $e->getMessage(), "\n";   // Channel is closed
 *     }
 * });
 * ```
 *
 * @see phasync::channel
 * @see phasync\WriteChannelInterface::write
 */
class ChannelException extends \RuntimeException implements RethrowExceptionInterface
{
    use RethrowExceptionTrait;
}
