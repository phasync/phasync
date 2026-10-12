<?php

use phasync\Psr\Response;
use phasync\Psr\UnbufferedStream;

phasync::run(function () {
    $body = new UnbufferedStream();
    phasync::go(function () use ($body) {
        foreach (['one', 'two', 'three'] as $line) {
            $body->append("$line\n");
            phasync::sleep(0.01);         // produced over time, say rows from a database
        }
        $body->end();
    });
    $response = new Response(200, ['Content-Type' => 'text/plain'], $body);

    // What a server does with the body: read until the end, from another coroutine
    phasync::await(phasync::go(function () use ($response) {
        $stream = $response->getBody();
        while (!$stream->eof()) {
            echo 'sent: ', json_encode($stream->read(1024)), "\n";
        }
        var_dump($stream->getSize(), $stream->isSeekable());
    }));
});
