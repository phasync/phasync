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

test('waiters get the lock in the order they asked for it: a holder that asks again goes to the back', function () {
    $log = phasync::run(function () {
        $log = [];
        $a   = phasync::go(function () use (&$log) {
            for ($i = 1; $i <= 3; ++$i) {
                Synchronized::run('token', function () use ($i, &$log) {
                    $log[] = "a$i";
                    phasync::sleep(0.01);
                });
            }
        });
        $b = phasync::go(function () use (&$log) {
            Synchronized::run('token', function () use (&$log) {
                $log[] = 'b';
            });
        });
        phasync::await($a);
        phasync::await($b);

        return $log;
    });
    expect($log)->toBe(['a1', 'b', 'a2', 'a3']);
});

test('a waiter cancelled in line leaves the lock to the next', function () {
    $log = phasync::run(function () {
        $log  = [];
        $hold = phasync::go(fn () => Synchronized::run('token', fn () => phasync::sleep(0.02)));
        $gone = phasync::go(function () use (&$log) {
            Synchronized::run('token', function () use (&$log) {
                $log[] = 'gone';
            });
        });
        $next = phasync::go(function () use (&$log) {
            Synchronized::run('token', function () use (&$log) {
                $log[] = 'next';
            });
        });
        phasync::sleep(0.01);
        phasync::cancel($gone);
        phasync::await($hold);
        phasync::await($next);
        try {
            phasync::await($gone);
        } catch (Throwable) {
        }
        Synchronized::run('token', function () use (&$log) {
            $log[] = 'free';
        });

        return $log;
    });
    expect($log)->toBe(['next', 'free']);
});
