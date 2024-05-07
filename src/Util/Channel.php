<?php
namespace phasync\Util;

use Closure;
use phasync\Legacy\Channel\ReadableChannel;
use phasync\ReadChannelInterface;
use phasync\WriteChannelInterface;

final class Channel {

    private ReadChannelInterface $readChannel;
    private WriteChannelInterface $writeChannel;


    public function __construct(int $bufferSize = 0) {
        
    }

    public function getReadChannel(): ReadChannelInterface {

    }

    public function getWriteChannel(): WriteChannelInterface {

    }
}