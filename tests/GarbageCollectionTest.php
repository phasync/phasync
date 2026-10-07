<?php

/*
 * EventLoop collects cyclic garbage in two ways (see EventLoop::tick()); PHP's own collector
 * is off inside run():
 * - idle-time: right before the poller would wait with a timeout > 0 (nothing runnable), if
 *   there are any possible cycles at all, a poll(0) finds no ready I/O, and the last idle-time
 *   collection was at least GC_MIN_INTERVAL ago.
 * - busy, time-based: a loop that never idles still collects once GC_MAX_INTERVAL has passed
 *   since the last collection, as long as there is anything to collect, and not before: until
 *   then the garbage stays in memory, however much of it there is.
 *
 * These tests reset the driver's GC bookkeeping first, via reflection, so the result does not
 * depend on what earlier tests left behind in the process-wide driver singleton.
 */

/** A cycle only the collector frees: unreferenced once this returns. */
function makeGcCycle(): void
{
    $a    = new stdClass();
    $b    = new stdClass();
    $a->b = $b;
    $b->a = $a;
}

function resetEventLoopGcState(): void
{
    $driver = (new ReflectionMethod('phasync', 'getDriver'))->invoke(null);
    (new ReflectionProperty($driver, 'lastGarbageCollect'))->setValue($driver, \microtime(true));
    (new ReflectionProperty($driver, 'lastIdleCollect'))->setValue($driver, 0.0);
    (new ReflectionProperty($driver, 'lastGarbageCheck'))->setValue($driver, 0.0);
}

beforeEach(function () {
    resetEventLoopGcState();
});

test('a loop that goes idle collects cyclic garbage before its wait returns', function () {
    [$before, $after] = phasync::run(function () {
        phasync::go(function () {
            makeGcCycle(); // ends at once: the cycle is unreferenced when go() returns
        });
        $before = \gc_status();
        phasync::sleep(0.05); // nothing else runnable: the loop waits in the poller

        return [$before, \gc_status()];
    });

    expect($before['roots'])->toBeGreaterThan(0);
    expect($after['roots'])->toBeLessThan($before['roots']);
    expect($after['runs'])->toBeGreaterThan($before['runs']);
});

test('a loop with coroutines always runnable (timeout 0) does not collect', function () {
    [$before, $after] = phasync::run(function () {
        phasync::go(function () {
            makeGcCycle();
        });
        $before = \gc_status();
        $busy   = phasync::go(function () {
            for ($i = 0; $i < 100; ++$i) {
                phasync::sleep(0); // always runnable: the loop's wait is always timeout 0
            }
        });
        phasync::await($busy);

        return [$before, \gc_status()];
    });

    expect($before['roots'])->toBeGreaterThan(0);
    expect($after['runs'])->toBe($before['runs']);
    expect($after['roots'])->toBeGreaterThanOrEqual($before['roots']);
});

test('a loop that never idles keeps its garbage until GC_MAX_INTERVAL, however much it makes', function () {
    [$before, $after] = phasync::run(function () {
        $busy = phasync::go(function () {
            $until = \microtime(true) + 0.2; // well inside EventLoop::GC_MAX_INTERVAL (0.5 s)
            while (\microtime(true) < $until) {
                for ($i = 0; $i < 2000; ++$i) {
                    makeGcCycle();
                }
                phasync::sleep(0); // always runnable: the loop never idles
            }
        });
        $before = \gc_status(); // go() ran the first batch already
        phasync::await($busy);

        return [$before, \gc_status()];
    });

    expect($before['roots'])->toBeGreaterThan(0);
    expect($after['runs'])->toBe($before['runs']);
    expect($after['roots'])->toBeGreaterThan(40_000); // far past PHP's own threshold
});

test('a loop kept busy for longer than the time limit still collects', function () {
    [$before, $after] = phasync::run(function () {
        phasync::go(function () {
            makeGcCycle(); // only time forces this collection
        });
        $before = \gc_status();
        $busy   = phasync::go(function () {
            $until = \microtime(true) + 0.65; // past EventLoop::GC_MAX_INTERVAL (0.5 s)
            while (\microtime(true) < $until) {
                phasync::sleep(0); // always runnable: the loop never idles
            }
        });
        phasync::await($busy);

        return [$before, \gc_status()];
    });

    expect($before['roots'])->toBeGreaterThan(0);
    expect($after['runs'])->toBeGreaterThan($before['runs']);
});
