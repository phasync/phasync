<?php

require __DIR__ . '/../vendor/autoload.php';

test('test finally statement in run-coroutine', function () {
    $counter = 0;
    phasync::run(function () use (&$counter) {
        $counter += 2;
        phasync::finally(function () use (&$counter) {
            expect($counter--)->toBe(1);
        });
        phasync::finally(function () use (&$counter) {
            expect($counter--)->toBe(2);
        });
    });
    expect($counter)->toBe(0);
});

test('test finally in nested coroutines', function () {
    $counter = 0;
    phasync::run(function () use (&$counter) {
        phasync::finally(function () use (&$counter) {
            expect($counter--)->toBe(1);
        });
        phasync::go(function () use (&$counter) {
            phasync::finally(function () use (&$counter) {
                expect($counter--)->toBe(2);
            });
            ++$counter;
        });
        ++$counter;
    });
    expect($counter)->toBe(0);
});

test('test finally with exceptions', function () {
    $counter = 0;
    try {
        phasync::run(function () use (&$counter) {
            phasync::finally(function () use (&$counter) {
                expect($counter)->toBe(1);
                ++$counter;
            });
            ++$counter;
            throw new Exception('Test exception');
        });
    } catch (Exception $e) {
        expect($counter)->toBe(2);
    }
});
