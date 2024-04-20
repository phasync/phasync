<?php
require("../vendor/autoload.php");

use phasync\Server\TcpConnection;
use phasync\Server\TcpServer;
use phasync\Server\TcpServerOptions;
use phasync\Server\TcpConnectionOptions;
use function phasync\{run, fread, fwrite};

/**
 * This script sets up a TcpServer that listens on port 8080.
 * It echoes back any received data to the client and then closes the connection.
 */
run(function() {
    // Create the TCP Server instance
    $tcpServer = new TcpServer('0.0.0.0', 8080);

    // Define the connection handling logic
    $tcpServer->run(function(TcpConnection $connection) {

        while (!$connection->isClosed()) {
            $chunk = $connection->read();
            echo "Sending '$chunk'\n";
            $connection->write($chunk);
        }
        echo "Handled connection: echoed back " . strlen($data) . " bytes\n";
    });

    echo "Server is running on tcp://0.0.0.0:8080\n";
});
