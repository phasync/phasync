<?php

test('execute run coroutine immediately', function() {
    expect(function() {
        phasync::run(function() {
            throw new Exception("Yes");
        });
        throw new Exception("No");
    })->toThrow(new Exception("Yes"));
});

test('execute go coroutine immediately before resuming', function() {
    /**
     * This will throw the "Yes" exception, even though the "No" exception
     * is actually thrown first. This is because the coroutine that throws
     * the "No" exception is not awaited, and therefore the "No" exception
     * is actually not surfaced until the coroutine is garbage collected.
     */
    expect(function() {
        phasync::run(function() {
            phasync::go(function() {
                throw new Exception("No");
            });
            throw new Exception("Yes");
        });
    })->toThrow(new Exception("Yes"));
});

test('execution order of coroutines', function() {
    /**
     * This tests check that the coroutine is executed immediately upon
     * creation, before it is added to the event loop to run on the next
     * tick. It allows the execution order of coroutines on the next tick
     * to vary. The test guarantees for execution order may be changed in
     * the future if proper threading support arrives in PHP.
     */
    $step = 0;
    phasync::run(function() use (&$step) {
        expect($step++)->toBe(0);
        phasync::go(function() use (&$step) {
            expect($step++)->toBe(1);
            phasync::sleep(0);
            expect($step++)->toBeBetween(4, 5);

        });
        phasync::go(function() use (&$step) {
            expect($step++)->toBe(2);
            phasync::sleep(0);
            expect($step++)->toBeBetWeen(4,5);
        });
        expect($step++)->toBe(3);
    });
});