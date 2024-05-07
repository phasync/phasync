<?php
namespace phasync\Internal;

use Closure;
use phasync;
use phasync\Legacy\Loop;
use Throwable;

/**
 * Use an instance of this class as a value to a WeakMap, to invoke a closure
 * when the object is garbage collected.
 * 
 * @package phasync\Util
 */
final class GarbageCollectTrigger {

    public function __construct(private readonly Closure $closure) {}
    public function __destruct() {
        try {
            ($this->closure)();
        } catch (Throwable $e) {
            phasync::logUnhandledException($e);
        }
    }

}