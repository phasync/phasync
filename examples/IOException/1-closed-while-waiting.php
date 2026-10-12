<?php

use phasync\IOException;

phasync::run(function () {
    try {
        phasync::readable('not a stream');
    } catch (IOException $e) {
        echo $e->getMessage(), "\n";
    }

    [$a, $b] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    stream_set_blocking($a, false);
    phasync::go(function () use ($a) {
        phasync::sleep(0.01);
        fclose($a);   // another coroutine closes the stream we wait for
    });

    try {
        phasync::readable($a);
    } catch (IOException $e) {
        echo $e->getMessage(), "\n";
    }
});
