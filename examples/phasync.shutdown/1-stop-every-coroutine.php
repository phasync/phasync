<?php

use phasync\ShutdownException;

phasync::run(function () {
    foreach (['queue consumer', 'cache warmer'] as $name) {
        phasync::go(function () use ($name) {
            try {
                phasync::sleep(10);
            } catch (ShutdownException) {
                echo "$name stops\n";
            }
        });
    }
    phasync::go(function () {
        phasync::finally(function () {
            phasync::sleep(0.01);   // cleanup that waits gets the window to finish
            echo "connections flushed\n";
        });
        phasync::sleep(10);
    });

    phasync::sleep(0.01);
    $left = phasync::shutdown(1.0);
    echo "still running after the window: $left\n";
});
