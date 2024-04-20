<?php
namespace phasync;

use WeakMap;
use WeakReference;

/**
 * All fibers are associated with a ContextInterface object. The ContextInterface
 * object is inherited by all fibers, but can be replaced for the current fiber
 * and nested fibers.
 * 
 * @package phasync
 */
interface ContextInterface {

    /**
     * All the Fiber instances attached to this context and their
     * start time.
     * 
     * @return WeakMap<Fiber, float> 
     */
    public function getFibers(): WeakMap;

}