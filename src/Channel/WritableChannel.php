<?php
namespace phasync\Channel;

use Closure;

class WritableChannel implements WritableChannelInterface {

    private readonly Closure $writeFunction;
    private readonly Closure $isClosedFunction;
    private readonly Closure $willBlockFunction;

    public function __construct(Closure $writeFunction, Closure $isClosedFunction, Closure $willBlockFunction) {
        $this->writeFunction = $writeFunction;        
        $this->isClosedFunction = $isClosedFunction;
        $this->willBlockFunction = $willBlockFunction;
    }

    public function write(mixed $value): void {
        ($this->writeFunction)($value);
    }

    public function isClosed(): bool { 
        return ($this->isClosedFunction)();
    }

    public function willBlock(): bool {
        return ($this->willBlockFunction)();
    }

}