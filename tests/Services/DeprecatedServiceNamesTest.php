<?php

use phasync\Services\CurlMulti;
use phasync\Services\MySQLiAwaiter;
use phasync\Services\MySQLiPoll;

test('CurlMulti::await() is CurlAwaiter::await() under its old name', function () {
    $server = \stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    $port   = (int) \substr(\stream_socket_get_name($server, false), \strrpos(\stream_socket_get_name($server, false), ':') + 1);

    $body = phasync::run(function () use ($server, $port) {
        phasync::go(function () use ($server) {
            phasync::readable($server);
            $client = \stream_socket_accept($server);
            phasync::readable($client);
            \fread($client, 8192);
            \fwrite($client, "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nok");
            \fclose($client);
        });
        $ch = \curl_init("http://127.0.0.1:$port/");
        \curl_setopt($ch, \CURLOPT_RETURNTRANSFER, true);

        return CurlMulti::await($ch);
    });
    \fclose($server);

    expect($body)->toBe('ok');
    expect((new ReflectionClass(CurlMulti::class))->getDocComment())->toContain('@deprecated');
    expect((new ReflectionMethod(CurlMulti::class, 'await'))->getParameters())->toHaveCount(1);
});

test('MySQLiPoll::poll() keeps the signature of MySQLiAwaiter::await()', function () {
    $old = new ReflectionMethod(MySQLiPoll::class, 'poll');
    $new = new ReflectionMethod(MySQLiAwaiter::class, 'await');

    expect(\array_map(fn ($p) => [$p->getName(), (string) $p->getType(), $p->isOptional()], $old->getParameters()))
        ->toBe(\array_map(fn ($p) => [$p->getName(), (string) $p->getType(), $p->isOptional()], $new->getParameters()));
    expect((string) $old->getReturnType())->toBe((string) $new->getReturnType());
    expect($old->getDocComment())->toContain('@deprecated');
});
