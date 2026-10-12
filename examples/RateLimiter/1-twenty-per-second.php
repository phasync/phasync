<?php

use phasync\Util\RateLimiter;

phasync::run(function () {
    $limiter = new RateLimiter(20);   // one permit every 0.05 s
    $start   = microtime(true);

    for ($i = 1; $i <= 5; ++$i) {
        $limiter->wait();
        echo "request $i\n";
    }

    // The first permit is immediate, the other four come 0.05 s apart
    printf("took at least 0.2 s: %s\n", microtime(true) - $start >= 0.19 ? 'yes' : 'no');
});
