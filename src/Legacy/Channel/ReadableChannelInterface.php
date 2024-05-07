<?php
namespace phasync\Legacy\Channel;

interface ReadableChannelInterface extends ChannelInterface {
    /**
     * Read a value from the channel. Reading may block the
     * coroutine depending on channel semantics.
     * 
     * @return mixed 
     */
    public function read(): mixed;
}