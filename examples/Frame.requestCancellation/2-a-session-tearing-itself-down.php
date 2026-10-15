<?php

// A session with background workers: when the session itself decides to end (not a failure),
// requestCancellation() asks them to stop, and run() returns normally once they have -- unlike
// frame()->cancel(), which would make this run() throw CancelledException instead.
$result = phasync::run(function () {
    $frame = phasync::frame();
    phasync::go(function () use ($frame) {
        while (!$frame->isCancellationRequested()) {
            // one tick of background work
            phasync::sleep(0.01);
        }
        echo "worker stopped\n";
    });

    phasync::sleep(0.05);   // the session's own work
    $frame->requestCancellation();

    return 'session ended normally';
});

var_dump($result);   // 'session ended normally'
