<?php

phasync::run(function () {
    $job = null;
    phasync::go(function () use (&$job) {
        phasync::run(function () use (&$job) {   // the job's frame
            $job = phasync::frame();
            phasync::go(function () {             // in the job's frame too
                try {
                    phasync::sleep(1);
                } finally {
                    echo "child cancelled\n";
                }
            });
            try {
                phasync::sleep(1);
            } finally {
                echo "job cancelled\n";
            }
        });
    });

    phasync::sleep(0.01);
    $job->cancel();                               // every coroutine of the frame
});
echo "the job's coroutines have ended\n";
