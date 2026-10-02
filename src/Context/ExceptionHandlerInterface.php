<?php

namespace phasync\Context;

/**
 * A context that takes the failures of its coroutines that nobody awaited.
 *
 * Such a failure goes to the nearest handler among the context and the contexts it is nested in (`withContext()`, or `go()` with a context of its own). With none, it fails the nearest `run()`: the rest of its coroutines are cancelled and the failure is thrown. With a handler, only the failed coroutine ends. The handler runs in the event loop, outside of any coroutine, so it must not wait. To handle the failures of a whole application, give the outermost `run()` a context with a handler.
 *
 * ```php
 * $handler = new class implements phasync\Context\ExceptionHandlerInterface {
 *     public function handleException(Throwable $exception): void
 *     {
 *         echo "logged: ", $exception->getMessage(), "\n";
 *     }
 * };
 *
 * phasync::run(function () {
 *     phasync::go(function () { throw new RuntimeException('lost'); });
 *     phasync::sleep(0.05);
 *     echo "run goes on\n";
 * }, [], $handler);
 * ```
 *
 * @see phasync::run
 * @see phasync\AggregateException
 */
interface ExceptionHandlerInterface
{
    /**
     * Takes the failure of a coroutine of this context.
     *
     * @param \Throwable $exception what the coroutine threw
     */
    public function handleException(\Throwable $exception): void;
}
