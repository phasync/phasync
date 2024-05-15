<?php
namespace phasync\Internal;

use phasync\ReadChannelInterface;
use phasync\WriteChannelInterface;

interface ChannelBackendInterface extends ReadChannelInterface, WriteChannelInterface {
    
}