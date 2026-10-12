<?php

use function phasync\sleep;

// With this import, sleep() in this file waits without blocking the other coroutines
phasync::run(function () {
    phasync::go(function () {
        sleep(0.02);
        echo "second\n";
    });
    phasync::go(function () {
        sleep(0.01);   // a float, unlike the native sleep()
        echo "first\n";
    });
});
