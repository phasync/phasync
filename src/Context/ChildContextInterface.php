<?php

namespace phasync\Context;

/**
 * A context whose coroutines work on behalf of another context's: a part of its work that can
 * be cancelled on its own. An application server gives each request a context; a component
 * framework gives the parts of a page contexts of their own, children of the request's.
 *
 * phasync itself gives the parent no role. It is for code that keeps per-request state keyed
 * by the current context: walk up to the context that is not a child, and key by that.
 *
 *     $context = phasync::getContext();
 *     while ($context instanceof ChildContextInterface) {
 *         $context = $context->getParentContext();
 *     }
 */
interface ChildContextInterface extends ContextInterface
{
    public function getParentContext(): ContextInterface;
}
