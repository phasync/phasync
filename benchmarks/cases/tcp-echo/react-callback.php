<?php // Callback style (no coroutines): react/socket has no fiber-style API.
require __DIR__ . '/../../vendor/autoload.php'; $s = new React\Socket\SocketServer('127.0.0.1:' . $argv[1]);
$s->on('connection', function ($c) { $c->on('data', fn($d) => $c->write($d)); }); echo "ready\n";
