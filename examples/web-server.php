<?php
require __DIR__ . '/../vendor/autoload.php';

phasync::run(function () {
    $socket = stream_socket_server('tcp://0.0.0.0:8080', $errno, $errstr);
    if (!$socket) {
        die("Could not create socket: $errstr ($errno)");
    }

    while (true) {        
        phasync::readable($socket);     // Wait for activity on the server socket, while allowing coroutines to run
        if (!($client = stream_socket_accept($socket, 0))) {
            break;
        }
        
        phasync::go(function () use ($client) {
            phasync::sleep();           // this single sleep allows the server to accept slightly more connections before reading and writing
            phasync::readable($client); // pause coroutine until resource is readable
            $request = \fread($client, 32768);
            phasync::writable($client); // pause coroutine until resource is writable
            $written = fwrite($client,
                "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: 13\r\n\r\n".
                "Hello, world!"
            );
            fclose($client);
        });
    }
});
