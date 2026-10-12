<?php

use phasync\SelectableInterface;
use phasync\Util\WaitGroup;

phasync::run(function () {
    $group = new WaitGroup();
    $group->add();
    phasync::channel($read, $write, 1);

    phasync::go(function () use ($group, $write) {
        phasync::sleep(0.01);
        $write->write('data');
        $group->done();
    });

    /** @var SelectableInterface $selectable */
    foreach (['channel' => $read, 'wait group' => $group] as $name => $selectable) {
        echo "$name ready before: ", var_export($selectable->isReady(), true), "\n";
        phasync::await($selectable);
        echo "$name ready after: ", var_export($selectable->isReady(), true), "\n";
    }
});
