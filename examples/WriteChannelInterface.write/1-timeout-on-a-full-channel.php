<?php

use phasync\TimeoutException;

phasync::run(function () {
    phasync::channel($read, $write, 1);

    phasync::go(function () use ($write) {
        $write->write('fits in the buffer');
        try {
            $write->write('no room', 0.01);
        } catch (TimeoutException) {
            echo "the buffer stayed full: gave up\n";
        }
        $write->close();
    });

    phasync::sleep(0.02);
    foreach ($read as $value) {
        echo "read: $value\n";
    }
});
