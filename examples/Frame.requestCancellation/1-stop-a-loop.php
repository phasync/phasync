<?php

phasync::run(function () {
    $frame = phasync::frame();
    phasync::go(function () use ($frame) {
        while (!$frame->isCancellationRequested()) {
            // one step of work
            phasync::sleep(0.01);
        }
        echo "stopped after its tick, no exception\n";
    });

    phasync::sleep(0.05);
    $frame->requestCancellation();        // returns at once; the loop sees it on its next check
    echo "requested\n";
});
