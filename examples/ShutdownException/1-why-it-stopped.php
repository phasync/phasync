<?php

use phasync\CancelledException;
use phasync\ShutdownException;

phasync::run(function () {
    phasync::go(function () {
        try {
            phasync::sleep(10);
        } catch (ShutdownException $e) {
            echo "telling the client: {$e->getMessage()}\n";
        }
    });
    phasync::go(function () {
        try {
            phasync::sleep(10);
        } catch (CancelledException $e) {
            echo 'a CancelledException handler sees it too: ', $e::class, "\n";
        }
    });

    phasync::sleep(0.01);
    phasync::shutdown(1.0, new ShutdownException('server restarting'));
});
