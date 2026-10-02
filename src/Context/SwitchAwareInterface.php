<?php

namespace phasync\Context;

/**
 * A context that is told when it becomes live and when another does, to swap per-request state in and out.
 *
 * A server can keep global variables, static properties or the locale of a request swapped in for the coroutines of that request. The event loop calls `resume()` before a coroutine of this context runs when another context ran last, and `suspend()` before a coroutine of another context runs. Switches between coroutines of one context call neither. Both run in the event loop, outside of any coroutine: they must not wait, and an exception from either ends `phasync::run()`.
 *
 * ```php
 * final class Current
 * {
 *     public static ?string $user = null;
 * }
 *
 * final class RequestScope implements phasync\Context\SwitchAwareInterface
 * {
 *     public function __construct(public readonly string $user) {}
 *
 *     public function resume(): void  { Current::$user = $this->user; }
 *     public function suspend(): void { Current::$user = null; }
 * }
 *
 * phasync::run(function () {
 *     foreach (['ann', 'bob'] as $user) {
 *         phasync::go(function () {
 *             phasync::sleep(0.01);
 *             echo Current::$user, "\n";   // the user of this request, also after waiting
 *         }, [], new RequestScope($user));
 *     }
 * });
 * ```
 *
 * @see phasync::withContext
 */
interface SwitchAwareInterface
{
    /**
     * Called when a coroutine of this context is about to run after another context ran.
     */
    public function resume(): void;

    /**
     * Called when a coroutine of another context is about to run after this one.
     */
    public function suspend(): void;
}
