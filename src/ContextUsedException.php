<?php

namespace phasync;

/**
 * A context object was used twice: it was given to `run()`, `go()` or `withContext()` before.
 *
 * A context stands for one scope and cannot be entered again, even after that scope has ended. Create a new object each time.
 *
 * ```php
 * $context = new stdClass();
 * phasync::run(fn () => 1, [], $context);
 *
 * try {
 *     phasync::run(fn () => 2, [], $context);
 * } catch (phasync\ContextUsedException $e) {
 *     echo $e->getMessage(), "\n";   // Can't use a context multiple times
 * }
 * ```
 *
 * @see phasync::run
 * @see phasync::withContext
 */
class ContextUsedException extends \RuntimeException
{
    /**
     * Creates the exception with its fixed message.
     */
    public function __construct()
    {
        parent::__construct("Can't use a context multiple times");
    }
}
