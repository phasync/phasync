<?php

phasync::run(function () {
    $jobs = new stdClass();

    phasync::go(function () {
        foreach (['thumbnail' => 0.02, 'email' => 0.01] as $job => $seconds) {
            phasync::go(function () use ($job, $seconds) {   // in the context $jobs too
                phasync::sleep($seconds);
                echo "$job done\n";
            });
        }
        echo "jobs started\n";
    }, [], $jobs);

    phasync::awaitContext($jobs);
    echo "every coroutine of the context has ended\n";
});
