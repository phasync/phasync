<?php

namespace phasync;

/**
 * Several failures reached one phasync::run(): the first is the primary (the message and the
 * previous exception), all of them are in getExceptions(), in the order they happened.
 */
final class AggregateException extends \RuntimeException
{
    /** @param non-empty-list<\Throwable> $exceptions */
    public function __construct(private readonly array $exceptions)
    {
        parent::__construct(\count($exceptions) . ' failures, the first: ' . $exceptions[0]->getMessage(), 0, $exceptions[0]);
    }

    /** @return non-empty-list<\Throwable> */
    public function getExceptions(): array
    {
        return $this->exceptions;
    }
}
