<?php

use phasync\Util\Synchronized;

phasync::run(function () {
    foreach ([1, 2, 3] as $job) {
        phasync::go(function () use ($job) {
            Synchronized::run('report.csv', function () use ($job) {
                echo "job $job opens report.csv\n";
                phasync::sleep(0.01);       // other jobs wait instead of interleaving here
                echo "job $job closes report.csv\n";
            });
        });
    }
});
