<?php

namespace phasync;

use phasync\Internal\RethrowExceptionTrait;

/**
 * A generic exception occurred in the channel.
 */
class ChannelException extends \RuntimeException implements RethrowExceptionInterface
{
    use RethrowExceptionTrait;
}
