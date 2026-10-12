<?php

phasync::run(function () {
    [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    stream_set_blocking($client, false);

    phasync::go(function () use ($server) {
        foreach (['PING', 'PING', 'QUIT'] as $command) {
            phasync::sleep(0.01);
            fwrite($server, "$command\n");
        }
        fclose($server);
    });

    // Each wait lets the other coroutine run; fgets() after it does not block
    while (!feof($client)) {
        $line = fgets(phasync::readable($client));
        if (false !== $line) {
            echo 'received ', rtrim($line), "\n";
        }
    }
    echo "the peer closed the connection\n";
});
