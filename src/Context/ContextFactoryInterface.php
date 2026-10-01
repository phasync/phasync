<?php

namespace phasync\Context;

/**
 * Creates the context of a {@see \phasync::withContext()} call when the closure first needs it.
 *
 * A server that gives each request a context of its own pays for it only when the request's code
 * asks for it: phasync::getContext(), phasync::getRootContext(), phasync::go(), phasync::finally(),
 * a nested phasync::run() or withContext() all do. A closure that never does runs in the calling
 * coroutine's own context, and createContext() is never called.
 *
 * createContext() runs in the coroutine that asks, at most once per withContext() call, and
 * must return a context that was not used before (a new object). It should not suspend.
 */
interface ContextFactoryInterface
{
    public function createContext(): object;
}
