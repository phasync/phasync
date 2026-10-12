<?php

use phasync\Util\Pool;

phasync::run(function () {
    $pool = new Pool(fn () => new ArrayObject(), size: 1);

    try {
        $pool->use(function (ArrayObject $conn) {
            throw new RuntimeException('query failed');
        });
    } catch (RuntimeException $e) {
        echo 'caught: ', $e->getMessage(), "\n";
    }

    // The only instance was released despite the exception, so this does not wait
    echo $pool->use(fn (ArrayObject $conn) => "borrowed again\n");
});
