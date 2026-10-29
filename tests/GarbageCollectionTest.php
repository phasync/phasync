<?php

/*
 * EventLoop collects cyclic garbage in two ways (see EventLoop::tick()); PHP's own collector
 * is off inside run():
 * - idle-time: right before the poller would wait with a timeout > 0 (nothing runnable), if
 *   there are any possible cycles at all and a poll(0) finds no ready I/O.
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
    \gc_collect_cycles(); // garbage an earlier test left would make the first collection find some
    $driver = (new ReflectionMethod('phasync', 'getDriver'))->invoke(null);
    (new ReflectionProperty($driver, 'lastGarbageCollect'))->setValue($driver, \microtime(true));
    (new ReflectionProperty($driver, 'lastGarbageCheck'))->setValue($driver, 0.0);
}

/** Makes every object in $live a possible root of the collector, without making it garbage. */
function touchGcRoots(array $live): void
{
    foreach ($live as $o) {
        $copy = $o;
        unset($copy); // a refcount decrement that leaves it alive: a possible root
    }
}

/** Keeps the loop busy (never idle) for $seconds; returns gc_status() runs at the end. */
function busyFor(float $seconds, ?Closure $each = null): void
{
    $until = \microtime(true) + $seconds;
    while (\microtime(true) < $until) {
        $each && $each();
        phasync::sleep(0);
    }
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

test('every idle moment collects, however soon after the last one', function () {
    $runs = phasync::run(function () {
        $runs = [];
        for ($i = 0; $i < 3; ++$i) {
            phasync::go(fn () => makeGcCycle());
            $before = \gc_status()['runs'];
            phasync::sleep(0.02); // nothing runnable: an idle moment
            $runs[] = \gc_status()['runs'] - $before;
        }

        return $runs;
    });

    expect($runs)->toBe([1, 1, 1]);
});

test('a coroutine woken by a destructor of the garbage runs at once, not after the rest of the wait', function () {
    $waited = phasync::run(function () {
        $released = new stdClass();
        $holder   = new class($released) {
            public $self;

            public function __construct(public stdClass $flag)
            {
                $this->self = $this; // a cycle: only the collector frees it
            }

            public function __destruct()
            {
                phasync::raiseFlag($this->flag);
            }
        };
        unset($holder);
        $timer = phasync::go(fn () => phasync::sleep(0.5)); // the loop's wait would be 0.5 s
        $start = \microtime(true);
        phasync::awaitFlag($released);
        $waited = \microtime(true) - $start;
        phasync::cancel($timer);

        return $waited;
    });

    expect($waited)->toBeLessThan(0.05);
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

test('a busy loop collects once per GC_MAX_INTERVAL', function () {
    [$first, $second] = phasync::run(function () {
        $start = \gc_status()['runs'];
        $busy  = phasync::go(fn () => busyFor(0.65, fn () => makeGcCycle()));
        phasync::await($busy);
        $first = \gc_status()['runs'] - $start;
        $busy  = phasync::go(fn () => busyFor(0.65, fn () => makeGcCycle()));
        phasync::await($busy);

        return [$first, \gc_status()['runs'] - $start - $first];
    });

    expect($first)->toBe(1);
    expect($second)->toBe(1);
});

test('GC_MAX_INTERVAL counts from the end of a busy-loop collection, so a slow one is not followed by another at once (#104)', function () {
    $driver   = (new ReflectionMethod('phasync', 'getDriver'))->invoke(null);
    $interval = new ReflectionProperty($driver, 'gcMaxInterval');
    $was      = $interval->getValue($driver);
    $interval->setValue($driver, 0.02);
    try {
        $seen = phasync::run(function () {
            for ($i = 0; $i < 300000; ++$i) {
                makeGcCycle(); // the first collection frees these: it takes longer than 0.02 s
            }
            $runs = \gc_status()['runs'];
            $seen = [];
            $busy = phasync::go(function () use (&$runs, &$seen) {
                $until = \microtime(true) + 1.0;
                while (\count($seen) < 2 && \microtime(true) < $until) {
                    makeGcCycle(); // always something to collect
                    phasync::sleep(0);
                    if (\gc_status()['runs'] !== $runs) {
                        $runs   = \gc_status()['runs'];
                        $seen[] = \microtime(true); // right after the tick that collected
                    }
                }
            });
            phasync::await($busy);

            return $seen;
        });
    } finally {
        $interval->setValue($driver, $was);
    }
    expect($seen)->toHaveCount(2);
    expect($seen[1] - $seen[0])->toBeGreaterThanOrEqual(0.02);
});
