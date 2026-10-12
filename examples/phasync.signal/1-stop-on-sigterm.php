<?php

phasync::run(function () {
    $server = phasync::go(function () {
        try {
            while (true) {
                phasync::sleep(0.01);   // serving requests
            }
        } finally {
            echo "server stopped accepting\n";
        }
    });

    phasync::go(function () {
        phasync::sleep(0.02);
        posix_kill(getmypid(), SIGTERM);   // what `kill <pid>` does
    });

    $signal = phasync::signal([SIGTERM, SIGINT]);
    echo 'received ', SIGTERM === $signal ? 'SIGTERM' : 'SIGINT', "\n";
    phasync::cancel($server);
});
