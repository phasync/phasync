<?php

use phasync\AggregateException;

// Two coroutines fail at about the same moment: run() throws both, not whichever came first.
try {
    phasync::run(function () {
        phasync::go(function () {
            phasync::sleep(0.01);
            throw new RuntimeException('cache unavailable');
        });
        phasync::go(function () {
            phasync::sleep(0.01);
            throw new LogicException('bad state');
        });
    });
} catch (AggregateException $e) {
    foreach ($e->getExceptions() as $failure) {
        echo '- ', $failure::class, ': ', $failure->getMessage(), "\n";
    }
    if ($e->contains(LogicException::class)) {
        echo "one of them is a bug\n";
    }
}
