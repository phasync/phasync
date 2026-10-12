<?php

phasync::run(function () {
    $connection = phasync::go(function () {
        try {
            phasync::sleep(10);
        } finally {
            $sent = phasync::shielded(function () {
                phasync::sleep(0.01);   // not cut short, though the coroutine is cancelled
                return 'goodbye frame';
            });
            echo "sent the $sent\n";
        }
    });

    phasync::sleep(0.01);
    phasync::cancel($connection);
});
