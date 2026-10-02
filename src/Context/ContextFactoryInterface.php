<?php

namespace phasync\Context;

/**
 * Creates the context of a `phasync::withContext()` call when the closure first needs it.
 *
 * A server that gives each request a context of its own pays for it only when the request's code asks for it: `phasync::getContext()`, `getRootContext()`, `go()`, `finally()`, a nested `run()` or `withContext()` all do. A closure that never does runs in the calling coroutine's own context, and `createContext()` is never called.
 *
 * `createContext()` runs in the coroutine that asks, at most once per `withContext()` call.
 *
 * ```php
 * class RequestFactory implements phasync\Context\ContextFactoryInterface
 * {
 *     public function createContext(): object
 *     {
 *         return new stdClass();   // a new object: a context can be used once
 *     }
 * }
 *
 * phasync::run(function () {
 *     phasync::withContext(fn () => print("no context was created\n"), new RequestFactory());
 * });
 * ```
 *
 * @see phasync::withContext
 */
interface ContextFactoryInterface
{
    /**
     * Returns the context of the call.
     *
     * It must return an object that was not used as a context before, and should not suspend.
     *
     * @return object a new object
     */
    public function createContext(): object;
}
