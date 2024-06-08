<?php
namespace phasync;

use IteratorAggregate;

interface SubscribersInterface extends IteratorAggregate {

    public function subscribe(): SubscriberInterface;
    
}
