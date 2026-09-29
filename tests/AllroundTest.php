<?php

uses()->group('phasync');

test('phasync::run executes a simple coroutine', function () {
    $result = phasync::run(function () {
        return 42;
    });

    expect($result)->toBe(42);
});

test('phasync::sleep pauses execution', function () {
    $start = \microtime(true);
    phasync::run(function () {
        phasync::sleep(0.5);
    });
    $end = \microtime(true);

    expect($end - $start)->toBeGreaterThanOrEqual(0.5);
});

test('phasync::go creates and runs multiple coroutines', function () {
    $results = [];
    phasync::run(function () use (&$results) {
        phasync::go(function () use (&$results) {
            phasync::sleep(0.1);
            $results[] = 1;
        });
        phasync::go(function () use (&$results) {
            $results[] = 2;
        });
        phasync::sleep(0.2);
    });

    expect($results)->toBe([2, 1]);
});

test('phasync::await waits for a coroutine to complete', function () {
    $result = phasync::run(function () {
        $fiber = phasync::go(function () {
            phasync::sleep(0.1);

            return 'done';
        });

        return phasync::await($fiber);
    });

    expect($result)->toBe('done');
});

test('phasync::channel creates a channel for communication between coroutines', function () {
    phasync::run(function () {
        phasync::channel($read, $write);

        phasync::go(function () use ($write) {
            $write->write('Hello');
            $write->close();
        });

        $message = $read->read();
        expect($message)->toBe('Hello');
        expect($read->read())->toBeNull();
    });
});

test('phasync handles exceptions in coroutines', function () {
    $exceptionThrown = false;

    try {
        phasync::run(function () {
            phasync::go(function () {
                throw new Exception('Test exception');
            });
            phasync::sleep(0.1);
        });
    } catch (Exception $e) {
        $exceptionThrown = true;
        expect($e->getMessage())->toBe('Test exception');
    }

    expect($exceptionThrown)->toBeTrue();
});
