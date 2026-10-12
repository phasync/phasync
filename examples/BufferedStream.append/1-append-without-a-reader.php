<?php

use phasync\Psr\BufferedStream;

phasync::run(function () {
    $stream = new BufferedStream();

    // append() never waits for a reader: the content is kept until the stream is dropped
    foreach (['one ', 'two ', 'three'] as $chunk) {
        $stream->append($chunk);
    }
    echo $stream->read(100), "\n";
    $stream->rewind();
    echo $stream->read(3), "\n";        // read again from the start
    $stream->end();

    try {
        $stream->append('four');
    } catch (LogicException $e) {
        echo get_class($e), "\n";
    }
});
