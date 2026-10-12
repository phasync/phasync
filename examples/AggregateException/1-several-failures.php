<?php

use phasync\AggregateException;

try {
    phasync::run(function () {
        phasync::go(function () {
            phasync::sleep(0.01);
            throw new RuntimeException('disk full');
        });
        phasync::go(function () {
            phasync::sleep(0.01);
            throw new LogicException('bad state');
        });
        phasync::sleep(1);
    });
} catch (AggregateException $e) {
    echo $e->getMessage(), "\n";
    foreach ($e->getExceptions() as $failure) {
        echo '- ', $failure::class, ': ', $failure->getMessage(), "\n";
    }
}
