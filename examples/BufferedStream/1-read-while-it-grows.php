<?php

use phasync\Psr\BufferedStream;

phasync::run(function () {
    $stream = new BufferedStream();

    phasync::go(function () use ($stream) {
        foreach (['alpha ', 'beta ', 'gamma'] as $chunk) {
            $stream->append($chunk);
            phasync::sleep(0.01);
        }
        $stream->end();
    });

    echo $stream->read(6), "|\n";        // waits for the first chunk
    echo $stream->read(5), "|\n";        // waits for the second
    $stream->seek(0);                    // seekable: nothing is discarded once read
    echo $stream->read(11), "|\n";
    echo 'size: ', $stream->getSize(), "\n";   // waits for end()
});
