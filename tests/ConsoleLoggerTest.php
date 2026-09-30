<?php

use phasync\Util\Console;
use phasync\Util\ConsoleLogger;

function loggerLines(Closure $use, string $level = 'debug'): array
{
    $stream = \fopen('php://memory', 'w+');
    $use(new ConsoleLogger(new Console($stream, false), $level, 'w1'));
    \rewind($stream);

    return \array_map(static fn ($l) => \substr($l, 23), \explode("\n", \rtrim(\stream_get_contents($stream))));
}

test('ConsoleLogger writes Console::log() lines for every PSR-3 level, with placeholders', function () {
    $lines = loggerLines(function (ConsoleLogger $log) {
        $log->info('listening on {address}', ['address' => '127.0.0.1:8080']);
        $log->warning('disk {path} is full', ['path' => '/var']);
        $log->emergency('down');
    });
    expect($lines)->toBe(['w1 listening on 127.0.0.1:8080', 'w1 warning   disk /var is full', 'w1 emergency down']);
});

test('ConsoleLogger drops levels below its minimum', function () {
    $lines = loggerLines(function (ConsoleLogger $log) {
        $log->debug('noise');
        $log->info('noise');
        $log->error('kept');
    }, 'warning');
    expect($lines)->toBe(['w1 error     kept']);
});

test('ConsoleLogger rejects an unknown level with the PSR-3 exception', function () {
    expect(fn () => loggerLines(fn (ConsoleLogger $log) => $log->log('verbose', 'x')))->toThrow(Psr\Log\InvalidArgumentException::class);
});
