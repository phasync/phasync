<?php

namespace phasync;

use Traversable;

/**
 * The reading end of a channel: values written to the channel arrive here in the order they were written.
 *
 * Any PHP value can be sent, because a channel is in-memory communication between coroutines of one process and nothing is serialized. `read()` waits while the channel is empty, `foreach` over the end reads until the channel is closed and empty, and each value goes to exactly one reader. Closing either end closes the channel, and so does dropping the last reference to either end; values already written can still be read.
 *
 * ```php
 * phasync::run(function () {
 *     phasync::channel($read, $write);
 *
 *     phasync::go(function () use ($write) {
 *         $write->write('hello');
 *         $write->close();
 *     });
 *
 *     foreach ($read as $message) {
 *         echo $message, "\n";       // hello
 *     }
 * });
 * ```
 *
 * @see phasync::channel
 * @see phasync\WriteChannelInterface
 * @see phasync\SubscriberInterface  when every reader should get every value
 */
interface ReadChannelInterface extends SelectableInterface, Traversable
{
    /**
     * Switches off the deadlock check of the channel.
     *
     * The coroutine that created the channel gets a ChannelException if it waits on the channel and no other coroutine has done so within about 100 ms. Call this when the creator is meant to wait on the channel alone, as when the other end is used by a coroutine that starts later.
     *
     * @see phasync::channel
     */
    public function activate(): void;

    /**
     * Closes the channel, for both ends.
     *
     * Readers still get the values already written, and after them the end of the channel. A write to a closed channel throws a ChannelException.
     *
     * @see ReadChannelInterface::isClosed
     */
    public function close(): void;

    /**
     * Returns true if the channel has been closed, also when values are left to read.
     *
     * @see ReadChannelInterface::isReadable
     */
    public function isClosed(): bool;

    /**
     * Returns the next value, and waits while there is none and the channel is open.
     *
     * `null` is both a value that can be written and what `read()` returns when the channel is closed with nothing left, so the return value alone cannot tell them apart. Pass `$eof`: it is set to true only when the channel is closed and empty, and false otherwise, also when the value is `null`.
     *
     * ```php
     * phasync::run(function () {
     *     phasync::channel($read, $write, 2);
     *     $write->write(null);
     *     $write->close();
     *
     *     $read->read(eof: $eof);   // null, $eof is false: a value
     *     $read->read(eof: $eof);   // null, $eof is true: the end
     * });
     * ```
     *
     * @param float     $timeout seconds to wait at most
     * @param bool|null $eof     set to true if the channel is closed and empty
     *
     * @throws TimeoutException if nothing arrived in time
     * @throws ChannelException if the creator of the channel waits on it alone, see {@see ReadChannelInterface::activate()}
     *
     * @return mixed the value, or null at the end of the channel
     *
     * @see WriteChannelInterface::write
     */
    public function read(float $timeout = \PHP_FLOAT_MAX, ?bool &$eof = null): mixed;

    /**
     * Returns true if the channel is open, or has values left to read.
     *
     * @see ReadChannelInterface::isClosed
     */
    public function isReadable(): bool;
}
