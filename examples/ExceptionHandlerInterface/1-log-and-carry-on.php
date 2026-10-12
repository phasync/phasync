<?php

use phasync\Context\ExceptionHandlerInterface;

final class LoggingContext implements ExceptionHandlerInterface
{
    public function handleException(Throwable $exception): void
    {
        echo 'logged: ', $exception->getMessage(), "\n";   // runs in the loop: must not wait
    }
}

phasync::run(function () {
    phasync::go(function () {
        phasync::sleep(0.01);
        throw new RuntimeException('a background job failed');
    });
    phasync::go(function () {
        phasync::sleep(0.02);
        echo "the other coroutines run on\n";
    });
}, [], new LoggingContext());

echo "run() returned normally\n";
