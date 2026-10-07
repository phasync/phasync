<?php

use phasync\CancelledException;
use phasync\ShutdownException;

/*
 * phasync::shutdown(): every other coroutine of the run gets a ShutdownException (a
 * CancelledException) at its wait, and has a short window to clean up.
 */

test('shutdown() throws into every other coroutine, which may clean up; the caller goes on', function () {
    $log = [];
    $left = phasync::run(function () use (&$log) {
        foreach (['a', 'b'] as $name) {
            phasync::go(function () use ($name, &$log) {
                try {
                    phasync::sleep(10);
                } catch (ShutdownException $e) {
                    $log[] = "$name: {$e->getMessage()}";
                } finally {
                    phasync::finally(function () use ($name, &$log) {
                        phasync::sleep(0.01);   // cleanup that waits
                        $log[] = "$name cleaned up";
                    });
                }
            });
        }
        phasync::sleep(0.01);
        $left = phasync::shutdown(1.0, new ShutdownException('stopping'));
        $log[] = 'caller goes on';

        return $left;
    });
    expect($left)->toBe(0);
    sort($log);
    expect($log)->toBe(['a cleaned up', 'a: stopping', 'b cleaned up', 'b: stopping', 'caller goes on']);
});

test('shutdown() returns the coroutines still running after the window', function () {
    $left = phasync::run(function () {
        phasync::go(function () {
            try {
                phasync::sleep(10);
            } catch (CancelledException) {
            }
            phasync::finally(fn () => phasync::sleep(5));   // ignores the window
        });
        phasync::sleep(0.01);

        return [phasync::shutdown(0.1), microtime(true)];
    });
    expect($left[0])->toBe(1);
});

test('cancel() takes a CancelledException of the caller\'s own, such as a ShutdownException', function () {
    $got = phasync::run(function () {
        $mine   = new ShutdownException('mine');
        $worker = phasync::go(function () {
            try {
                phasync::sleep(10);
            } catch (CancelledException $e) {
                return $e;
            }
        });
        phasync::sleep(0.01);
        phasync::cancel($worker, $mine);

        return [phasync::await($worker), $mine];
    });
    expect($got[0])->toBe($got[1]);
});

test('shutdown() reaches a coroutine in a context of its own, as a server runs each request', function () {
    $got = phasync::run(function () {
        $result = null;
        phasync::go(function () use (&$result) {
            try {
                phasync::sleep(10);
            } catch (ShutdownException) {
                $result = 'stopped';
            }
        }, context: new stdClass());
        phasync::sleep(0.01);
        phasync::shutdown(1.0);

        return $result;
    });
    expect($got)->toBe('stopped');
});
