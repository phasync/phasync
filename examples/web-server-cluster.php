<?php

require '../vendor/autoload.php';

// Set up the socket server on port 8080
$ctx = \stream_context_create([
    'socket' => [
        'backlog'      => 511,  // Configure the kernel backlog size
        'so_reuseport' => true,  // Allow reconnection to a recently closed port
    ],
]);

$pids = [];
for ($i = 0; $i < 4; ++$i) {
    $pid = \pcntl_fork();
    if (-1 === $pid) {
        exit('Could not fork');
    } elseif ($pid) {
        $pids[] = $pid;
    } else {
        // In child process
        break;
    }
}

$server = \stream_socket_server('tcp://0.0.0.0:8080', $errorCode, $errorMessage, \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN, $ctx);

if ($pid) {
    // In parent process
    foreach ($pids as $child) {
        \pcntl_waitpid($child, $status);
    }
    exit("Closed children\n");
}

phasync::run(function () use ($server) {
    echo "Server is accepting connections...\n";

    while ($client = \stream_socket_accept(phasync::readable($server), 3600, $peerName)) {
        // Launch a coroutine to handle the connection:
        phasync::go(handle_connection(...), args: [$client, $peerName]);
    }
});

function handle_connection($client, string $peerName): void
{
    \stream_set_blocking($client, false);

    while (true) {
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

        // Check for Connection: keep-alive header
        $keepAlive = false;
        foreach ($headerLines as $line) {
            if (false !== \stripos($line, 'Connection: keep-alive')) {
                $keepAlive = true;
                break;
            }
        }

        // Example response preparation
        $response = "HTTP/1.1 200 OK\r\n"
                  . "Content-Type: text/html\r\n"
                  . 'Date: ' . \gmdate('r') . "\r\n";

        if ($keepAlive) {
            $response .= "Connection: keep-alive\r\n";
        // . "Keep-Alive: timeout=5, max=100\r\n";
        } else {
            $response .= "Connection: close\r\n";
        }

        $response .= "\r\n"
                  . '<html><body>Hello, World!</body></html>';

        \fwrite(phasync::writable($client), $response);

        if (!$keepAlive) {
            \fclose($client);

            return;
        }
    }
}
