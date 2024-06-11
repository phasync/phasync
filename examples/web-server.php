<?php

require '../vendor/autoload.php';

// Set up the socket server on port 8080
$ctx = \stream_context_create([
    'socket' => [
        'backlog'      => 511,  // Configure the kernel backlog size
        'so_reuseport' => true,  // Allow reconnection to a recently closed port
    ],
]);

$server = \stream_socket_server('tcp://0.0.0.0:8080', $errorCode, $errorMessage, \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN, $ctx);

phasync::run(function () use ($server) {
    echo "Server is accepting connections...\n";

    while ($client = \stream_socket_accept(phasync::readable($server), 3600, $peerName)) {
        // At this point, a connection from a client (browser) that connected to http://localhost:8080/ on your computer has been accepted.
        // Launch a coroutine to handle the connection:
        phasync::go(handle_connection(...), args: [$client, $peerName]);
    }
});

function handle_connection($client, string $peerName): void
{
    // Read a large chunk of data from the client
    // stream_set_blocking($client, false);
    $buffer = \fread(phasync::readable($client), 65536);
    if (false === $buffer) {
        echo "$peerName: Socket invalid when accepting client\n";
        \fclose($client);

        return;
    } elseif ('' === $buffer) {
        echo "$peerName: Unable to read request data\n";
        \fclose($client);

        return;
    }

    // Split the request into headers and body (if any)
    $parts = \explode("\r\n\r\n", $buffer, 2);
    $head  = $parts[0];
    $body  = $parts[1] ?? '';

    // Split the head into individual lines
    $headerLines = \explode("\r\n", $head);

    // Example response preparation and sending
    $response   = "HTTP/1.1 200 OK\r\n"
                . "Connection: close\r\n"
                . "Content-Type: text/html\r\n"
                . 'Date: ' . \gmdate('r') . "\r\n"
                . "\r\n"
                . '<html><body>Hello, World!</body></html>';

    \fwrite(phasync::writable($client), $response);

    \fclose($client);
}
