<?php

use phasync\CancelledException;

phasync::run(function () {
    $fiber = phasync::go(function () {
        try {
            phasync::sleep(1);
        } catch (CancelledException) {
            echo "caught the cancellation, and waits again\n";
        }
        try {
            phasync::sleep(1);
        } catch (CancelledException) {
            echo "cancelled again at the next wait\n";
        }
    });

    phasync::sleep(0.01);
    phasync::cancel($fiber);
});
