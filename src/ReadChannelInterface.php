<?php

namespace phasync;

use Traversable;

/**
 * A readable channel provides messages asynchronously from various sources.
 * Any value can be written and read -- a channel is single-process, in-memory
 * communication between coroutines, so nothing is ever serialized. Reading
 * when no message will return `null` when the channel is closed, and will
 * block the coroutine if no messages are buffered. The coroutine will be
 * resumed as soon as another coroutine writes to the channel.
 */
interface ReadChannelInterface extends SelectableInterface, Traversable
{
    /**
     * This function can be used to activate the channel so that
     * the deadlock protection does not fail.
     */
    public function activate(): void;

    /**
     * Closes the channel.
     */
    public function close(): void;

    /**
     * True if the channel is no longer readable.
     */
    public function isClosed(): bool;

    /**
     * Returns the next item that can be read. If no item is
     * available and the channel is still open, the function
     * will suspend the coroutine and allow other coroutines
     * to work.
     *
     * `null` is both a legal value (if it was written) and what is returned when the channel
     * is closed with nothing left to read, so the two are not distinguishable from the return
     * value alone. Pass `$eof` by reference to tell them apart: it is set to `true` only when
     * the channel is closed and there was nothing left, `false` otherwise (including when the
     * returned value happens to be `null`).
     *
     * @throws \RuntimeException
     */
    public function read(float $timeout = \PHP_FLOAT_MAX, ?bool &$eof = null): mixed;

    /**
     * Returns true if the channel is still readable.
     */
    public function isReadable(): bool;
}
