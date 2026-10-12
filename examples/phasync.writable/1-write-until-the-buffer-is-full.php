<?php

phasync::run(function () {
    [$a, $b] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    stream_set_blocking($a, false);
    stream_set_blocking($b, false);
    $data = str_repeat('x', 1_000_000);   // more than the socket buffer holds

    phasync::go(function () use ($b, $data) {
        $received = '';
        while (strlen($received) < strlen($data)) {
            $received .= fread(phasync::readable($b), 65536);
        }
        echo 'reader received ', strlen($received), " bytes\n";
    });

    $offset = 0;
    $writes = 0;
    while ($offset < strlen($data)) {
        // fwrite() takes what fits; writable() waits until the reader made room
        $offset += fwrite(phasync::writable($a), substr($data, $offset));
        ++$writes;
    }
    echo 'writer sent ', $offset, ' bytes in ', $writes > 1 ? 'several' : 'one', " writes\n";
});
