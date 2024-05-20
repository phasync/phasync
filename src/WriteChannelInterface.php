<?php
namespace phasync;

use Serializable;

interface WriteChannelInterface extends SelectableInterface {

    /**
     * Closes the channel.
     * 
     * @return void 
     */
    public function close(): void;

    /**
     * True if the channel is no longer writable.
     * 
     * @return bool 
     */
    public function isClosed(): bool;

    /**
     * Write a chunk of data to the writable stream. Writing may
     * cause the coroutine to be suspended for example in the case
     * of blocking IO.
     * 
     * @param string $value 
     * @return int 
     */
    public function write(Serializable|array|string|float|int|bool $value): void;

    /**
     * Returns true if the channel is still readable.
     * 
     * @return bool 
     */
    public function isWritable(): bool;
}
