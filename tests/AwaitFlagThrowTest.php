<?php

/*
 * phasync::awaitFlag()'s $throw parameter: a timeout can return false instead of throwing
 * TimeoutException, without constructing one at all (expire() in src/EventLoop.php takes a
 * separate branch for throwOnTimeout: false that never calls `new TimeoutException`).
 */

use phasync\CancelledException;
use phasync\TimeoutException;

test('awaitFlag() returns true once the flag is raised, with throw: false too', function () {
    $out = phasync::run(function () {
        $flag   = new stdClass();
        $waiter = phasync::go(function () use ($flag) {
            return phasync::awaitFlag($flag, 1.0, throw: false);
        });
        phasync::sleep(0.01);
        phasync::raiseFlag($flag);

        return phasync::await($waiter);
    });

    expect($out)->toBeTrue();
});

test('awaitFlag() with throw: false returns false on timeout instead of throwing', function () {
    $out = phasync::run(function () {
        $flag = new stdClass();

        return phasync::awaitFlag($flag, 0.02, throw: false);
    });

    expect($out)->toBeFalse();
});

test('awaitFlag() default (throw: true) still throws TimeoutException on timeout', function () {
    $out = phasync::run(function () {
        $flag = new stdClass();
        try {
            phasync::awaitFlag($flag, 0.02);

            return 'no exception';
        } catch (Throwable $e) {
            return \get_class($e);
        }
    });

    expect($out)->toBe(TimeoutException::class);
});

test('awaitFlag() with throw: false and a timeout of 0 returns false at once', function () {
    [$out, $elapsed] = phasync::run(function () {
        $flag  = new stdClass();
        $t     = \microtime(true);
        $out   = phasync::awaitFlag($flag, 0.0, throw: false);

        return [$out, \microtime(true) - $t];
    });

    expect($out)->toBeFalse();
    expect($elapsed)->toBeLessThan(0.1);
});

test('awaitFlag() with throw: false and a negative timeout returns false at once', function () {
    $out = phasync::run(function () {
        $flag = new stdClass();

        return phasync::awaitFlag($flag, -1.0, throw: false);
    });

    expect($out)->toBeFalse();
});

test('cancellation during a throw: false wait still throws CancelledException', function () {
    $out = phasync::run(function () {
        $flag   = new stdClass();
        $waiter = phasync::go(function () use ($flag) {
            try {
                phasync::awaitFlag($flag, 1.0, throw: false);

                return 'no exception';
            } catch (Throwable $e) {
                return \get_class($e);
            }
        });
        phasync::sleep(0.01);
        phasync::cancel($waiter);

        return phasync::await($waiter);
    });

    expect($out)->toBe(CancelledException::class);
});

test('dropping the flag during a throw: false wait still throws CancelledException', function () {
    $out = phasync::run(function () {
        $owner       = new stdClass();
        $owner->flag = new stdClass();
        $waiter      = phasync::go(function () use ($owner) {
            try {
                phasync::awaitFlag($owner->flag, 1.0, throw: false);

                return 'no exception';
            } catch (Throwable $e) {
                return \get_class($e);
            }
        });
        phasync::sleep(0.01);
        $owner->flag = null;

        return phasync::await($waiter);
    });

    expect($out)->toBe(CancelledException::class);
});

test('a coroutine can mix throw: false and the default across several waits', function () {
    $out = phasync::run(function () {
        $flag  = new stdClass();
        $log   = [];
        $log[] = phasync::awaitFlag($flag, 0.01, throw: false); // false: times out
        try {
            phasync::awaitFlag($flag, 0.01); // default: throws
            $log[] = 'no exception';
        } catch (TimeoutException) {
            $log[] = 'threw';
        }
        $log[] = phasync::awaitFlag($flag, 0.01, throw: false); // false again: times out

        return $log;
    });

    expect($out)->toBe([false, 'threw', false]);
});
