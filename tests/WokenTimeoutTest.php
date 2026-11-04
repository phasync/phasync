<?php

/*
 * A wait with a short timeout (under 10 ms: an "exact" timeout) that ends because something woke
 * it must leave no timeout behind. A stale one made the coroutine's next short-timeout wait fail
 * with "The Fiber is already scheduled", or expire a later wait early.
 */

beforeEach(fn () => phasyncResetProcessState());
afterEach(fn () => phasyncResetProcessState());

test('a flag raised before a short timeout leaves no timeout for the next short wait', function () {
    $result = phasync::run(function () {
        $flag = new stdClass();
        phasync::go(function () use ($flag) {
            phasync::sleep(0.001);
            phasync::raiseFlag($flag);
        });
        phasync::awaitFlag($flag, 0.005);   // woken by the flag, not the timeout
        $other = new stdClass();
        try {
            phasync::awaitFlag($other, 0.002);
        } catch (phasync\TimeoutException) {
            return 'timed out';
        }

        return 'woken';
    });
    expect($result)->toBe('timed out');
});

test('a stale short timeout does not cut a later, longer wait short', function () {
    $elapsed = phasync::run(function () {
        $flag = new stdClass();
        phasync::go(function () use ($flag) {
            phasync::sleep(0.001);
            phasync::raiseFlag($flag);
        });
        phasync::awaitFlag($flag, 0.004);
        $start = \microtime(true);
        phasync::sleep(0.05);

        return \microtime(true) - $start;
    });
    expect($elapsed)->toBeGreaterThanOrEqual(0.049);
});
