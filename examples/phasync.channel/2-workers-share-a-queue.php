<?php

// Each value goes to exactly one reader: three workers share the jobs
phasync::run(function () {
    phasync::channel($jobs, $submit);
    $done = [];

    foreach (['w1', 'w2', 'w3'] as $worker) {
        phasync::go(function () use ($jobs, $worker, &$done) {
            foreach ($jobs as $job) {
                phasync::sleep(0.01);
                $done[$worker][] = $job;
            }
        });
    }

    foreach (range(1, 9) as $job) {
        $submit->write($job);   // unbuffered: returns when a worker has taken it
    }
    $submit->close();
    phasync::sleep(0.05);

    ksort($done);
    foreach ($done as $worker => $handled) {
        echo "$worker handled ", count($handled), " jobs\n";
    }
});
