<?php

use phasync\TimeoutException;
use phasync\Util\WaitGroup;

phasync::run(function () {
    $group = new WaitGroup();
    $group->add();
    phasync::go(function () use ($group) {
        phasync::sleep(0.2);
        $group->done();
    });

    try {
        $group->await(0.05);
    } catch (TimeoutException) {
        echo "not done within 0.05 s\n";
    }

    $group->await();             // no limit
    echo "done\n";

    $group->await();             // the counter is zero: returns at once
    echo "still done\n";
});
