<?php

use phasync\CancelledException;

test('idle() waits until nothing else is runnable', function () {
    phasync::run(function () {
        $log  = [];
        $busy = phasync::go(function () use (&$log) {
            for ($i = 0; $i < 5; ++$i) {
                $log[] = 'busy';
                phasync::yield();
            }
        });
        phasync::idle();
        $log[] = 'idle';
        phasync::await($busy);
        expect($log)->toBe(['busy', 'busy', 'busy', 'busy', 'busy', 'idle']);
    });
});

test('idle() wakes its waiters one per idle moment, oldest first', function () {
    phasync::run(function () {
        $order = [];
        $a     = phasync::go(function () use (&$order) {
            phasync::idle();
            $order[] = 'a';
        });
        $b     = phasync::go(function () use (&$order) {
            phasync::idle();
            $order[] = 'b';
        });
        $c     = phasync::go(function () use (&$order) {
            phasync::idle();
            $order[] = 'c';
        });
        phasync::await($a);
        phasync::await($b);
        phasync::await($c);
        expect($order)->toBe(['a', 'b', 'c']);
    });
});

test('idle($after) waits for that much idleness, not just the next idle moment', function () {
    phasync::run(function () {
        $log = [];
        phasync::go(function () use (&$log) {
            phasync::sleep(0.05);
            $log[] = 'slept';
        });
        $t = \microtime(true);
        phasync::idle(0.01);
        $elapsed = \microtime(true) - $t;
        $log[]   = 'idle';
        // idle() returned while the sleeper was still waiting, not after it woke
        expect($log)->toBe(['idle']);
        expect($elapsed)->toBeGreaterThanOrEqual(0.008);
        expect($elapsed)->toBeLessThan(0.04);
    });
});

test('cancelling an idle() waiter does not swallow the idle moment for the next one', function () {
    phasync::run(function () {
        $first  = phasync::go(function () {
            phasync::idle();
        });
        $second = phasync::go(function () {
            phasync::idle();
        });
        phasync::cancel($first);
        expect(fn () => phasync::await($first))->toThrow(CancelledException::class);
        // Would throw TimeoutException here if the cancelled waiter's idle moment had been
        // swallowed instead of passed on to $second.
        phasync::await($second, 1.0);
        expect(true)->toBeTrue();
    });
});

test('phasync::idle() outside of phasync is cheap', function () {
    $t = \hrtime(true);
    phasync::idle();
    expect(\hrtime(true) - $t)->toBeLessThan(50000);
});
