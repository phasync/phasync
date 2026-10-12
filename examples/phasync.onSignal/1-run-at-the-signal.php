<?php

phasync::run(function () {
    phasync::onSignal(SIGUSR1, function (int $signo) {
        // Runs inside the signal handler: no waiting here
        echo "handler: signal $signo arrived\n";
    });

    phasync::go(function () {
        phasync::signal(SIGUSR1);   // a coroutine is woken for the same signal
        echo "coroutine: woken by SIGUSR1\n";
    });

    phasync::sleep(0.01);
    posix_kill(getmypid(), SIGUSR1);
    phasync::sleep(0.01);
});
