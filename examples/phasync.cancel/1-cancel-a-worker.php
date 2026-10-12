<?php

use phasync\CancelledException;

phasync::run(function () {
    $worker = phasync::go(function () {
        try {
            for ($i = 1; ; ++$i) {
                echo "tick $i\n";
                phasync::sleep(0.03);
            }
        } finally {
            echo "worker cleans up\n";
        }
    });

    phasync::sleep(0.075);
    phasync::cancel($worker, 'no longer needed');

    try {
        phasync::await($worker);
    } catch (CancelledException $e) {
        echo 'await(): ', $e->getMessage(), "\n";
    }
});
