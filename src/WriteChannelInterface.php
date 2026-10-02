<?php

namespace phasync;

/**
 * The writing end of a channel: `write()` sends a value to the readers of the channel.
 *
 * On an unbuffered channel `write()` returns when a reader has taken the value; on a buffered one it returns at once while the buffer has room, and waits when it is full. Dropping the last reference to an end closes the channel, so a reader sees the end when the writer is gone.
 *
 * ```php
 * phasync::run(function () {
 *     phasync::channel($read, $write, 3);
 *
 *     phasync::go(function () use ($write) {
 *         foreach ([1, 2, 3] as $number) {
 *             $write->write($number);
 *         }
 *         $write->close();
 *     });
 *
 *     foreach ($read as $number) {
 *         echo $number, "\n";
 *     }
 * });
 * ```
 *
 * @see phasync::channel
 * @see phasync\ReadChannelInterface
 */
interface WriteChannelInterface extends SelectableInterface
{
    /**
     * Switches off the deadlock check of the channel.
     *
     * The coroutine that created the channel gets a ChannelException if it waits on the channel and no other coroutine has done so within about 100 ms. Call this when the creator is meant to wait on the channel alone.
     *
     * @see phasync::channel
     */
    public function activate(): void;

    /**
     * Closes the channel, for both ends.
     *
     * Readers still get the values already written, and after them the end of the channel. A write to a closed channel throws a ChannelException.
     *
     * @see WriteChannelInterface::isClosed
     */
    public function close(): void;

    /**
     * Returns true if the channel has been closed.
     *
     * @see WriteChannelInterface::isWritable
     */
    public function isClosed(): bool;

    /**
     * Sends a value, and waits while the channel cannot take it: for a reader on an unbuffered channel, for room on a full buffer.
     *
     * Any value is accepted: a channel is in-memory communication between coroutines of one process, and nothing is serialized.
     *
     * @param mixed $value   what to send
     * @param float $timeout seconds to wait at most
     *
     * @throws ChannelException if the channel is closed, or the creator of the channel waits on it alone, see {@see WriteChannelInterface::activate()}
     * @throws TimeoutException if the value could not be sent in time
     *
     * @see ReadChannelInterface::read
     */
    public function write(mixed $value, float $timeout = \PHP_FLOAT_MAX): void;

    /**
     * Returns true if the channel is open, so a write is accepted.
     *
     * @see WriteChannelInterface::isClosed
     */
    public function isWritable(): bool;
}
