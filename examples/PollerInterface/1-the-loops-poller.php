<?php

use phasync\PollerInterface;

// phasync::readable() and phasync::writable() wait through the loop's poller
phasync::run(function () {
    $poller = phasync::getLoop()->getPoller();
    echo 'implements PollerInterface: ', var_export($poller instanceof PollerInterface, true), "\n";

    [$a, $b] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    stream_set_blocking($a, false);
    phasync::go(function () use ($b) {
        phasync::sleep(0.01);
        fwrite($b, 'ready');
    });

    $poller->readable($a, 1.0);   // parks until the loop's poll() finds $a readable
    echo fread($a, 100), "\n";
});
