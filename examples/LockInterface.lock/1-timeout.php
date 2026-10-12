<?php

use phasync\TimeoutException;
use phasync\Util\LockInterface;
use phasync\Util\LockTrait;

$printer = new class implements LockInterface {
    use LockTrait;
};

phasync::run(function () use ($printer) {
    phasync::go(fn () => $printer->lock(function () {
        echo "long job holds the printer\n";
        phasync::sleep(0.2);
    }));

    try {
        $printer->lock(fn () => print("never printed\n"), timeout: 0.05);
    } catch (TimeoutException) {
        echo "gave up after 0.05 s\n";
    }

    echo $printer->lock(fn () => "got the printer after the long job\n");
});
