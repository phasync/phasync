<?php

namespace phasync;

interface WriteChannelInterface extends SelectableInterface
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
     * True if the channel is no longer writable.
     */
    public function isClosed(): bool;

    /**
     * Write a chunk of data to the writable stream. Writing may
     * cause the coroutine to be suspended for example in the case
     * of blocking IO.
     *
     * @param string $value
     *
     * @return int
     */
    public function write(\Serializable|array|string|float|int|bool $value): void;

    /**
     * Returns true if the channel is still readable.
     */
    public function isWritable(): bool;
}
