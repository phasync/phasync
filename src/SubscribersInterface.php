<?php

namespace phasync;

interface SubscribersInterface extends \IteratorAggregate
{
    /**
     * Subscribe to future messages from the publisher channel.
     */
    public function subscribe(): SubscriberInterface;
}
