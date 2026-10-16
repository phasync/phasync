<?php

use phasync\Util\Console;
use phasync\Util\ConsoleLogger;
use Psr\Log\InvalidArgumentException;

test('ConsoleLogger defaults to STDERR', function () {
    $logger = new ConsoleLogger();
    expect($logger)->toBeInstanceOf(ConsoleLogger::class);
});

test('ConsoleLogger with custom console', function () {
    $console = new Console(\STDOUT);
    $logger  = new ConsoleLogger($console);
    expect($logger)->toBeInstanceOf(ConsoleLogger::class);
});

test('ConsoleLogger with custom level', function () {
    $logger = new ConsoleLogger(null, 'warning');
    expect($logger)->toBeInstanceOf(ConsoleLogger::class);
});

test('ConsoleLogger throws on invalid level', function () {
    expect(fn () => new ConsoleLogger(null, 'invalid'))
        ->toThrow(InvalidArgumentException::class);
});

test('ConsoleLogger with source', function () {
    $logger = new ConsoleLogger(null, 'debug', 'worker-1');
    expect($logger)->toBeInstanceOf(ConsoleLogger::class);
});

test('ConsoleLogger log() with debug level', function () {
    // Use a temporary file to capture output
    $tmpfile  = \tempnam(\sys_get_temp_dir(), 'test_');
    $resource = \fopen($tmpfile, 'w');

    try {
        $console = new Console($resource);
        $logger  = new ConsoleLogger($console, 'debug');

        $logger->debug('test message');
        $logger->info('info message');

        \fclose($resource);

        // Verify output was written
        $content = \file_get_contents($tmpfile);
        expect($content)->toContain('test message');
        expect($content)->toContain('info message');
    } finally {
        @\unlink($tmpfile);
    }
});

test('ConsoleLogger respects level filtering', function () {
    $tmpfile  = \tempnam(\sys_get_temp_dir(), 'test_');
    $resource = \fopen($tmpfile, 'w');

    try {
        $console = new Console($resource);
        $logger  = new ConsoleLogger($console, 'warning');

        $logger->debug('debug');
        $logger->info('info');
        $logger->notice('notice');
        $logger->warning('warning');
        $logger->error('error');

        \fclose($resource);

        $content = \file_get_contents($tmpfile);
        expect($content)->not->toContain('debug');
        expect($content)->not->toContain('info');
        expect($content)->not->toContain('notice');
        expect($content)->toContain('warning');
        expect($content)->toContain('error');
    } finally {
        @\unlink($tmpfile);
    }
});

test('ConsoleLogger log with context placeholders', function () {
    $tmpfile  = \tempnam(\sys_get_temp_dir(), 'test_');
    $resource = \fopen($tmpfile, 'w');

    try {
        $console = new Console($resource);
        $logger  = new ConsoleLogger($console);

        $logger->info('User {user} logged in from {ip}', ['user' => 'alice', 'ip' => '192.168.1.1']);

        \fclose($resource);

        $content = \file_get_contents($tmpfile);
        expect($content)->toContain('alice');
        expect($content)->toContain('192.168.1.1');
    } finally {
        @\unlink($tmpfile);
    }
});

test('ConsoleLogger log() with invalid level throws', function () {
    $logger = new ConsoleLogger();

    expect(fn () => $logger->log('invalid', 'message'))
        ->toThrow(InvalidArgumentException::class);
});

test('ConsoleLogger emergency', function () {
    $tmpfile  = \tempnam(\sys_get_temp_dir(), 'test_');
    $resource = \fopen($tmpfile, 'w');

    try {
        $console = new Console($resource);
        $logger  = new ConsoleLogger($console);

        $logger->emergency('emergency message');

        \fclose($resource);

        $content = \file_get_contents($tmpfile);
        expect($content)->toContain('emergency message');
        expect($content)->toContain('emergency');
    } finally {
        @\unlink($tmpfile);
    }
});

test('ConsoleLogger alert', function () {
    $tmpfile  = \tempnam(\sys_get_temp_dir(), 'test_');
    $resource = \fopen($tmpfile, 'w');

    try {
        $console = new Console($resource);
        $logger  = new ConsoleLogger($console);

        $logger->alert('alert message');

        \fclose($resource);

        $content = \file_get_contents($tmpfile);
        expect($content)->toContain('alert message');
    } finally {
        @\unlink($tmpfile);
    }
});

test('ConsoleLogger critical', function () {
    $tmpfile  = \tempnam(\sys_get_temp_dir(), 'test_');
    $resource = \fopen($tmpfile, 'w');

    try {
        $console = new Console($resource);
        $logger  = new ConsoleLogger($console);

        $logger->critical('critical message');

        \fclose($resource);

        $content = \file_get_contents($tmpfile);
        expect($content)->toContain('critical message');
    } finally {
        @\unlink($tmpfile);
    }
});

test('ConsoleLogger error', function () {
    $tmpfile  = \tempnam(\sys_get_temp_dir(), 'test_');
    $resource = \fopen($tmpfile, 'w');

    try {
        $console = new Console($resource);
        $logger  = new ConsoleLogger($console);

        $logger->error('error message');

        \fclose($resource);

        $content = \file_get_contents($tmpfile);
        expect($content)->toContain('error message');
    } finally {
        @\unlink($tmpfile);
    }
});

test('ConsoleLogger notice', function () {
    $tmpfile  = \tempnam(\sys_get_temp_dir(), 'test_');
    $resource = \fopen($tmpfile, 'w');

    try {
        $console = new Console($resource);
        $logger  = new ConsoleLogger($console, 'notice');

        $logger->notice('notice message');

        \fclose($resource);

        $content = \file_get_contents($tmpfile);
        expect($content)->toContain('notice message');
    } finally {
        @\unlink($tmpfile);
    }
});

test('ConsoleLogger with Stringable context', function () {
    $tmpfile  = \tempnam(\sys_get_temp_dir(), 'test_');
    $resource = \fopen($tmpfile, 'w');

    try {
        $obj = new class implements Stringable {
            public function __toString(): string
            {
                return 'stringable value';
            }
        };

        $console = new Console($resource);
        $logger  = new ConsoleLogger($console);

        $logger->info('Value: {val}', ['val' => $obj]);

        \fclose($resource);

        $content = \file_get_contents($tmpfile);
        expect($content)->toContain('stringable value');
    } finally {
        @\unlink($tmpfile);
    }
});
