<?php

phasync::run(function () {
    $worker = phasync::go(function () {
        echo "lock taken\n";
        phasync::finally(function () {
            phasync::sleep(0.01);   // cleanup may wait, even after a cancellation
            echo "lock released\n";
        });
        phasync::finally(fn () => print("temporary file deleted\n"));   // runs first

        phasync::sleep(1);
    });

    phasync::sleep(0.01);
    phasync::cancel($worker);
});
