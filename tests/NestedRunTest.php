<?php

/*
 * A run() inside a coroutine runs $fn in that coroutine, in a frame nested in its own. Ported
 * from the withContext() tests: a nested run() is what withContext() was, with a frame instead
 * of a context.
 */

beforeEach(fn () => phasyncResetProcessState());
afterEach(fn () => phasyncResetProcessState());

test('finally() inside a nested run() runs as $fn returns, before the caller goes on: in the same coroutine and frame, last registered first', function () {
    expect(phasync::run(function () {
        $log   = [];
        $fiber = Fiber::getCurrent();
        phasync::run(function () use (&$log, $fiber) {
            $frame = phasync::frame();
            foreach ([1, 2] as $n) {
                phasync::finally(function () use (&$log, $n, $fiber, $frame) {
                    $log[] = "f$n " . (Fiber::getCurrent() === $fiber ? 'same coroutine' : 'other coroutine') . ', ' . (phasync::frame() === $frame ? 'its frame' : 'other frame');
                });
            }
            $log[] = 'body';
        });
        $log[] = 'caller';

        return $log;
    }))->toBe(['body', 'f2 same coroutine, its frame', 'f1 same coroutine, its frame', 'caller']);
});

test('finally() inside a nested run() may suspend, and runs also when $fn throws', function () {
    expect(phasync::run(function () {
        $log = [];
        try {
            phasync::run(function () use (&$log) {
                phasync::finally(function () use (&$log) {
                    phasync::sleep(0.01);
                    $log[] = 'after a wait';
                });
                throw new RuntimeException('failed');
            });
        } catch (RuntimeException $e) {
            $log[] = 'caller caught ' . $e->getMessage();
        }

        return $log;
    }))->toBe(['after a wait', 'caller caught failed']);
});

test('finally() in a coroutine started inside a nested run() still runs when that coroutine ends, before the run returns', function () {
    expect(phasync::run(function () {
        $log = [];
        phasync::run(function () use (&$log) {
            phasync::go(function () use (&$log) {
                phasync::finally(function () use (&$log) {
                    $log[] = 'coroutine finally';
                });
                phasync::sleep(0.02);
            });
            phasync::finally(function () use (&$log) {
                $log[] = 'run finally';
            });
        });
        $log[] = 'caller';

        return $log;
    }))->toBe(['run finally', 'coroutine finally', 'caller']);
});

test('finally() in nested runs runs as each returns', function () {
    expect(phasync::run(function () {
        $log = [];
        phasync::run(function () use (&$log) {
            phasync::finally(function () use (&$log) {
                $log[] = 'outer finally';
            });
            phasync::run(function () use (&$log) {
                phasync::finally(function () use (&$log) {
                    $log[] = 'inner finally';
                });
            });
            $log[] = 'between';
        });

        return $log;
    }))->toBe(['inner finally', 'between', 'outer finally']);
});

test('a nested run() whose $fn throws waits for its coroutines (it does not cancel them), then rethrows', function () {
    $log = [];
    phasync::run(function () use (&$log) {
        try {
            phasync::run(function () use (&$log) {
                phasync::go(function () use (&$log) {
                    phasync::sleep(0.02);
                    $log[] = 'child finished';
                });
                throw new RuntimeException('failed');
            });
        } catch (RuntimeException $e) {
            $log[] = 'caught ' . $e->getMessage();
        }
    });
    expect($log)->toBe(['child finished', 'caught failed']);
});

test('a nested run() cancelled while it waits cancels what it started, waits for it to unwind, and rethrows the cancellation', function () {
    $log = [];
    phasync::run(function () use (&$log) {
        $caller = phasync::go(function () use (&$log) {
            try {
                phasync::run(function () use (&$log) {
                    phasync::go(function () use (&$log) {
                        try {
                            phasync::sleep(1);
                        } finally {
                            phasync::shielded(fn () => phasync::sleep(0.01));
                            $log[] = 'child unwound';
                        }
                    });
                });
            } catch (phasync\CancelledException) {
                $log[] = 'caller cancelled';
            }
        });
        phasync::sleep(0.01);
        phasync::cancel($caller);
    });
    expect($log)->toBe(['child unwound', 'caller cancelled']);
});

test('the frame of a nested run() counts the calling coroutine only while $fn runs', function () {
    phasync::run(function () {
        $outer = phasync::frame();
        $inner = phasync::run(function () {
            return [phasync::frame(), \count(phasync::getLoop()->getFibers(phasync::frame()))];
        });
        expect($inner[1])->toBe(1);
        expect(phasync::getLoop()->getFibers($inner[0]))->toBe([]);
        expect(phasync::frame())->toBe($outer);
    });
});

test('onScopeChange() keeps a value of its own per scope across interleaved coroutines, as a server swaps per-request state', function () {
    $values  = new WeakMap();
    $current = null;
    phasync::onScopeChange(function (Scope $new, ?Scope $old) use ($values, &$current) {
        if (null !== $old) {
            $values[$old] = $current;
        }
        $current = $values[$new] ?? null;
    });
    $out = [];
    phasync::run(function () use (&$out, &$current) {
        foreach (['a', 'b', 'c'] as $name) {
            (new Scope())->run(function () use ($name, &$out, &$current) {
                phasync::go(function () use ($name, &$out, &$current) {
                    $out[]   = "$name start " . ($current ?? 'unset');
                    $current = "$name changed";
                    phasync::sleep(0.01);
                    $out[] = "$name end $current";
                });
            });
        }
        phasync::sleep(0.05);
    });
    expect($out)->toBe([
        'a start unset', 'b start unset', 'c start unset',
        'a end a changed', 'b end b changed', 'c end c changed',
    ]);
});
