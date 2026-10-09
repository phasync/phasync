<?php

/*
 * phasync/phasync#88: a Fiber a coroutine runs itself (Drupal's renderer) belongs to that
 * coroutine's code, which resumes it. phasync's waits there block or return, as outside a
 * coroutine, instead of suspending it back to that code while the loop holds the coroutine.
 */

test('sleep() in a Fiber a coroutine runs blocks in it, and the loop still runs the others', function () {
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
            phasync::idle();
            $log[] = 'nested slept ' . (\microtime(true) - $t >= 0.05 ? 'enough' : 'too little');

            return 'done';
        });
        expect($nested->start())->toBeNull();
        expect($nested->isTerminated())->toBeTrue();
        expect($nested->getReturn())->toBe('done');
        phasync::sleep(0.02);
        $log[] = 'coroutine slept';
    });
    expect($log)->toBe(['nested slept enough', 'other coroutine', 'coroutine slept']);
});

test('readable() in a Fiber a coroutine runs waits in it', function () {
    phasync::run(function () {
        [$a, $b] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, 0);
        \stream_set_blocking($a, false);
        $nested = new Fiber(function () use ($a, $b) {
            \fwrite($b, 'x');

            return \fread(phasync::readable($a, 1.0), 1);
        });
        $nested->start();
        expect($nested->isTerminated())->toBeTrue();
        expect($nested->getReturn())->toBe('x');
    });
});
