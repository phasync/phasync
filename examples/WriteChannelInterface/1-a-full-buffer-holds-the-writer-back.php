<?php

phasync::run(function () {
    phasync::channel($read, $write, 2);

    phasync::go(function () use ($write) {
        foreach ([1, 2, 3, 4] as $n) {
            $write->write($n);
            echo "wrote $n\n";
        }
        $write->close();
    });

    phasync::go(function () use ($read) {
        foreach ($read as $n) {
            echo "read $n\n";
            phasync::sleep(0.01);   // a slow reader
        }
    });
});
