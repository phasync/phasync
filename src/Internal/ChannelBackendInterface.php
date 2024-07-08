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

    public function awaitReadable(): void;

    public function isReadyForWrite(): bool;

    public function awaitWritable(): void;
}
