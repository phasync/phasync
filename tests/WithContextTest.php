<?php

/*
 * phasync::withContext(): a closure run in the current coroutine as a coroutine of a context.
 */

use phasync\CancelledException;
use phasync\ContextUsedException;

test('inside, getContext() is the given context, also after a suspension; afterwards the own one again', function () {
    expect(phasync::run(function () {
        $own     = phasync::getContext();
        $context = new stdClass();
        $seen    = phasync::withContext(function () use ($context) {
            $before = phasync::getContext() === $context;
            phasync::sleep(0.01);

            return [$before, phasync::getContext() === $context];
        }, $context);

        return [...$seen, phasync::getContext() === $own];
    }))->toBe([true, true, true]);
});

test('returns what the closure returns, and runs it in the calling coroutine', function () {
    expect(phasync::run(function () {
        $fiber = Fiber::getCurrent();

        return phasync::withContext(fn () => [Fiber::getCurrent() === $fiber, 42], new stdClass());
    }))->toBe([true, 42]);
});

test('coroutines started inside belong to the context and keep running after it returns', function () {
    expect(phasync::run(function () {
        $context = new stdClass();
        $child   = phasync::withContext(fn () => phasync::go(function () use ($context) {
            phasync::sleep(0.05);

            return phasync::getContext() === $context;
        }), $context);
        $running = !$child->isTerminated();

        return [$running, phasync::await($child)];
    }))->toBe([true, true]);
});

test('the context counts the calling coroutine only while the closure runs', function () {
    expect(phasync::run(function () {
        $context = new stdClass();
        $fiber   = Fiber::getCurrent();
        $inside  = phasync::withContext(fn () => \in_array($fiber, phasync::getLoop()->getFibers($context), true), $context);

        return [$inside, \in_array($fiber, phasync::getLoop()->getFibers($context), true)];
    }))->toBe([true, false]);
});

test('an exception leaves the closure as it is, and the own context is restored', function () {
    expect(phasync::run(function () {
        $own = phasync::getContext();
        try {
            phasync::withContext(function () {
                phasync::sleep(0.01);
                throw new RuntimeException('boom');
            }, new stdClass());
        } catch (RuntimeException $e) {
            return [$e->getMessage(), phasync::getContext() === $own];
        }
    }))->toBe(['boom', true]);
});

test('cancelling the coroutine while the closure waits ends it with the cancellation, and restores the own context', function () {
    expect(phasync::run(function () {
        $own    = null;
        $worker = phasync::go(function () use (&$own) {
            $own = phasync::getContext();
            try {
                phasync::withContext(fn () => phasync::sleep(5), new stdClass());
            } catch (CancelledException) {
                return phasync::getContext() === $own;
            }
        });
        phasync::sleep(0.01);
        phasync::cancel($worker);

        return phasync::await($worker);
    }))->toBeTrue();
});

test('nested: the inner context applies inside, the outer one again after it', function () {
    expect(phasync::run(function () {
        $outer = new stdClass();
        $inner = new stdClass();

        return phasync::withContext(function () use ($outer, $inner) {
            $in = phasync::withContext(fn () => phasync::getContext() === $inner, $inner);

            return [$in, phasync::getContext() === $outer];
        }, $outer);
    }))->toBe([true, true]);
});

test('a context can be used once, as with go()', function () {
    phasync::run(function () {
        $context = new stdClass();
        phasync::withContext(fn () => null, $context);
        expect(fn () => phasync::withContext(fn () => null, $context))->toThrow(ContextUsedException::class);
    });
});

test('outside a coroutine it throws LogicException', function () {
    expect(fn () => phasync::withContext(fn () => null, new stdClass()))->toThrow(LogicException::class);
});

test('finally() inside withContext() runs as withContext() returns, before the caller goes on: in the same coroutine and context, last registered first', function () {
    expect(phasync::run(function () {
        $log     = [];
        $fiber   = Fiber::getCurrent();
        $context = new stdClass();
        phasync::withContext(function () use (&$log, $fiber, $context) {
            foreach ([1, 2] as $n) {
                phasync::finally(function () use (&$log, $n, $fiber, $context) {
                    $log[] = "f$n " . (Fiber::getCurrent() === $fiber ? 'same coroutine' : 'other coroutine') . ', ' . (phasync::getContext() === $context ? 'its context' : 'other context');
                });
            }
            $log[] = 'body';
        }, $context);
        $log[] = 'caller';

        return $log;
    }))->toBe(['body', 'f2 same coroutine, its context', 'f1 same coroutine, its context', 'caller']);
});

test('finally() inside withContext() may suspend, and runs also when the closure throws', function () {
    expect(phasync::run(function () {
        $log = [];
        try {
            phasync::withContext(function () use (&$log) {
                phasync::finally(function () use (&$log) {
                    phasync::sleep(0.01);
                    $log[] = 'after a wait';
                });
                throw new RuntimeException('failed');
            }, new stdClass());
        } catch (RuntimeException $e) {
            $log[] = 'caller caught ' . $e->getMessage();
        }

        return $log;
    }))->toBe(['after a wait', 'caller caught failed']);
});

test('finally() in a coroutine started inside withContext() still runs when that coroutine ends', function () {
    expect(phasync::run(function () {
        $log = [];
        phasync::withContext(function () use (&$log) {
            phasync::go(function () use (&$log) {
                phasync::finally(function () use (&$log) {
                    $log[] = 'coroutine finally';
                });
                phasync::sleep(0.02);
            });
            phasync::finally(function () use (&$log) {
                $log[] = 'withContext finally';
            });
        }, new stdClass());
        $log[] = 'caller';
        phasync::sleep(0.05);

        return $log;
    }))->toBe(['withContext finally', 'caller', 'coroutine finally']);
});

test('finally() in nested withContext() runs as each returns', function () {
    expect(phasync::run(function () {
        $log = [];
        phasync::withContext(function () use (&$log) {
            phasync::finally(function () use (&$log) {
                $log[] = 'outer finally';
            });
            phasync::withContext(function () use (&$log) {
                phasync::finally(function () use (&$log) {
                    $log[] = 'inner finally';
                });
            }, new stdClass());
            $log[] = 'between';
        }, new stdClass());

        return $log;
    }))->toBe(['inner finally', 'between', 'outer finally']);
});

/** A context that swaps a "global" in and out, logging each call. */
final class SwitchAwareTestContext implements phasync\Context\SwitchAwareInterface
{
    public static ?string $global = null;
    private ?string $saved;

    public function __construct(private string $name, private array &$log)
    {
        $this->saved = "$name's";
    }

    public function resume(): void
    {
        $this->log[]  = "resume {$this->name}";
        self::$global = $this->saved;
    }

    public function suspend(): void
    {
        $this->log[] = "suspend {$this->name}";
        $this->saved = self::$global;
    }
}

test('a switch-aware context is resumed before its coroutines run and suspended before another switch-aware context\'s run; switches within one context call neither', function () {
    $log  = [];
    $seen = phasync::run(function () use (&$log) {
        $seen = [];
        $a    = phasync::go(function () use (&$seen) {
            phasync::go(function () use (&$seen) { // a second coroutine of the same context
                $seen[] = 'a2';
            });
            phasync::sleep(0.01);
            $seen[] = 'a1';
        }, context: new SwitchAwareTestContext('a', $log));
        $b = phasync::go(function () use (&$seen) {
            phasync::sleep(0.03);
            $seen[] = 'b';
        }, context: new SwitchAwareTestContext('b', $log));
        phasync::await($a);
        phasync::await($b);

        return $seen;
    });
    expect($seen)->toBe(['a2', 'a1', 'b']);
    // a: resumed to start; a's second coroutine runs without calls; b's start suspends a; the
    // loop resumes a, then b
    expect($log)->toBe(['resume a', 'suspend a', 'resume b', 'suspend b', 'resume a', 'suspend a', 'resume b']);
});

test('switch-aware contexts keep their own value of a global across interleaved coroutines, also through withContext()', function () {
    $log    = [];
    $result = phasync::run(function () use (&$log) {
        $out = [];
        foreach (['a', 'b', 'c'] as $name) {
            phasync::go(function () use ($name, &$out) {
                $out[]                          = "$name start " . SwitchAwareTestContext::$global;
                SwitchAwareTestContext::$global = "$name changed";
                phasync::sleep(0.01);
                $out[] = "$name end " . SwitchAwareTestContext::$global;
            }, context: new SwitchAwareTestContext($name, $log));
        }
        phasync::sleep(0.05);
        $out[] = 'withContext ' . phasync::withContext(fn () => SwitchAwareTestContext::$global, new SwitchAwareTestContext('w', $log));

        return $out;
    });
    expect($result)->toBe([
        "a start a's", "b start b's", "c start c's",
        'a end a changed', 'b end b changed', 'c end c changed',
        "withContext w's",
    ]);
});
