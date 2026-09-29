<?php

namespace phasync\Context;

/**
 * A context told when it becomes live, and when another does: a server can keep its own
 * per-request state (global variables, static properties, locale) swapped in for the coroutines
 * of each request. The event loop calls resume() before a coroutine of this context runs when
 * another context ran last, and suspend() before a coroutine of another context runs. Switches
 * between coroutines of one context call neither.
 *
 * Both run in the event loop, outside of any coroutine: they must not suspend. An exception from
 * either ends phasync::run().
 */
interface SwitchAwareInterface
{
    public function resume(): void;

    public function suspend(): void;
}
