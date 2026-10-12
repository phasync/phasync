<?php

use phasync\Util\WaitGroup;

phasync::run(function () {
    $group = new WaitGroup();

    foreach (['small.txt' => 0.03, 'large.iso' => 0.09, 'medium.zip' => 0.06] as $file => $seconds) {
        $group->add();
        phasync::go(function () use ($group, $file, $seconds) {
            try {
                phasync::sleep($seconds);   // stands in for the download
                echo "downloaded $file\n";
            } finally {
                $group->done();             // also when the download fails
            }
        });
    }

    $group->await();
    echo "all three done\n";
});
