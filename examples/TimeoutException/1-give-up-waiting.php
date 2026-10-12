<?php

use phasync\TimeoutException;

phasync::run(function () {
    phasync::channel($replies, $reply);

    phasync::go(function () use ($replies) {
        try {
            $replies->read(timeout: 0.01);
        } catch (TimeoutException) {
            echo "no reply in time\n";
        }
        // The channel still works: the timeout ended only that wait
        echo $replies->read(), "\n";
    });

    phasync::sleep(0.02);
    $reply->write('late reply');
});
