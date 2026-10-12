<?php

phasync::run(function () {
    $fiber = phasync::go(function () {
        phasync::sleep(0.01);
        throw new DomainException('invalid order');
    });

    try {
        phasync::await($fiber);
    } catch (DomainException $e) {
        // awaiting it handled the failure: the run does not fail
        echo 'caught: ', $e->getMessage(), "\n";
    }
});
echo "run() returned normally\n";
