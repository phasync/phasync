<?php

namespace phasync\Context;

/**
 * A context that takes the failures of its coroutines that nobody awaited. Such a failure goes
 * to the nearest handler among its context and the contexts that one is nested in
 * (withContext(), go() with a context of its own); with none, it fails the nearest
 * phasync::run(), which drops its coroutines and throws it. With a handler, only the failed
 * coroutine ends. The handler runs in the event loop, outside of any coroutine: to handle
 * failures of a whole application, give the outermost run() a context with a handler.
 */
interface ExceptionHandlerInterface
{
    public function handleException(\Throwable $exception): void;
}
