<?php
require __DIR__ . '/../vendor/autoload.php';

$context = stream_context_create([
    'socket' => [
        'backlog' => 511,
        'tcp_nodelay' => true,
    ]
]);
$socket = stream_socket_server('tcp://0.0.0.0:8080', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if (!$socket) {
    die("Could not create socket: $errstr ($errno)");
}
stream_set_chunk_size($socket, 65536);
$pids = [];
for ($i = 0; $i < 4; $i++) {
    $pid = \pcntl_fork();
    if ($pid == -1) {
        die("Could not fork");
    } elseif ($pid) {
        $pids[] = $pid;
        continue;
    } else {
        break;
    }
}

if ($pid) {
    foreach ($pids as $child) {
        \pcntl_waitpid($child, $status);
    }
    die("Closed children\n");
}

phasync::run(args: [$socket], fn: function ($socket) {
    while (true) {        
        phasync::readable($socket);     // Wait for activity on the server socket, while allowing coroutines to run
        if (!($client = @stream_socket_accept($socket, 0.01))) {
            continue;
        }
        
        phasync::go(function () use ($client) {
            //phasync::sleep();           // this single sleep allows the server to accept slightly more connections before reading and writing
            phasync::readable($client); // pause coroutine until resource is readable
            $request = \fread($client, 32768);
            phasync::writable($client); // pause coroutine until resource is writable
            $written = fwrite($client,
                "HTTP/1.1 200 OK\r\nConnection: close\r\nContent-Type: text/plain\r\nContent-Length: 13\r\n\r\n".
                "Hello, world!"
            );
            fclose($client);
        });
    }
});
