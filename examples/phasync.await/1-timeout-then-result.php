<?php

use phasync\TimeoutException;

phasync::run(function () {
    $report = phasync::go(function () {
        phasync::sleep(0.05);

        return 'report ready';
    });

    try {
        phasync::await($report, 0.01);
    } catch (TimeoutException) {
        echo "not ready after 0.01 s, the coroutine keeps running\n";
    }
    echo phasync::await($report), "\n";
    echo phasync::await($report), " (awaited again: the same result)\n";
});
