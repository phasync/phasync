<?php

namespace phasync\Psr;

/**
 * A PSR-7 StreamInterface containing a constant string.
 */
class StringStream extends BufferedStream
{
    public function __construct(string $contents)
    {
        parent::__construct();
        $this->append($contents);
        $this->end();
    }
}
