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
// idleTimeout and dispose
// ---------------------------------------------------------------------------

/** A pool of numbered instances with an idle timeout, recording what was disposed. */
function expiring_pool(int $size, float $idleTimeout, ?int &$made = null, ?array &$disposed = null): Pool
{
    $made     = 0;
    $disposed = [];

    return new Pool(static function () use (&$made) {
        $instance     = new stdClass();
        $instance->nr = ++$made;

        return $instance;
    }, $size, $idleTimeout, static function (stdClass $instance) use (&$disposed) {
        $disposed[] = $instance->nr;
    });
}

test('idle instances unused for longer than the idle timeout are dropped and disposed, without traffic', function () {
    phasync::run(function () {
        $pool = expiring_pool(3, 0.05, $made, $disposed);
        $a    = $pool->borrow();
        $b    = $pool->borrow();
        $pool->release($a);
        $pool->release($b);
        expect([\count($pool), $disposed])->toBe([2, []]);
        phasync::sleep(0.2);
        expect([\count($pool), $disposed])->toBe([0, [1, 2]]);
        expect($pool->borrow()->nr)->toBe(3);
    });
});

test('a borrowed instance is never dropped, and its idle time starts when it is released', function () {
    phasync::run(function () {
        $pool = expiring_pool(1, 0.1, $made, $disposed);
        $a    = $pool->borrow();
        phasync::sleep(0.25); // out for longer than the timeout
        expect([\count($pool), $disposed])->toBe([1, []]);
        $pool->release($a);
        phasync::sleep(0.05);
        expect($disposed)->toBe([]);
        phasync::sleep(0.2);
        expect($disposed)->toBe([1]);
    });
});

test('an instance that is borrowed again in time stays', function () {
    phasync::run(function () {
        $pool = expiring_pool(1, 0.2, $made, $disposed);
        $pool->release($pool->borrow());
        phasync::sleep(0.1);
        $pool->release($pool->borrow());
        phasync::sleep(0.1); // 0.2 s since the first release, 0.1 s since the second
        $pool->release($pool->borrow());
        expect([$made, $disposed])->toBe([1, []]);
    });
});

test('the sweeper does not keep run() waiting, and idle instances stay for the next run', function () {
    $pool  = null;
    $start = \microtime(true);
    phasync::run(function () use (&$pool) {
        $pool = expiring_pool(1, 30, $made, $disposed);
        $pool->release($pool->borrow());
        phasync::sleep(0.01);
    });
    expect(\microtime(true) - $start)->toBeLessThan(1.0);
    expect(\count($pool))->toBe(1);
    expect(phasync::run(fn () => $pool->borrow()->nr))->toBe(1);
});

test('an instance that outlived its timeout between runs is disposed, not lent', function () {
    $pool = null;
    phasync::run(function () use (&$pool, &$disposed) {
        $pool = expiring_pool(1, 0.05, $made, $disposed);
        $pool->release($pool->borrow());
    });
    \usleep(100_000);
    $nr = phasync::run(fn () => $pool->borrow()->nr);
    expect([$nr, $disposed])->toBe([2, [1]]);
});

test('the sweeper ends when no idle instance is left, and starts again when one comes back', function () {
    phasync::run(function () {
        $pool    = expiring_pool(1, 0.05);
        $sweeper = static fn () => (new ReflectionProperty($pool, 'sweeper'))->getValue($pool);
        expect($sweeper())->toBeNull();
        $pool->release($pool->borrow());
        $first = $sweeper();
        expect($first->isTerminated())->toBeFalse();
        phasync::sleep(0.2);
        expect($first->isTerminated())->toBeTrue();
        $pool->release($pool->borrow());
        expect($sweeper())->not->toBe($first);
        expect($sweeper()->isTerminated())->toBeFalse();
    });
});

test('a pool that is dropped is freed, and its sweeper ends', function () {
    phasync::run(function () {
        $pool = expiring_pool(1, 30);
        $pool->release($pool->borrow());
        $ref = WeakReference::create($pool);
        unset($pool);
        expect($ref->get())->toBeNull();
    });
});

test('without an idle timeout idle instances are never dropped, and dispose is not used for release', function () {
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
        $pool = expiring_pool(1, 30, $made, $disposed);
        $a    = $pool->borrow();
        $pool->discard($a);
        expect([$disposed, \count($pool)])->toBe([[1], 0]);
        expect($pool->borrow()->nr)->toBe(2);
    });
});

test('an idle timeout must be above 0', function () {
    expect(fn () => new Pool(fn () => new stdClass(), 1, 0.0))->toThrow(InvalidArgumentException::class);
});

// ---------------------------------------------------------------------------
// warm()
// ---------------------------------------------------------------------------

test('warm() makes an instance in the background and keeps it idle', function () {
    phasync::run(function () {
        $pool = numbered_pool(2, $made);
        $pool->warm();
        expect($made)->toBe(0); // $create runs in a coroutine of its own, not in the caller's stack
        phasync::sleep(0.01);
        expect(\count($pool))->toBe(1);
        $a = $pool->borrow();
        expect([$made, $a->nr])->toBe([1, 1]);
    });
});

test('warm() does not make the caller wait for a slow create', function () {
    phasync::run(function () {
        $made = 0;
        $pool = new Pool(function () use (&$made) {
            phasync::sleep(0.05);

            return (object) ['nr' => ++$made];
        }, 1);
        $start = \microtime(true);
        $pool->warm();
        expect(\microtime(true) - $start)->toBeLessThan(0.04);
        $a = $pool->borrow(); // waits for the instance being made, which goes to it
        expect([$made, $a->nr])->toBe([1, 1]);
    });
});

test('warm() makes nothing when the pool is at its size, counting instances being made', function () {
    phasync::run(function () {
        $pool = numbered_pool(2, $made);
        $a    = $pool->borrow();
        $pool->warm();
        $pool->warm(); // the second is being made, or is idle: the pool is full
        $pool->warm();
        phasync::sleep(0.01);
        expect([$made, \count($pool)])->toBe([2, 2]);
        $pool->release($a);
        $pool->warm();
        phasync::sleep(0.01);
        expect($made)->toBe(2);
    });
});

test('warm() makes nothing while a borrower waits', function () {
    phasync::run(function () {
        $pool = numbered_pool(1, $made);
        $a    = $pool->borrow();
        $next = phasync::go(fn () => $pool->borrow(1)->nr);
        phasync::sleep(0.01);
        $pool->discard($a); // a place is free, and the borrower in line has not yet taken it
        $pool->warm();
        expect(phasync::await($next))->toBe(2);
        expect($made)->toBe(2);
    });
});

test('warm() returns at once, also when create never suspends and takes long', function () {
    phasync::run(function () {
        $pool  = new Pool(function () {
            \usleep(30000);

            return new stdClass();
        }, 1);
        $start = \microtime(true);
        $pool->warm();
        expect(\microtime(true) - $start)->toBeLessThan(0.02);
        expect(\count($pool))->toBe(1); // the place is taken at once
        $pool->warm(); // so a second does not start another
        phasync::sleep(0.01);
        expect($pool->borrow(1))->toBeInstanceOf(stdClass::class);
    });
});

test('warm() that is cancelled before it ran frees the place', function () {
    $pool = null;
    try {
        phasync::run(function () use (&$pool) {
            $pool = new Pool(fn () => new stdClass(), 1);
            $pool->warm();
            throw new RuntimeException('stop');
        });
    } catch (RuntimeException) {
    }
    expect(\count($pool))->toBe(0);
});

test('warm() that fails passes the exception to $onFailure, and frees the place', function () {
    $failures = [];
    phasync::run(function () use (&$failures) {
        $fail = true;
        $pool = new Pool(function () use (&$fail) {
            if ($fail) {
                $fail = false;
                throw new RuntimeException('connection refused');
            }

            return new stdClass();
        }, 1);
        $pool->warm(function (Throwable $e) use (&$failures) {
            $failures[] = $e->getMessage();
            // A framework may turn warnings into exceptions: nothing here is a warning
        });
        phasync::sleep(0.01);
        expect(\count($pool))->toBe(0);
        expect($pool->borrow(0.1))->toBeInstanceOf(stdClass::class);
    });
    expect($failures)->toBe(['connection refused']);
});

test('warm() that fails without $onFailure raises no warning and no exception; the next borrower meets the failure itself', function () {
    $warnings = 0;
    \set_error_handler(function () use (&$warnings) {
        ++$warnings;

        return true;
    });
    try {
        phasync::run(function () {
            $pool = new Pool(function () {
                throw new RuntimeException('connection refused');
            }, 1);
            $pool->warm();
            phasync::sleep(0.01);
            expect(\count($pool))->toBe(0);
            expect(fn () => $pool->borrow())->toThrow(RuntimeException::class, 'connection refused');
        });
    } finally {
        \restore_error_handler();
    }
    expect($warnings)->toBe(0);
});

test('a borrower waiting for a warm() that fails makes an instance itself', function () {
    $failures = 0;
    $nr       = phasync::run(function () use (&$failures) {
        $calls = 0;
        $pool  = new Pool(function () use (&$calls) {
            if (1 === ++$calls) {
                phasync::sleep(0.02);
                throw new RuntimeException('connection refused');
            }

            return (object) ['nr' => $calls];
        }, 1);
        $pool->warm(function () use (&$failures) { ++$failures; });

        return $pool->borrow(1)->nr;
    });
    expect([$nr, $failures])->toBe([2, 1]);
});

// ---------------------------------------------------------------------------
// idle() and lent()
// ---------------------------------------------------------------------------

test('idle() and lent() count instances ready and instances out, apart from those being made', function () {
    phasync::run(function () {
        $pool = new Pool(function () {
            phasync::sleep(0.02);

            return new stdClass();
        }, 3);
        expect([$pool->idle(), $pool->lent(), \count($pool)])->toBe([0, 0, 0]);
        $pool->warm();
        expect([$pool->idle(), $pool->lent(), \count($pool)])->toBe([0, 0, 1]); // being made: neither
        phasync::sleep(0.05);
        expect([$pool->idle(), $pool->lent(), \count($pool)])->toBe([1, 0, 1]);
        $a = $pool->borrow();
        $b = $pool->borrow();
        expect([$pool->idle(), $pool->lent(), \count($pool)])->toBe([0, 2, 2]);
        $pool->release($a);
        expect([$pool->idle(), $pool->lent(), \count($pool)])->toBe([1, 1, 2]);
        $pool->discard($b);
        expect([$pool->idle(), $pool->lent(), \count($pool)])->toBe([1, 0, 1]);
    });
});

test('idle() does not count instances that expired', function () {
    phasync::run(function () {
        $pool = new Pool(fn () => new stdClass(), 2, 0.01);
        $pool->release($pool->borrow());
        expect($pool->idle())->toBe(1);
        \usleep(20000);
        expect($pool->idle())->toBe(0);
    });
});

test('lent() does not count an instance that was lost, and warns', function () {
    \set_error_handler(fn () => true, \E_USER_WARNING);
    try {
        phasync::run(function () {
            $pool = new Pool(fn () => new stdClass(), 2);
            $pool->borrow();
            expect($pool->lent())->toBe(0);
        });
    } finally {
        \restore_error_handler();
    }
});
