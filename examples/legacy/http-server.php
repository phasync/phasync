<?php

use phasync\Server\HttpServer;

require "../vendor/autoload.php";

use function phasync\{run, sleep, go, file_put_contents, await};

/**
 * This script sets up a TcpServer that listens on port 8080.
 * It echoes back any received data to the client and then closes the connection.
 */
run(function() {
    // Create the TCP Server instance
    $server = new HttpServer('0.0.0.0', 8080);

    go(function() {
        while (true) {
            echo "Tick\n";
            sleep(1);
            file_put_contents(__DIR__ . '/last-tick.txt', \gmdate('Y-m-d H:i:s') . "\n");
        }
    });

    $future = go(function() {
        sleep(10);
        return "Done";
    });

    // Starts listening to requests asynchronously
    // and each request is handled in a separate fiber.
    $server->run(function($server, $connection) {

        // $server contains the same values as $_SERVER
        // would contain in a normal PHP application

        // Todo: Logging

        // Parse the query string, if any
        $get = null;
        if (!empty($_SERVER['QUERY_STRING'])) {
            \parse_str($server['QUERY_STRING'], $get);
        }
        
        if ($server['REQUEST_METHOD'] == 'GET') {

            // The phasync namespace contains a set of utility
            // functions like `sleep`, `fread`, `fwrite`, 
            // `file_get_contents`, `file_put_contents` which 
            // are all transparently causing a context switch
            // to allow other requests to be accepted
            phasync\sleep(1);

            // Writing to the connection is also an IO operation,
            // which will cause context switching and allows
            // you to handle other requests concurrently.
            $connection->write(
                "HTTP/1.1 200 Ok\r\n" .
                "Content-Type: text/plain; charset=utf-8\r\n" .
                "Connection: close\r\n" .
                "\r\n" .
                "Hello World"
            );
        } else {
            $connection->write(
                "HTTP/1.1 400 Bad Request\r\n" .
                "Content-Type: text/plain; charset=utf-8\r\n" .
                "Connection: close\r\n" .
                "\r\n" .
                "The request was not permitted"
            );
        }
    });

    echo "Server is running on tcp://0.0.0.0:8080\n";

    echo "Terminating: " . await($future) . "\n";
});
