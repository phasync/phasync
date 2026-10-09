<?php

/*
 * phasync/phasync#88 and phasync/phasync-ext-src#83: a Fiber a coroutine runs itself (Drupal's
 * renderer) belongs to that coroutine's code, which resumes it. A wait there runs the event loop
 * in place until it is over: other coroutines run meanwhile, and the Fiber is never suspended
 * back to the code running it.
 */

test('sleep() in a Fiber a coroutine runs lets the other coroutines run, and returns in that Fiber', function () {
    $log = [];
    phasync::run(function () use (&$log) {
        phasync::go(function () use (&$log) {
            phasync::sleep(0.01);
            $log[] = 'other coroutine';
        });
        $nested = new Fiber(function () use (&$log) {
            $t = \microtime(true);
            phasync::sleep(0.05);
            phasync::sleep(0);
            phasync::yield();
            $log[] = 'nested slept ' . (\microtime(true) - $t >= 0.05 ? 'enough' : 'too little');

            return 'done';
        });
        expect($nested->start())->toBeNull();
        expect($nested->isTerminated())->toBeTrue();
        expect($nested->getReturn())->toBe('done');
        phasync::sleep(0.02);
        $log[] = 'coroutine slept';
    });
    expect($log)->toBe(['other coroutine', 'nested slept enough', 'coroutine slept']);
});

test('readable() in a Fiber a coroutine runs waits for another coroutine to write', function () {
    phasync::run(function () {
        [$a, $b] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, 0);
        \stream_set_blocking($a, false);
        phasync::go(function () use ($b) {
            phasync::sleep(0.01);
            \fwrite($b, 'x');
        });
        $nested = new Fiber(fn () => \fread(phasync::readable($a, 1.0), 1));
        $nested->start();
        expect($nested->isTerminated())->toBeTrue();
        expect($nested->getReturn())->toBe('x');
    });
});

test('a coroutine waiting in a Fiber of its own for a lock another coroutine holds gets it once that one releases it (Drupal: a row lock another request holds)', function () {
    $log = [];
    phasync::run(function () use (&$log) {
        $released = new stdClass();
        $locked   = true;
        phasync::go(function () use (&$log, &$locked, $released) {   // holds the lock across a wait
            phasync::sleep(0.02);
            $locked = false;
            $log[]  = 'holder released';
            phasync::raiseFlag($released);
        });
        $nested = new Fiber(function () use (&$log, &$locked, $released) {
            while ($locked) {
                phasync::awaitFlag($released, 1.0);
            }
            $log[] = 'waiter got the lock';
        });
        $nested->start();
        $log[] = 'nested ' . ($nested->isTerminated() ? 'done' : 'suspended');
    });
    expect($log)->toBe(['holder released', 'waiter got the lock', 'nested done']);
});

test('a timeout of the wait is thrown in the Fiber that waits', function () {
    phasync::run(function () {
        $flag   = new stdClass();
        $nested = new Fiber(fn () => phasync::awaitFlag($flag, 0.02));
        expect(fn () => $nested->start())->toThrow(phasync\TimeoutException::class);
    });
});

test('Fibers nest: a wait in a Fiber of a coroutine resumed by a wait in place runs the loop in place again', function () {
    $log = [];
    phasync::run(function () use (&$log) {
        phasync::go(function () use (&$log) {
            (new Fiber(function () use (&$log) {
                phasync::sleep(0.01);
                $log[] = 'inner';
            }))->start();
        });
        (new Fiber(function () use (&$log) {
            phasync::sleep(0.03);
            $log[] = 'outer';
        }))->start();
    });
    expect($log)->toBe(['inner', 'outer']);
});
