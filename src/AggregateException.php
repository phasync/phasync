<?php

namespace phasync;

/**
 * Several coroutines failed in one `run()`, and none of the failures is more important than the others.
 *
 * `run()` throws this when more than one coroutine failed before it could cancel the rest. The message and `getPrevious()` are those of the first failure; `getExceptions()` has all of them, in the order they happened. With one failure, `run()` throws that exception itself.
 *
 * ```php
 * try {
 *     phasync::run(function () {
 *         phasync::go(function () { phasync::sleep(0.01); throw new RuntimeException('first'); });
 *         phasync::go(function () { phasync::sleep(0.01); throw new LogicException('second'); });
 *         phasync::sleep(1);
 *     });
 * } catch (phasync\AggregateException $e) {
 *     foreach ($e->getExceptions() as $failure) {
 *         echo get_class($failure), ': ', $failure->getMessage(), "\n";
 *     }
 * }
 * ```
 *
 * @see phasync::run
 */
final class AggregateException extends \RuntimeException
{
    /**
     * @param non-empty-list<\Throwable> $exceptions the failures, in the order they happened
     */
    public function __construct(private readonly array $exceptions)
    {
        parent::__construct(\count($exceptions) . ' failures, the first: ' . $exceptions[0]->getMessage(), 0, $exceptions[0]);
    }

    /**
     * Returns every failure, in the order they happened.
     *
     * @return non-empty-list<\Throwable> the failures; the first is also `getPrevious()`
     */
    public function getExceptions(): array
    {
        return $this->exceptions;
    }
}
