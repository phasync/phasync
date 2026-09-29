<?php

test('execute run coroutine immediately', function () {
    expect(function () {
        phasync::run(function () {
            throw new Exception('Yes');
        });
        throw new Exception('No');
    })->toThrow(new Exception('Yes'));
});

test('execute go coroutine immediately before resuming', function () {
    // The main coroutine's own failure first, then the child's, which was thrown first but
    // nobody awaited
    try {
        phasync::run(function () {
            phasync::go(function () {
                throw new Exception('No');
            });
            throw new Exception('Yes');
        });
    } catch (phasync\AggregateException $e) {
    }
    expect(\array_map(fn ($x) => $x->getMessage(), $e->getExceptions()))->toBe(['Yes', 'No']);
});
