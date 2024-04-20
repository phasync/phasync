<?php
namespace phasync\Channel;

use Closure;

class ReadableChannel implements ReadableChannelInterface {

    private readonly Closure $readFunction;
    private readonly Closure $isClosedFunction;
    private readonly Closure $willBlockFunction;

    public function __construct(Closure $readFunction, Closure $isClosedFunction, Closure $willBlockFunction) {
        $this->readFunction = $readFunction;
        $this->isClosedFunction = $isClosedFunction;
        $this->willBlockFunction = $willBlockFunction;
    }

    public function read(): mixed {
        return ($this->readFunction)();
    }

    public function isClosed(): bool { 
        return ($this->isClosedFunction)();
    }

    public function willBlock(): bool {
        return ($this->willBlockFunction)();
    }

}