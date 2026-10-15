<?php

// A poll that usually finds nothing ready: throw: false avoids building a TimeoutException on
// every miss, which matters when the wait is on a hot path and almost always times out.
phasync::run(function () {
    $ready = new stdClass();

    $raised = phasync::awaitFlag($ready, 0.01, throw: false);
    var_dump($raised);   // false, no exception thrown or constructed

    phasync::go(function () use ($ready) {
        phasync::raiseFlag($ready);
    });
    $raised = phasync::awaitFlag($ready, 1.0, throw: false);
    var_dump($raised);   // true
});
