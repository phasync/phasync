<?php

use phasync\Util\StringBuffer;

phasync::run(function () {
    [$ours, $theirs] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $buffer = new StringBuffer();
    $writer = $buffer->writeToResource($ours);   // a coroutine drains the buffer into the socket

    $buffer->write("header\n");
    $buffer->write("body\n");
    $buffer->end();

    echo phasync::await($writer), " bytes written\n";
    fclose($ours);
    echo stream_get_contents($theirs);
});
