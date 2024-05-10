<?php
namespace phasync\Internal;

/**
 * This class guards objects from being garbage collected and instead
 * returns the object to the pool.
 * 
 * @template T
 * @package phasync\Internal
 */
final class GcGuard {

    /**
     * The guarded instance
     * 
     * @var T
     */
    public ObjectPoolInterface $instance;

    /**
     * 
     * @param T $instance 
     * @return void 
     */
    public function __construct(ObjectPoolInterface $instance) {
        $this->instance = $instance;
    }

    public function __destruct() {
        $this->instance->returnToPool();
    }

}