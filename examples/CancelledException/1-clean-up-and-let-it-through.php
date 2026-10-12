<?php

use phasync\CancelledException;

phasync::run(function () {
    $download = phasync::go(function () {
        $file = 'partial.tmp';
        try {
            phasync::sleep(10);
        } catch (CancelledException $e) {
            echo "cancelled ({$e->getMessage()}): removing $file\n";
            throw $e;   // let it end the coroutine: that is no failure of the run
        }
    });

    phasync::sleep(0.01);
    phasync::cancel($download, 'user pressed stop');
});
echo "run() returned normally\n";
