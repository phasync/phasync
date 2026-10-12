<?php

use phasync\RethrowExceptionInterface;
use phasync\TimeoutException;

phasync::run(function () {
    $slow = phasync::go(fn () => phasync::sleep(1));
    try {
        phasync::await($slow, 0.01);
    } catch (TimeoutException $e) {
        var_dump($e instanceof RethrowExceptionInterface);
        echo 'thrown in ', basename($e->getFile()), "\n";   // inside the event loop
        $e->rebuildStackTrace();
        echo 'after rebuildStackTrace(): ', basename($e->getFile()), ':', $e->getLine(), "\n";
    }
    phasync::cancel($slow);
});
