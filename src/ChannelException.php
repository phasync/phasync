<?php

namespace phasync;

use phasync\Internal\RethrowExceptionTrait;

/**
 * A channel operation failed: it was written to after it was closed.
 *
 * `write()` throws it on a closed channel. A timeout is a TimeoutException, not this; a wait that nothing can ever end fails the outermost `run()` with a DeadlockException.
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
 *
 * @topic channels-and-messaging
 *
 * @example examples/ChannelException/1-write-after-close.php Writing to a closed channel
 */
class ChannelException extends \RuntimeException implements RethrowExceptionInterface
{
    use RethrowExceptionTrait;
}
