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
