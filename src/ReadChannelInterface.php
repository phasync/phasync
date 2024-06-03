<?php

namespace phasync;

use Serializable;
use Traversable;

/**
 * A readable channel provides messages asynchronously from various sources.
 * Messages must be serializable. Reading when no message will return `null`
 * when the channel is closed, and will block the coroutine if no messages
 * are buffered. The coroutine will be resumed as soon as another coroutine
 * writes to the channel.
 */
interface ReadChannelInterface extends SelectableInterface, Traversable
{
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
     * @throws \RuntimeException
     */
    public function read(): \Serializable|array|string|float|int|bool|null;

    /**
     * Returns true if the channel is still readable.
     */
    public function isReadable(): bool;
}
