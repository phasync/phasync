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
    // The child ran (and failed) before go() returned; run() throws both, the main coroutine's
    // own failure first
    $log = [];
    try {
        phasync::run(function () use (&$log) {
            phasync::go(function () use (&$log) {
                $log[] = 'child';
                throw new Exception('No');
            });
            $log[] = 'main';
            throw new Exception('Yes');
        });
    } catch (phasync\AggregateException $e) {
        $log[] = \array_map(static fn ($x) => $x->getMessage(), $e->getExceptions());
    }
    expect($log)->toBe(['child', 'main', ['Yes', 'No']]);
});
