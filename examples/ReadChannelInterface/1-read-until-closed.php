<?php

phasync::run(function () {
    phasync::channel($lines, $writer, 4);

    phasync::go(function () use ($writer) {
        foreach (['GET /', 'GET /about', 'POST /login'] as $line) {
            $writer->write($line);
        }
        $writer->close();
    });

    foreach ($lines as $line) {
        echo $line, "\n";
    }
    echo 'closed: ', var_export($lines->isClosed(), true), ', readable: ', var_export($lines->isReadable(), true), "\n";
});
