<?php
namespace phasync\Legacy\Channel;

interface WritableChannelInterface extends ChannelInterface {
    /**
     * Write a value to the channel. Writing may block depending on
     * channel semantics.
     * 
     * @param mixed $value 
     * @return void 
     */
    public function write(mixed $value): void;
}