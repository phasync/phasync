<?php

require __DIR__ . '/vendor/autoload.php';

use phasync\Server;
use function phasync\{run, go};

run(function() {
    $server = new Server('127.0.0.1', 8080);
    $server->run(function(Connection $connection) {
        try {
            while (!$connection->eof()) {
                $data = $connection->readLine();
                // Echo the received data back to the client
                if ($data !== '') {
                    $connection->write($data);
                }
            }
        } catch (\Exception $e) {
            // Handle any exceptions (e.g., connection errors)
            echo "Error: " . $e->getMessage() . "\n";
        } finally {
            // Ensure the connection is closed
            $connection->close();
        }
    });

    $server->close();
});
