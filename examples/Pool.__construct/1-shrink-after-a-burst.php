<?php

use phasync\Util\Pool;

phasync::run(function () {
    $pool = new Pool(
        create: fn () => new stdClass(),
        size: 10,
        window: 0.1,                        // keep what a burst needed for 0.1 s
        dispose: function () {
            echo "an idle instance is dropped\n";
        },
    );

    // A burst: five borrowers at once
    $out = [];
    for ($i = 0; $i < 5; ++$i) {
        $out[] = $pool->borrow();
    }
    foreach ($out as $instance) {
        $pool->release($instance);
    }
    echo count($pool), " instances after the burst\n";

    phasync::sleep(0.15);                   // the window passes
    $pool->use(fn () => null);              // shrinking happens when the pool is used
    echo count($pool), " instance left\n";
});
