<?php
namespace phasync\Util;

use Closure;
use phasync\Loop;
use Throwable;

/**
 * This object will invoke the provided Closure instance when
 * the object is garbage collected.
 * 
 * @package phasync\Util
 */
final class GarbageCollectTrigger {

    public function __construct(private readonly Closure $closure) {}
    public function __destruct() {
        try {
            ($this->closure)();
        } catch (Throwable $e) {
            Loop::handleException($e);
        }
    }

}