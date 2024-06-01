<?php
namespace phasync;

use RuntimeException;
use Serializable;
use Traversable;

/**
 * A readable channel provides messages asynchronously from various sources.
 * Messages must be serializable. Reading when no message will return `null`
 * when the channel is closed, and will block the coroutine if no messages
 * are buffered. The coroutine will be resumed as soon as another coroutine
 * writes to the channel.
 * 
 * @package phasync
 */
interface ReadChannelInterface extends SelectableInterface, Traversable {

    /**
     * This function can be used to activate the channel so that
     * the deadlock protection does not fail.
     * 
     * @return void 
     */
    public function activate(): void;

    /**
     * Closes the channel.
     * 
     * @return void 
     */
    public function close(): void;

    /**
     * True if the channel is no longer readable.
     * 
     * @return bool 
     */
    public function isClosed(): bool;

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
