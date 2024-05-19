<?php

use phasync\Legacy\Channel\Channel;
use function phasync\{run, go, sleep};

require __DIR__ . '/../vendor/autoload.php';

/**
 * This example demonstrates the use of Channel::select()
 * to select the first channel where reading will not block.
 * 
 * In this demonstration, the $writer2 will be garbage collected
 * and therefore $reader2 will not block.
 */
run(function() {

    [$reader1, $writer1] = Channel::create(2);
    [$reader2, $writer2] = Channel::create(0);

    // Producer Coroutine
    go(function() use ($reader1, $reader2) {
        switch (Channel::select($reader1, $reader2)) { // May block if no readers are readable
            case $reader1: // Reader1
                echo "Selected reader 1\n";
                var_dump($reader1->read());; // will not block, because $reader1 is readable
                break;
            case $reader2: // Reader2
                echo "Selected reader 2\n";
                var_dump($reader2->read());; // will not block, because $reader2 is readable
                break;
        }
    });

    go(function() use ($writer1) {
        sleep(.5);
        $writer1->write("Hello");
    });
});

echo "Completed\n";