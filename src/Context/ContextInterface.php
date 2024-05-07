<?php
namespace phasync\Context;

use ArrayAccess;
use Fiber;
use LogicException;
use Throwable;
use WeakMap;

/**
 * All fibers are associated with a ContextInterface object. The ContextInterface
 * object is inherited by all fibers, but can be replaced for the current fiber
 * and nested fibers.
 * 
 * @package phasync
 */
interface ContextInterface extends ArrayAccess {

    /**
     * If an exception was thrown in the context, and not handled
     * it should be assigned here. This will ensure the exception
     * is thrown by `phasync::run()`.
     * 
     * @param Throwable $exception 
     * @return void 
     * @throws LogicException if the exception is already set.
     */
    public function setContextException(Throwable $exception): void;

    /**
     * Returns the exception for the context, if it has been set.
     * 
     * @return null|Throwable 
     */
    public function getContextException(): ?Throwable;

    /**
     * Set the first fiber of the context. This can only be set once.
     * 
     * @return void 
     * @throws LogicException
     */
    public function setRootFiber(Fiber $fiber): void;

    /**
     * Get the root fiber of the context.
     * 
     * @return Fiber 
     * @throws LogicException if no root fiber has been set-
     */
    public function getRootFiber(): Fiber;

    /**
     * All the Fiber instances attached to this context and their
     * start time.
     * 
     * @return WeakMap<Fiber, float> 
     */
    public function getFibers(): WeakMap;
}