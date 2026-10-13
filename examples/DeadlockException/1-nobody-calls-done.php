<?php

use phasync\DeadlockException;
use phasync\Util\WaitGroup;

try {
    phasync::run(function () {
        $done = new WaitGroup();
        $done->add();
        phasync::go(function () {
            phasync::sleep(0.01);   // forgets $done->done()
        });
        try {
            $done->await();
        } finally {
            echo "cleaned up\n";
        }
    });
} catch (DeadlockException $e) {
    echo $e->getMessage(), "\n";
}
