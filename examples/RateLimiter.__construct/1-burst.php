<?php

use phasync\Util\RateLimiter;

phasync::run(function () {
    $limiter = new RateLimiter(10, burst: 3);
    phasync::sleep(0.5);                // nobody waits: permits accumulate, up to the burst

    $start  = microtime(true);
    $passed = 0;
    while ($limiter->isReady()) {       // the burst passes at once
        $limiter->wait();
        ++$passed;
    }
    printf("%d passed at once\n", $passed);
    printf("without waiting: %s\n", microtime(true) - $start < 0.05 ? 'yes' : 'no');
});
