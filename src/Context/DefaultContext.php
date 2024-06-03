<?php

namespace phasync\Context;

/**
 * All coroutines are associated with an instance of ContextInterface.
 * If no ContextInterface implementation is provided when using run to
 * launch a coroutine, the coroutine will inherit the parent coroutines'
 * context. The root coroutine will use an instance of this class.
 */
final class DefaultContext implements ContextInterface
{
    use ContextTrait;
}
