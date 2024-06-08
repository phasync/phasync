<?php

namespace phasync;

use phasync\Internal\RethrowExceptionTrait;

class TimeoutException extends \RuntimeException implements RethrowExceptionInterface
{
    use RethrowExceptionTrait;
}
