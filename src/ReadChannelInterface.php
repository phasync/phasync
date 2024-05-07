<?php
namespace phasync;

use RuntimeException;
use Serializable;

interface ReadChannelInterface extends SelectableInterface {

    /**
     * Closes the channel.
     * 
     * @return void 
     */
    public function close(): void;

    /**
     * Returns the next item that can be read. If no item is
     * available and the channel is still open, the function
     * will suspend the coroutine and allow other coroutines
     * to work.
     * 
     * @return Serializable|array|string|float|int|bool|null
     * @throws RuntimeException
     */
    public function read(): Serializable|array|string|float|int|bool|null;

    /**
     * Returns true if the channel is still readable.
     * 
     * @return bool 
     */
    public function isReadable(): bool;
}