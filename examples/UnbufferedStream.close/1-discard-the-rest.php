<?php

use phasync\Psr\UnbufferedStream;

phasync::run(function () {
    $stream = new UnbufferedStream(bufferSize: 4);
    $writer = phasync::go(function () use ($stream) {
        for ($i = 1; $i <= 5; ++$i) {
            $stream->append("part $i\n");
        }
        $stream->end();
        echo "the writer finished\n";     // close() reads on, so append() does not wait forever
    });

    phasync::await(phasync::go(function () use ($stream) {
        echo $stream->read(7);
        $stream->close();                 // not interested in the rest
        echo "closed\n";
    }));
    phasync::await($writer);
});
