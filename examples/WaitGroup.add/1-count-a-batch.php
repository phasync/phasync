<?php

use phasync\Util\WaitGroup;

phasync::run(function () {
    $jobs  = ['resize', 'upload', 'notify'];
    $group = new WaitGroup();
    $group->add(count($jobs));   // the whole batch at once, before any job can finish

    foreach ($jobs as $i => $job) {
        phasync::go(function () use ($group, $job, $i) {
            phasync::sleep(0.01 * (3 - $i));
            echo "$job done\n";
            $group->done();
        });
    }

    $group->await();
    echo "batch done\n";

    try {
        $group->done();          // one done() too many
    } catch (LogicException $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
});
