<?php

use phasync\TimeoutException;
use phasync\Util\LockTrait;

function lockable(): object
{
    return new class {
        use LockTrait;
    };
}

test('the holder may lock again (re-entrant)', function () {
    $o = lockable();
    expect(phasync::run(fn () => $o->lock(fn () => $o->lock(fn () => 42))))->toBe(42);
});

test('waiters get the lock in the order they asked for it: a holder that asks again goes to the back', function () {
    $o   = lockable();
    $log = phasync::run(function () use ($o) {
        $log = [];
        $a   = phasync::go(function () use ($o, &$log) {
            for ($i = 1; $i <= 3; ++$i) {
                $o->lock(function () use ($i, &$log) {
                    $log[] = "a$i";
                    phasync::sleep(0.01);
                });
            }
        });
        $b = phasync::go(function () use ($o, &$log) {
            $o->lock(function () use (&$log) {
                $log[] = 'b';
            });
        });
        phasync::await($a);
        phasync::await($b);

        return $log;
    });
    expect($log)->toBe(['a1', 'b', 'a2', 'a3']);
});

test('a waiter that times out leaves the line, and the lock goes to the next', function () {
    $o   = lockable();
    $log = phasync::run(function () use ($o) {
        $log  = [];
        // Held for 0.5 s, waking often: timeouts are checked every 0.1 s (TMO-3)
        $hold = phasync::go(fn () => $o->lock(function () {
            for ($i = 0; $i < 25; ++$i) {
                phasync::sleep(0.02);
            }
        }));
        $late = phasync::go(function () use ($o, &$log) {
            try {
                $o->lock(function () use (&$log) {
                    $log[] = 'late';
                }, 0.05);
            } catch (TimeoutException) {
                $log[] = 'timed out';
            }
        });
        $next = phasync::go(function () use ($o, &$log) {
            $o->lock(function () use (&$log) {
                $log[] = 'next';
            });
        });
        phasync::await($hold);
        phasync::await($late);
        phasync::await($next);
        $o->lock(function () use (&$log) {
            $log[] = 'free';
        });

        return $log;
    });
    expect($log)->toBe(['timed out', 'next', 'free']);
});
