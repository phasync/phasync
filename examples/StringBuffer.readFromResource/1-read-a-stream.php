<?php

use phasync\Util\StringBuffer;

phasync::run(function () {
    [$ours, $theirs] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $buffer = new StringBuffer();
    $buffer->readFromResource($ours);     // a coroutine fills the buffer from the socket

    fwrite($theirs, "line one\nline two\n");
    fclose($theirs);                      // the end of the stream ends the buffer

    $text = '';
    while (!$buffer->eof()) {
        $text .= $buffer->read(1024);
    }
    echo $text;
});
