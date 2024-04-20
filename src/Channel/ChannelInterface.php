<?php
namespace phasync\Channel;

interface ChannelInterface {
    /**
     * Indicates if the channel is closed or in the process of closing.
     * 
     * @return bool 
     */
    public function isClosed(): bool;

    /**
     * Returns true if the channel will block reading.
     * 
     * @return bool 
     */
    public function willBlock(): bool;
}