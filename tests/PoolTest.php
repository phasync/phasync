<?php

use phasync\CancelledException;
use phasync\TimeoutException;
use phasync\Util\Pool;

/** A pool of stdClass instances, numbered in the order they were made. */
function numbered_pool(int $size, ?int &$made = null): Pool
{
    $made = 0;

    return new Pool(static function () use (&$made) {
        $instance     = new stdClass();
        $instance->nr = ++$made;

        return $instance;
    }, $size);
}

test('instances are made when needed, up to the size, and reused after that', function () {
    phasync::run(function () {
        $pool = numbered_pool(2, $made);
        $a    = $pool->borrow();
        $pool->release($a);
        $b = $pool->borrow();
        expect([$made, $b])->toBe([1, $a]);
        $c = $pool->borrow();
        expect([$made, $c->nr, \count($pool)])->toBe([2, 2, 2]);
    });
});

test('never more instances out than the size, however many coroutines borrow', function () {
    $peak = phasync::run(function () {
        $pool   = numbered_pool(3, $made);
        $inUse  = 0;
        $peak   = 0;
        $fibers = [];
        for ($i = 0; $i < 50; ++$i) {
            $fibers[] = phasync::go(function () use ($pool, &$inUse, &$peak) {
                $pool->use(function () use (&$inUse, &$peak) {
                    $peak = \max($peak, ++$inUse);
                    phasync::sleep(0.001 * \random_int(1, 5));
                    --$inUse;
                });
            });
        }
        foreach ($fibers as $f) {
            phasync::await($f);
        }
        expect($made)->toBe(3);

        return $peak;
    });
    expect($peak)->toBe(3);
});

test('borrowers waiting are served in the order they came', function () {
    $order = phasync::run(function () {
        $pool  = numbered_pool(1);
        $held  = $pool->borrow();
        $order = [];
        foreach (['first', 'second', 'third'] as $name) {
            phasync::go(function () use ($pool, $name, &$order) {
                $pool->use(function () use ($name, &$order) {
                    $order[] = $name;
                    phasync::sleep(0.001);
                });
            });
        }
        phasync::sleep(0.01);
        $pool->release($held);
        phasync::sleep(0.05);

        return $order;
    });
    expect($order)->toBe(['first', 'second', 'third']);
});

test('a borrower that times out leaves the line; the instance goes to the next', function () {
    $log = phasync::run(function () {
        $pool = numbered_pool(1);
        $held = $pool->borrow();
        $log  = [];
        phasync::go(function () use ($pool, &$log) {
            try {
                $pool->borrow(0.05);
            } catch (TimeoutException) {
                $log[] = 'timed out';
            }
        });
        phasync::go(function () use ($pool, &$log) {
            $log[] = 'got ' . $pool->borrow(1)->nr;
        });
        phasync::sleep(0.1);
        $pool->release($held);
        phasync::sleep(0.01);

        return $log;
    });
    expect($log)->toBe(['timed out', 'got 1']);
});

test('a waiting borrower that is cancelled leaves the line', function () {
    $log = phasync::run(function () {
        $pool   = numbered_pool(1);
        $held   = $pool->borrow();
        $log    = [];
        $waiter = phasync::go(function () use ($pool, &$log) {
            try {
                $pool->borrow();
            } catch (CancelledException) {
                $log[] = 'cancelled';
            }
        });
        phasync::go(function () use ($pool, &$log) {
            $log[] = 'got ' . $pool->borrow(1)->nr;
        });
        phasync::sleep(0.01);
        phasync::cancel($waiter);
        phasync::sleep(0.01);
        $pool->release($held);
        phasync::sleep(0.01);

        return $log;
    });
    expect($log)->toBe(['cancelled', 'got 1']);
});

test('use() releases the instance also when the function throws', function () {
    phasync::run(function () {
        $pool = numbered_pool(1);
        expect(fn () => $pool->use(fn () => throw new RuntimeException('oops')))->toThrow(RuntimeException::class);
        expect($pool->borrow(0.01)->nr)->toBe(1);
    });
});

test('a discarded instance is forgotten; the next borrower gets a new one', function () {
    phasync::run(function () {
        $pool = numbered_pool(1, $made);
        $a    = $pool->borrow();
        $next = phasync::go(fn () => $pool->borrow(1)->nr);
        phasync::sleep(0.01);
        $pool->discard($a);
        expect([phasync::await($next), $made])->toBe([2, 2]);
    });
});

test('a lost instance is noticed: a warning, and a new one in its place for a waiting borrower', function () {
    $warnings = [];
    \set_error_handler(function (int $level, string $message) use (&$warnings) {
        $warnings[] = $message;

        return true;
    }, \E_USER_WARNING);
    try {
        $got = phasync::run(function () {
            $pool = numbered_pool(1);
            phasync::go(function () use ($pool) {
                $pool->borrow(); // dropped without release()
            });
            $start = \microtime(true);
            $nr    = $pool->borrow(3)->nr;

            return [$nr, \microtime(true) - $start < 1.5];
        });
    } finally {
        \restore_error_handler();
    }
    expect($got)->toBe([2, true]);
    expect($warnings)->toHaveCount(1);
    expect($warnings[0])->toContain('destroyed without release() or discard()');
});

test('an instance that failed to be made frees its place', function () {
    phasync::run(function () {
        $fail = true;
        $pool = new Pool(function () use (&$fail) {
            if ($fail) {
                $fail = false;
                throw new RuntimeException('connection refused');
            }

            return new stdClass();
        }, 1);
        expect(fn () => $pool->borrow())->toThrow(RuntimeException::class);
        expect($pool->borrow(0.1))->toBeInstanceOf(stdClass::class);
    });
});

test('releasing what the pool did not lend is an error', function () {
    phasync::run(function () {
        $pool = numbered_pool(1);
        expect(fn () => $pool->release(new stdClass()))->toThrow(LogicException::class);
        $a = $pool->borrow();
        $pool->release($a);
        expect(fn () => $pool->release($a))->toThrow(LogicException::class);
    });
});

// ---------------------------------------------------------------------------
// window and dispose
// ---------------------------------------------------------------------------

/** A pool of numbered instances that shrinks to the peak lent over $window seconds, recording what was disposed. */
function shrinking_pool(int $size, ?float $window, ?int &$made = null, ?array &$disposed = null): Pool
{
    $made     = 0;
    $disposed = [];

    return new Pool(static function () use (&$made) {
        $instance     = new stdClass();
        $instance->nr = ++$made;

        return $instance;
    }, $size, $window, static function (stdClass $instance) use (&$disposed) {
        $disposed[] = $instance->nr;
    });
}

test('after a burst the idle instances are dropped once the window has passed, at the next borrow', function () {
    phasync::run(function () {
        $pool = shrinking_pool(3, 0.3, $made, $disposed);
        $a    = $pool->borrow();
        $b    = $pool->borrow();
        $c    = $pool->borrow();
        $pool->release($a);
        $pool->release($b);
        $pool->release($c);
        phasync::sleep(0.2);
        $pool->release($pool->borrow()); // the burst of 3 is within the window
        expect([\count($pool), $disposed])->toBe([3, []]);
        phasync::sleep(0.2); // 0.4 s since the burst: the peak since then is 1
        $d = $pool->borrow();
        expect([\count($pool), $disposed, $d->nr])->toBe([1, [1, 2], 3]);
    });
});

test('nothing is dropped without a borrow or release, however long the pool is idle', function () {
    phasync::run(function () {
        $pool = shrinking_pool(2, 0.1, $made, $disposed);
        $a    = $pool->borrow();
        $b    = $pool->borrow();
        $pool->release($a);
        $pool->release($b);
        phasync::sleep(0.3);
        expect([\count($pool), $disposed])->toBe([2, []]);
    });
});

test('the pool shrinks to the peak of the window, not to what is lent now', function () {
    phasync::run(function () {
        $pool = shrinking_pool(3, 0.4, $made, $disposed);
        $all  = [$pool->borrow(), $pool->borrow(), $pool->borrow()];
        foreach ($all as $instance) {
            $pool->release($instance);
        }
        phasync::sleep(0.25);
        $a = $pool->borrow();
        $b = $pool->borrow(); // 2 lent, 0.25 s after the burst of 3
        $pool->release($a);
        $pool->release($b);
        expect($disposed)->toBe([]);
        phasync::sleep(0.25); // the burst is 0.5 s old, the 2 are 0.25 s old
        $c = $pool->borrow();
        expect([\count($pool), $disposed])->toBe([2, [1]]);
        $pool->release($c);
        expect($disposed)->toBe([1]);
    });
});

test('an instance lent for longer than the window is not dropped when it is released', function () {
    phasync::run(function () {
        $pool = shrinking_pool(1, 0.2, $made, $disposed);
        $a    = $pool->borrow();
        phasync::sleep(0.4);
        $pool->release($a);
        expect([\count($pool), $disposed])->toBe([1, []]);
        expect($pool->borrow())->toBe($a);
    });
});

test('release() drops idle instances too, the least recently used first', function () {
    phasync::run(function () {
        $pool = shrinking_pool(2, 0.3, $made, $disposed);
        $a    = $pool->borrow();
        $b    = $pool->borrow();
        $pool->release($a);
        phasync::sleep(0.5);
        $pool->release($b); // 1 lent for the last 0.5 s
        expect([\count($pool), $disposed])->toBe([1, [1]]);
        expect($pool->borrow())->toBe($b);
    });
});

test('a pool never drops its only instance while it is used within the window', function () {
    phasync::run(function () {
        $pool = shrinking_pool(1, 0.2, $made, $disposed);
        for ($i = 0; $i < 4; ++$i) {
            $pool->use(fn () => phasync::sleep(0.1));
        }
        expect([$made, $disposed])->toBe([1, []]);
    });
});

test('a pool that was idle between two runs shrinks at its first borrow in the next', function () {
    $pool = null;
    phasync::run(function () use (&$pool, &$disposed) {
        $pool = shrinking_pool(2, 0.1, $made, $disposed);
        $a    = $pool->borrow();
        $b    = $pool->borrow();
        $pool->release($a);
        $pool->release($b);
    });
    \usleep(150_000);
    $nr = phasync::run(fn () => $pool->borrow()->nr);
    expect([$nr, $disposed])->toBe([2, [1]]);
});

test('without a window idle instances are never dropped, and dispose is not used for release', function () {
    phasync::run(function () {
        $disposed = [];
        $pool     = new Pool(fn () => new stdClass(), 1, null, function () use (&$disposed) {
            $disposed[] = true;
        });
        $a = $pool->borrow();
        $pool->release($a);
        phasync::sleep(0.05);
        expect([$pool->borrow(), $disposed])->toBe([$a, []]);
    });
});

test('discard() disposes the instance, after the pool has forgotten it', function () {
    phasync::run(function () {
        $pool = shrinking_pool(1, 30, $made, $disposed);
        $a    = $pool->borrow();
        $pool->discard($a);
        expect([$disposed, \count($pool)])->toBe([[1], 0]);
        expect($pool->borrow()->nr)->toBe(2);
    });
});

test('a window must be above 0', function () {
    expect(fn () => new Pool(fn () => new stdClass(), 1, 0.0))->toThrow(InvalidArgumentException::class);
});
