<?php

// A session's state, kept between the requests that each run in a context of their own
$session = ['visits' => 0];

phasync::run(function () use (&$session) {
    for ($request = 1; $request <= 3; ++$request) {
        phasync::withContext(function () use (&$session) {
            phasync::adoptContextState($session);
            phasync::sleep(0.001);
            ++phasync::$contextState['visits'];
        }, new stdClass());
    }
});

echo "visits: {$session['visits']}\n";
