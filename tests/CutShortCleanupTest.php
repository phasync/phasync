<?php

/*
 * phasync warns (E_USER_WARNING) when a cancelled coroutine's own cleanup is cut short: its
 * `finally` block waits, and that wait throws the sticky cancellation again before the rest of
 * the block runs. See EventLoop::checkCancelled()/makeExceptionHolder().
 */

use phasync\CancelledException;

function captureUserWarnings(Closure $fn): array
{
    $warnings = [];
    \set_error_handler(function (int $level, string $message) use (&$warnings) {
        $warnings[] = $message;

        return true;
    }, \E_USER_WARNING);
    try {
        $fn();
    } finally {
        \restore_error_handler();
    }

    return $warnings;
}

test('a wait inside finally that is cut short by the cancellation warns', function () {
    $warnings = captureUserWarnings(function () {
        phasync::run(function () {
            $waiter = phasync::go(function () {
                try {
                    phasync::sleep(1.0);
                } finally {
                    phasync::sleep(0.01); // sticky cancellation: throws again, cutting this short
                }
            });
            phasync::sleep(0.01);
            phasync::cancel($waiter);
            try {
                phasync::await($waiter);
            } catch (CancelledException) {
            }
        });
    });

    expect($warnings)->toHaveCount(1);
    expect($warnings[0])->toContain('cut short');
});

test('a finally that completes without waiting does not warn', function () {
    $log      = [];
    $warnings = captureUserWarnings(function () use (&$log) {
        phasync::run(function () use (&$log) {
            $waiter = phasync::go(function () use (&$log) {
                try {
                    phasync::sleep(1.0);
                } finally {
                    $log[] = 'cleaned up';
                }
            });
            phasync::sleep(0.01);
            phasync::cancel($waiter);
            try {
                phasync::await($waiter);
            } catch (CancelledException) {
            }
        });
    });

    expect($log)->toBe(['cleaned up']);
    expect($warnings)->toBe([]);
});

test('a finally that waits inside shielded() does not warn, and the wait completes', function () {
    $log      = [];
    $warnings = captureUserWarnings(function () use (&$log) {
        phasync::run(function () use (&$log) {
            $waiter = phasync::go(function () use (&$log) {
                try {
                    phasync::sleep(1.0);
                } finally {
                    phasync::shielded(function () use (&$log) {
                        phasync::sleep(0.01);
                        $log[] = 'cleaned up';
                    });
                }
            });
            phasync::sleep(0.01);
            phasync::cancel($waiter);
            try {
                phasync::await($waiter);
            } catch (CancelledException) {
            }
        });
    });

    expect($log)->toBe(['cleaned up']);
    expect($warnings)->toBe([]);
});

test('a plain cancellation with no finally at all does not warn', function () {
    $warnings = captureUserWarnings(function () {
        phasync::run(function () {
            $waiter = phasync::go(function () {
                phasync::sleep(1.0);
            });
            phasync::sleep(0.01);
            phasync::cancel($waiter);
            try {
                phasync::await($waiter);
            } catch (CancelledException) {
            }
        });
    });

    expect($warnings)->toBe([]);
});
