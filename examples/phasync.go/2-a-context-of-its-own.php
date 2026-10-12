<?php

phasync::run(function () {
    $job = new stdClass();

    phasync::go(function () {
        phasync::go(function () {           // inherits the context of its parent
            try {
                phasync::sleep(1);
            } finally {
                echo "grandchild cancelled\n";
            }
        });
        try {
            phasync::sleep(1);
        } finally {
            echo "child cancelled\n";
        }
    }, [], $job);

    phasync::sleep(0.01);
    phasync::cancel($job);                  // cancels every coroutine of the context
    phasync::awaitContext($job);
    echo "the job's coroutines have ended\n";
});
