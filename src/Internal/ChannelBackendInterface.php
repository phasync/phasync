<?php
namespace phasync\Internal;

use phasync\ReadChannelInterface;
use phasync\WriteChannelInterface;

/**
 * Interface for channel backend implementations. This interface should only be used internally
 * since it offers little protection against deadlocks.
 * 
 * @internal
 * @package phasync
 */
interface ChannelBackendInterface extends ReadChannelInterface, WriteChannelInterface {

    public function readWillBlock(): bool;
    public function writeWillBlock(): bool;

}
