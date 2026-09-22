<?php

/*
 * Characterization tests for phasync\Util\WaitGroup (WG-1).
 * They pin today's behaviour. See tests/Characterization/README.md before editing.
 * A failing test here means: stop and tell the maintainer.
 */

use phasync\CancelledException;
use phasync\SelectableInterface;
use phasync\TimeoutException;
use phasync\Util\WaitGroup;

uses()->group('characterization');

test('WG-1: done() without a matching add() throws LogicException', function () {
    $wg  = new WaitGroup();
    $out = null;
    try {
        $wg->done();
    } catch (Throwable $e) {
        $out = [\get_class($e), $e->getMessage()];
    }

    expect($out)->toBe([LogicException::class, 'Call WaitGroup::done() before calling WaitGroup::add()']);
});

test('WG-1: calling done() more often than add() throws LogicException', function () {
    $wg = new WaitGroup();
    $wg->add();
    $wg->done();
    expect(fn () => $wg->done())->toThrow(LogicException::class);
    expect($wg->isReady())->toBeTrue();
});

test('WG-1: isReady() is true exactly when the counter is zero', function () {
    $wg    = new WaitGroup();
    $out   = [$wg->isReady()];
    $wg->add();
    $out[] = $wg->isReady();
    $wg->add();
    $wg->done();
    $out[] = $wg->isReady();
    $wg->done();
    $out[] = $wg->isReady();

    expect($out)->toBe([true, false, false, true]);
});

// add($delta) replaced the earlier divergence test that pinned add(3) counting as one. Approved by
// the maintainer (D15): add() takes a delta like Go's WaitGroup.Add(delta).
test('WG-1: add($delta) adds that much work, like Go\'s Add(delta)', function () {
    $wg = new WaitGroup();
    $wg->add(3);
    $wg->done();
    $wg->done();
    expect($wg->isReady())->toBeFalse();
    $wg->done();
    expect($wg->isReady())->toBeTrue();
});

test('WG-1: a negative delta marks work as done and resumes the waiters when the counter reaches zero', function () {
    $log = phasync::run(function () {
        $wg  = new WaitGroup();
        $log = [];
        $wg->add(2);
        $waiter = phasync::go(function () use ($wg, &$log) {
            $wg->await();
            $log[] = 'woken';
        });
        phasync::sleep(0.02);
        $wg->add(-1);
        phasync::sleep(0.02);
        $log[] = 'after -1';
        $wg->add(-1);
        phasync::await($waiter);

        return $log;
    });

    expect($log)->toBe(['after -1', 'woken']);
});

test('WG-1: add() that would make the counter negative throws LogicException and changes nothing', function () {
    $wg = new WaitGroup();
    $wg->add();

    expect(static fn () => $wg->add(-2))->toThrow(LogicException::class);
    expect($wg->isReady())->toBeFalse();
    $wg->done();
    expect($wg->isReady())->toBeTrue();
});

test('WG-1: await() returns immediately when the counter is zero, inside and outside a coroutine', function () {
    $wg = new WaitGroup();
    $wg->await();
    $wg->await(0.01);

    $out = phasync::run(function () {
        $wg = new WaitGroup();
        $wg->await();

        return $wg->isReady();
    });

    expect($out)->toBeTrue();
});

test('WG-1: await() outside a coroutine while work is pending throws LogicException', function () {
    $wg = new WaitGroup();
    $wg->add();
    $out = null;
    try {
        $wg->await(0.01);
    } catch (Throwable $e) {
        $out = [\get_class($e), $e->getMessage()];
    }

    expect($out)->toBe([LogicException::class, 'Can only await flags from within a coroutine']);
});

test('WG-1: add() and done() work outside a coroutine', function () {
    $wg = new WaitGroup();
    $wg->add();
    $wg->done();

    expect($wg->isReady())->toBeTrue();
});

test('WG-1: all awaiting coroutines wake, in the order they started waiting, when the counter reaches zero', function () {
    $log = phasync::run(function () {
        $wg = new WaitGroup();
        $wg->add();
        $log = [];
        $cs  = [];
        foreach ([1, 2, 3] as $i) {
            $cs[] = phasync::go(function () use ($wg, $i, &$log) {
                $wg->await();
                $log[] = "waiter $i";
            });
        }
        $cs[] = phasync::go(function () use ($wg, &$log) {
            phasync::sleep(0.02);
            $log[] = 'done';
            $wg->done();
        });
        foreach ($cs as $c) {
            phasync::await($c);
        }

        return $log;
    });

    expect($log)->toBe(['done', 'waiter 1', 'waiter 2', 'waiter 3']);
});

test('WG-1: a WaitGroup can be reused after reaching zero', function () {
    $out = phasync::run(function () {
        $wg  = new WaitGroup();
        $out = [];
        for ($round = 0; $round < 2; ++$round) {
            $wg->add();
            phasync::go(function () use ($wg) {
                phasync::sleep(0.01);
                $wg->done();
            });
            $wg->await();
            $out[] = "round $round";
        }

        return $out;
    });

    expect($out)->toBe(['round 0', 'round 1']);
});

test('WG-1: await() with a timeout throws TimeoutException after at least the timeout', function () {
    [$class, $elapsed] = phasync::run(function () {
        $wg = new WaitGroup();
        $wg->add();
        $c = phasync::go(function () use ($wg) {
            $t = \microtime(true);
            try {
                $wg->await(0.05);
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

test('WG-1: cancelling one waiter throws CancelledException in it and the other waiters still wake', function () {
    $log = phasync::run(function () {
        $wg = new WaitGroup();
        $wg->add();
        $log = [];
        $a   = phasync::go(function () use ($wg, &$log) {
            try {
                $wg->await();
                $log[] = 'a woke';
            } catch (Throwable $e) {
                $log[] = 'a ' . \get_class($e);
            }
        });
        $b = phasync::go(function () use ($wg, &$log) {
            try {
                $wg->await();
                $log[] = 'b woke';
            } catch (Throwable $e) {
                $log[] = 'b ' . \get_class($e);
            }
        });
        phasync::sleep(0.01);
        phasync::cancel($a);
        phasync::sleep(0.01);
        $wg->done();
        phasync::await($a);
        phasync::await($b);
        \sort($log);

        return $log;
    });

    expect($log)->toBe(['a ' . CancelledException::class, 'b woke']);
});

test('WG-1: wait() is an alias of await() and phasync::waitGroup() returns a WaitGroup', function () {
    $out = phasync::run(function () {
        $wg = new WaitGroup();
        $wg->add();
        phasync::go(function () use ($wg) {
            phasync::sleep(0.01);
            $wg->done();
        });
        $wg->wait();

        return [$wg->isReady(), \get_class(phasync::waitGroup())];
    });

    expect($out)->toBe([true, WaitGroup::class]);
});

test('WG-1: a WaitGroup is a SelectableInterface, and await() returns once it is ready', function () {
    $out = phasync::run(function () {
        $wg = new WaitGroup();
        $wg->add();
        phasync::go(function () use ($wg) {
            phasync::sleep(0.01);
            $wg->done();
        });

        $wg->await(3.0);

        return [$wg instanceof SelectableInterface, $wg->isReady()];
    });

    expect($out)->toBe([true, true]);
});
