<?php

include '../vendor/autoload.php';

use function phasync\go;
use function phasync\run;
use function phasync\sleep;

// Outer run() call
run(function () {
    // Coroutine 1
    go(function () {
        echo "Coroutine 1 started\n";
        // Coroutine 1 waits for 2 seconds
        sleep(2);
        echo "Coroutine 1 finished\n";
    });

    // Coroutine 2
    go(function () {
        echo "Coroutine 2 started\n";
        // Inner run() call
        run(function () {
            // Coroutine 3
            go(function () {
                echo "Coroutine 3 started\n";
                // Coroutine 3 waits for 1 second
                sleep(1);
                echo "Coroutine 3 finished\n";
            });
        });
        echo "Coroutine 2 finished\n";
    });
});

echo "All coroutines completed\n";
