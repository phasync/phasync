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
    /*
     * This will throw the "Yes" exception, even though the "No" exception
     * is actually thrown first. This is because the coroutine that throws
     * the "No" exception is not awaited, and therefore the "No" exception
     * is actually not surfaced until the coroutine is garbage collected.
     */
    expect(function () {
        phasync::run(function () {
            phasync::go(function () {
                throw new Exception('No');
            });
            throw new Exception('Yes');
        });
    })->toThrow(new Exception('Yes'));
});
