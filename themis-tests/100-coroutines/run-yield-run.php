<?php

use function phasync\{run, go, sleep};

require(__DIR__ . '/../../vendor/autoload.php');

run(function() {
    echo "Line 1a\n";
    go(function() {
        sleep(1);
        echo "Slept for 1 second\n";
    });
    go(function() {
        echo "A coroutine line 1a\n";
        sleep(0.1);
        echo "A coroutine line 2a\n";
        sleep(0.1);
        echo "A coroutine line 3a\n";
    });
    echo "Line 2a\n";
    echo run(function() {
        sleep(5);
        return "Inner run\n";
    });
    echo "Line 3a\n";
});