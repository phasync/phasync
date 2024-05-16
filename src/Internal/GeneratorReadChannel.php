<?php
namespace phasync\Internal;

use Closure;
use Generator;
use phasync;
use phasync\ReadChannelInterface;
use Serializable;

final class ManagedReadChannel implements ReadChannelInterface {

    private ?Closure $readFunction;
    private ?Closure $isClosedFunction;
    private ?Closure $willBlockFunction;

    public function __construct(Closure $readFunction, Closure $isClosedFunction, Closure $willBlockFunction) {
        $this->readFunction = $readFunction;
        $this->isClosedFunction = $isClosedFunction;
        $this->willBlockFunction = $willBlockFunction;
    }

    public function close(): void { }

    public function isClosed(): bool {
        if ($this->isClosedFunction && ($this->isClosedFunction)()) {
            $this->isClosedFunction = null;
            $this->readFunction = null;
            $this->willBlockFunction = null;
        }
        return $this->isClosedFunction == null;
    }

    public function read(): Serializable|array|string|float|int|bool|null { 
        if ($this->readFunction === null) {
            return null;
        }
        return ($this->readFunction)();
    }

    public function isReadable(): bool {
        
    }

    public function readWillBlock(): bool { }
}