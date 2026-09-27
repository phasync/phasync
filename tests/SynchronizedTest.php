<?php

use phasync\Util\Synchronized;

test('coroutines of one context take turns; they are not re-entrant calls', function () {
    $log = phasync::run(function () {
        $log = [];
        $fs  = [];
        foreach (['a', 'b'] as $name) {
            // Same context: coroutines started by one request share it
            $fs[] = phasync::go(function () use ($name, &$log) {
                Synchronized::run('token', function () use ($name, &$log) {
                    $log[] = "$name in";
                    phasync::sleep(0.01);
                    $log[] = "$name out";
                });
            });
        }
        foreach ($fs as $f) {
            phasync::await($f);
        }

        return $log;
    });
    expect($log)->toBe(['a in', 'a out', 'b in', 'b out']);
});

test('coroutines of different contexts take turns too', function () {
    $log = phasync::run(function () {
        $log = [];
        $fs  = [];
        foreach (['a', 'b'] as $name) {
            $fs[] = phasync::go(function () use ($name, &$log) {
                Synchronized::run('token', function () use ($name, &$log) {
                    $log[] = "$name in";
                    phasync::sleep(0.01);
                    $log[] = "$name out";
                });
            }, context: new phasync\Context\DefaultContext());
        }
        foreach ($fs as $f) {
            phasync::await($f);
        }

        return $log;
    });
    expect($log)->toBe(['a in', 'a out', 'b in', 'b out']);
});

test('a nested run() for the same token in the same coroutine is refused (not re-entrant)', function () {
    expect(fn () => phasync::run(fn () => Synchronized::run('token', fn () => Synchronized::run('token', fn () => null))))
        ->toThrow(LogicException::class);
});
