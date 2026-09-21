<?php

/*
 * Characterization tests for flags: phasync::raiseFlag() / phasync::awaitFlag() (FLG-1).
 * They pin today's behaviour. See tests/Characterization/README.md before editing.
 * A failing test here means: stop and tell the maintainer.
 */

use phasync\CancelledException;
use phasync\TimeoutException;

uses()->group('characterization');

test('FLG-1: raiseFlag() with no waiters returns 0, inside and outside a coroutine', function () {
    expect(phasync::raiseFlag(new stdClass()))->toBe(0);
    expect(phasync::run(fn () => phasync::raiseFlag(new stdClass())))->toBe(0);
});

test('FLG-1: awaitFlag() outside a coroutine throws LogicException', function () {
    $out = null;
    try {
        phasync::awaitFlag(new stdClass(), 0.01);
    } catch (Throwable $e) {
        $out = [\get_class($e), $e->getMessage()];
    }

    expect($out)->toBe([LogicException::class, 'Can only await flags from within a coroutine']);
});

test('FLG-1: raiseFlag() wakes every waiter, in the order they started waiting, and returns how many', function () {
    [$count, $log] = phasync::run(function () {
        $flag  = new stdClass();
        $log   = [];
        $count = null;
        $cs    = [];
        foreach ([1, 2, 3] as $i) {
            $cs[] = phasync::go(function () use ($flag, $i, &$log) {
                phasync::awaitFlag($flag);
                $log[] = "waiter $i";
            });
        }
        $cs[] = phasync::go(function () use ($flag, &$count) {
            phasync::sleep(0.01);
            $count = phasync::raiseFlag($flag);
        });
        foreach ($cs as $c) {
            phasync::await($c);
        }

        return [$count, $log];
    });

    expect($count)->toBe(3);
    expect($log)->toBe(['waiter 1', 'waiter 2', 'waiter 3']);
});

test('FLG-1: a raise only wakes the coroutines waiting at that moment, a second raise wakes nobody', function () {
    $out = phasync::run(function () {
        $flag   = new stdClass();
        $out    = [];
        $waiter = phasync::go(function () use ($flag, &$out) {
            phasync::awaitFlag($flag);
            $out[] = 'woke';
        });
        phasync::sleep(0.01);
        $out[] = phasync::raiseFlag($flag);
        phasync::await($waiter);
        $out[] = phasync::raiseFlag($flag);

        return $out;
    });

    expect($out)->toBe([1, 'woke', 0]);
});

test('FLG-1: a flag raised before anyone waits is lost, so a later awaitFlag() times out', function () {
    $out = phasync::run(function () {
        $flag = new stdClass();
        phasync::raiseFlag($flag);
        $c = phasync::go(function () use ($flag) {
            try {
                phasync::awaitFlag($flag, 0.05);

                return 'woke';
            } catch (Throwable $e) {
                return \get_class($e);
            }
        });

        return phasync::await($c);
    });

    expect($out)->toBe(TimeoutException::class);
});

test('FLG-1: awaitFlag() with a timeout throws TimeoutException after at least the timeout', function () {
    [$class, $elapsed] = phasync::run(function () {
        $flag = new stdClass();
        $c    = phasync::go(function () use ($flag) {
            $t = \microtime(true);
            try {
                phasync::awaitFlag($flag, 0.05);
            } catch (Throwable $e) {
                return [\get_class($e), \microtime(true) - $t];
            }
        });

        return phasync::await($c);
    });

    expect($class)->toBe(TimeoutException::class);
    // Timeouts never fire early; they may fire late (see TMO-3)
    expect($elapsed)->toBeGreaterThanOrEqual(0.049)->toBeLessThan(3.0);
});

test('FLG-1: one coroutine can wait on several flags one after another', function () {
    $out = phasync::run(function () {
        $a   = new stdClass();
        $b   = new stdClass();
        $log = [];
        $c   = phasync::go(function () use ($a, $b, &$log) {
            phasync::awaitFlag($a);
            $log[] = 'a';
            phasync::awaitFlag($b);
            $log[] = 'b';
        });
        phasync::sleep(0.01);
        phasync::raiseFlag($a);
        phasync::sleep(0.01);
        phasync::raiseFlag($b);
        phasync::await($c);

        return $log;
    });

    expect($out)->toBe(['a', 'b']);
});

test('FLG-1: a cancelled waiter is not counted by raiseFlag() and does not consume the raise', function () {
    $log = phasync::run(function () {
        $flag = new stdClass();
        $log  = [];
        $a    = phasync::go(function () use ($flag, &$log) {
            try {
                phasync::awaitFlag($flag);
            } catch (Throwable $e) {
                $log[] = 'a ' . \get_class($e);
            }
        });
        $b = phasync::go(function () use ($flag, &$log) {
            phasync::awaitFlag($flag);
            $log[] = 'b woke';
        });
        phasync::sleep(0.01);
        phasync::cancel($a);
        phasync::sleep(0.01);
        $log[] = 'raised ' . phasync::raiseFlag($flag);
        phasync::await($a);
        phasync::await($b);
        \sort($log);

        return $log;
    });

    expect($log)->toBe(['a ' . CancelledException::class, 'b woke', 'raised 1']);
});

test('FLG-1: a fiber can be used as a flag and its waiters wake when it terminates', function () {
    $out = phasync::run(function () {
        $fiber  = phasync::go(function () {
            phasync::sleep(0.02);

            return 1;
        });
        $waiter = phasync::go(function () use ($fiber) {
            phasync::awaitFlag($fiber);

            return 'woke when the fiber ended';
        });

        return phasync::await($waiter);
    });

    expect($out)->toBe('woke when the fiber ended');
});

// ---------------------------------------------------------------------------
// FLG-1: garbage collection protection. Flags are the fundamental primitive: when the last
// reference to a flag goes away nothing can raise it, so its waiters are woken with a
// CancelledException. These tests replace the earlier divergence test that pinned the OLD
// behaviour (waiters were never woken). The change was approved by the maintainer.
// Waiting on a flag that nothing else references (awaitFlag(new stdClass())) is undefined
// behaviour and deliberately not tested.
// ---------------------------------------------------------------------------

test('FLG-1: a waiting coroutine does not keep its flag alive', function () {
    $alive = phasync::run(function () {
        $owner       = new stdClass();
        $owner->flag = new stdClass();
        $ref         = WeakReference::create($owner->flag);
        $waiter      = phasync::go(function () use ($owner) {
            try {
                phasync::awaitFlag($owner->flag);

                return 'returned normally';
            } catch (CancelledException) {
                return 'cancelled';
            }
        });
        phasync::sleep(0.02);
        $whileWaiting = null !== $ref->get();
        $owner->flag  = null;
        $afterDrop    = null !== $ref->get();

        return [$whileWaiting, $afterDrop, phasync::await($waiter)];
    });

    expect($alive)->toBe([true, false, 'cancelled']);
});

test('FLG-1: when the owner drops a flag, a waiting coroutine is woken with CancelledException', function () {
    [$log, $seconds] = phasync::run(function () {
        $owner       = new stdClass();
        $owner->flag = new stdClass();
        $log         = [];
        $waiter      = phasync::go(function () use ($owner, &$log) {
            try {
                phasync::awaitFlag($owner->flag);
                $log[] = 'returned normally';
            } catch (Throwable $e) {
                $log[] = [\get_class($e), $e->getMessage()];
            }
        });
        phasync::sleep(0.05);
        $dropped      = \microtime(true);
        $owner->flag  = null;
        phasync::await($waiter);

        return [$log, \microtime(true) - $dropped];
    });

    expect($log)->toBe([[CancelledException::class, 'The flag no longer exists']]);
    expect($seconds)->toBeLessThan(0.3);
});

test('FLG-1: dropping a flag wakes every coroutine waiting on it', function () {
    $log = phasync::run(function () {
        $owner       = new stdClass();
        $owner->flag = new stdClass();
        $log         = [];
        $waiters     = [];
        for ($i = 0; $i < 3; ++$i) {
            $waiters[] = phasync::go(function () use ($owner, $i, &$log) {
                try {
                    phasync::awaitFlag($owner->flag);
                } catch (CancelledException $e) {
                    $log[] = $i . ': ' . $e->getMessage();
                }
            });
        }
        phasync::sleep(0.02);
        $owner->flag = null;
        foreach ($waiters as $waiter) {
            phasync::await($waiter);
        }

        return $log;
    });

    expect($log)->toBe(['0: The flag no longer exists', '1: The flag no longer exists', '2: The flag no longer exists']);
});

test('FLG-1: the protection also works when the flag store is reused from the pool', function () {
    $results = phasync::run(function () {
        $results = [];
        for ($round = 0; $round < 3; ++$round) {
            // A normal raise returns the emptied store to the pool ...
            $flag   = new stdClass();
            $waiter = phasync::go(function () use ($flag) {
                phasync::awaitFlag($flag);
            });
            phasync::sleep(0.01);
            phasync::raiseFlag($flag);
            phasync::await($waiter);
            unset($flag, $waiter);

            // ... and the next wait reuses it, and must still be woken when the owner drops the flag.
            $owner       = new stdClass();
            $owner->flag = new stdClass();
            $waiter      = phasync::go(function () use ($owner) {
                try {
                    phasync::awaitFlag($owner->flag);

                    return 'returned normally';
                } catch (CancelledException $e) {
                    return $e->getMessage();
                }
            });
            phasync::sleep(0.01);
            $owner->flag = null;
            $results[]   = phasync::await($waiter);
            unset($owner, $waiter);
        }

        return $results;
    });

    expect($results)->toBe(\array_fill(0, 3, 'The flag no longer exists'));
});

test('FLG-1: a waiter on a flag that is still owned can be cancelled and times out normally', function () {
    $log = phasync::run(function () {
        $flag   = new stdClass();
        $waiter = phasync::go(function () use ($flag) {
            try {
                phasync::awaitFlag($flag);
            } catch (Throwable $e) {
                return \get_class($e);
            }
        });
        phasync::sleep(0.02);
        phasync::cancel($waiter);
        $log = [phasync::await($waiter)];
        try {
            phasync::awaitFlag($flag, 0.02);
        } catch (Throwable $e) {
            $log[] = \get_class($e);
        }

        return $log;
    });

    expect($log)->toBe([CancelledException::class, TimeoutException::class]);
});
