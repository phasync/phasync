<?php

phasync::run(function () {
    phasync::go(function () {
        for ($batch = 1; $batch <= 3; ++$batch) {
            echo "batch $batch\n";
            phasync::yield();
        }
    });

    phasync::idle();   // returns once nothing else is runnable
    echo "idle: housekeeping can run now\n";
});
