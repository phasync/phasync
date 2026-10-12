<?php

use phasync\Psr\BufferedStream;

phasync::run(function () {
    $stream = new BufferedStream();
    phasync::go(function () use ($stream) {
        $stream->append(str_repeat('x', 1000));
        phasync::sleep(0.02);
        $stream->append(str_repeat('y', 500));
        $stream->end();
    });

    var_dump($stream->getSize());       // the size once end() is called, not the size so far
});
