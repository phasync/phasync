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
     * Write a value to the channel. Writing may cause the coroutine to be
     * suspended, for example while waiting for a reader on an unbuffered
     * channel.
     *
     * Any value is accepted -- a channel is single-process, in-memory
     * communication between coroutines, so nothing is ever serialized. This
     * is unrelated to whether a value can cross a process boundary; that is
     * a separate concern (see the 2.0.0 clustering primitive in
     * docs/roadmap-2.0.md), not something a channel itself restricts.
     */
    public function write(mixed $value, float $timeout = \PHP_FLOAT_MAX): void;

    /**
     * Returns true if the channel is still readable.
     */
    public function isWritable(): bool;
}
