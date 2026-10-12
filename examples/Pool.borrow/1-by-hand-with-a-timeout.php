<?php

use phasync\TimeoutException;
use phasync\Util\Pool;

phasync::run(function () {
    $pool  = new Pool(fn () => new stdClass(), size: 1);
    $first = $pool->borrow();

    try {
        $pool->borrow(timeout: 0.05);     // the only instance is out
    } catch (TimeoutException) {
        echo "none free within 0.05 s\n";
    }

    phasync::go(function () use ($pool, $first) {
        phasync::sleep(0.05);
        $pool->release($first);
    });
    $second = $pool->borrow();            // waits for the release above
    echo $second === $first ? "got the released instance\n" : "got a new instance\n";
    $pool->release($second);
});
