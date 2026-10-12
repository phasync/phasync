<?php

use phasync\Psr\UnbufferedStream;

phasync::run(function () {
    $stream = new UnbufferedStream(bufferSize: 4);

    phasync::go(function () use ($stream) {
        foreach (['aaaa', 'bbbb', 'cccc'] as $chunk) {
            $stream->append($chunk);      // waits while more than 4 bytes are unread
            echo "appended $chunk\n";
        }
        $stream->end();
    });

    phasync::await(phasync::go(function () use ($stream) {
        while (!$stream->eof()) {
            $chunk = $stream->read(4);
            echo "  read $chunk\n";
        }
    }));
});
