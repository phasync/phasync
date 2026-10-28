<?php

namespace phasync\Internal;

use phasync\ReadChannelInterface;
use phasync\WriteChannelInterface;

/**
 * Interface for channel backend implementations. This interface should only be used internally
 * since it offers little protection against deadlocks.
 *
 * @internal
 */
interface ChannelBackendInterface extends ReadChannelInterface, WriteChannelInterface
{
    public function isReadyForRead(): bool;

    public function awaitReadable(float $timeout = \PHP_FLOAT_MAX): void;

    public function isReadyForWrite(): bool;

    public function awaitWritable(float $timeout = \PHP_FLOAT_MAX): void;

    /**
     * Closes the channel from the reading end: a value written but not yet read can never be,
     * so the write() waiting with it throws.
     */
    public function closeReading(): void;
}
