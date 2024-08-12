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

test('phasync::select chooses the first ready selectable', function () {
    $result = phasync::run(function () {
        phasync::channel($read1, $write1);
        phasync::channel($read2, $write2);

        phasync::go(function () use ($write1) {
            phasync::sleep(0.1);
            $write1->write('First');
        });

        phasync::go(function () use ($write2) {
            phasync::sleep(0.2);
            $write2->write('Second');
        });

        $selected = phasync::select([$read1, $read2]);

        $read2->read(); // Must read since it will write

        return $selected->read();
    });

    expect($result)->toBe('First');
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

test('phasync::preempt allows other coroutines to run during CPU-intensive tasks', function () {
    $order = [];
    phasync::run(function () use (&$order) {
        phasync::go(function () use (&$order) {
            for ($i = 0; $i < 1000000; ++$i) {
                if (0 === $i % 100000) {
                    $order[] = "A$i";
                    phasync::preempt();
                }
            }
        });

        phasync::go(function () use (&$order) {
            for ($i = 0; $i < 5; ++$i) {
                $order[] = "B$i";
                phasync::sleep(0.01);
            }
        });
    });

    expect($order)->toContain('B0');
    expect($order)->toContain('A0');
    expect($order)->toContain('B4');
    expect($order)->toContain('A900000');
});

test('phasync::fork runs a function in a separate process', function () {
    $result = phasync::run(function () {
        return phasync::fork(function () {
            return \posix_getpid();
        });
    });

    expect($result)->not->toBe(\posix_getpid());
});
