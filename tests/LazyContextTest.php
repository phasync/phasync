<?php

/*
 * phasync::withContext() given a ContextFactoryInterface: the context is created, and entered,
 * when the closure first needs it.
 */

use phasync\CancelledException;
use phasync\Context\ContextFactoryInterface;
use phasync\Context\SwitchAwareInterface;
use phasync\ContextUsedException;

function lazyFactory(?object $fixed = null): object
{
    return new class($fixed) implements ContextFactoryInterface {
        public int $calls    = 0;
        public ?object $last = null;

        public function __construct(private ?object $fixed)
        {
        }

        public function createContext(): object
        {
            ++$this->calls;

            return $this->last = $this->fixed ?? new stdClass();
        }
    };
}

test('a closure that never asks for its context never gets one', function () {
    $factory = lazyFactory();
    expect(phasync::run(function () use ($factory) {
        $own = phasync::getContext();

        return [phasync::withContext(fn () => 42, $factory), phasync::getContext() === $own];
    }))->toBe([42, true]);
    expect($factory->calls)->toBe(0);
});

test('getContext() creates the context once, and it stays the context across suspensions; afterwards the own one again', function () {
    $factory = lazyFactory();
    expect(phasync::run(function () use ($factory) {
        $own  = phasync::getContext();
        $seen = phasync::withContext(function () use ($factory) {
            phasync::sleep(0.01);
            $first = phasync::getContext();
            phasync::sleep(0.01);

            return [$first === $factory->last, phasync::getContext() === $first, phasync::getRootContext() === $first];
        }, $factory);

        return [...$seen, phasync::getContext() === $own];
    }))->toBe([true, true, true, true]);
    expect($factory->calls)->toBe(1);
});

test('a coroutine started inside gets the context created, and it keeps running after the closure returns', function () {
    $factory = lazyFactory();
    expect(phasync::run(function () use ($factory) {
        $child   = phasync::withContext(fn () => phasync::go(function () {
            phasync::sleep(0.05);

            return phasync::getContext();
        }), $factory);
        $running = !$child->isTerminated();

        return [$running, phasync::await($child) === $factory->last];
    }))->toBe([true, true]);
    expect($factory->calls)->toBe(1);
});

test('the context counts the calling coroutine only while the closure runs', function () {
    $factory = lazyFactory();
    expect(phasync::run(function () use ($factory) {
        $fiber  = Fiber::getCurrent();
        $inside = phasync::withContext(function () use ($factory, $fiber) {
            phasync::getContext();

            return \in_array($fiber, phasync::getLoop()->getFibers($factory->last), true);
        }, $factory);

        return [$inside, \in_array($fiber, phasync::getLoop()->getFibers($factory->last), true)];
    }))->toBe([true, false]);
});

test('finally() inside creates the context, and runs as the call returns, in the same coroutine and context', function () {
    $factory = lazyFactory();
    expect(phasync::run(function () use ($factory) {
        $log   = [];
        $fiber = Fiber::getCurrent();
        phasync::withContext(function () use (&$log, $fiber, $factory) {
            phasync::finally(function () use (&$log, $fiber, $factory) {
                $log[] = (Fiber::getCurrent() === $fiber ? 'same coroutine' : 'other coroutine') . ', ' . (phasync::getContext() === $factory->last ? 'its context' : 'other context');
            });
            $log[] = 'body';
        }, $factory);
        $log[] = 'caller';

        return $log;
    }))->toBe(['body', 'same coroutine, its context', 'caller']);
    expect($factory->calls)->toBe(1);
});

test('an exception leaves the closure as it is, with or without a context created, and the own context is restored', function () {
    $factory = lazyFactory();
    expect(phasync::run(function () use ($factory) {
        $own = phasync::getContext();
        $out = [];
        foreach ([false, true] as $ask) {
            try {
                phasync::withContext(function () use ($ask) {
                    if ($ask) {
                        phasync::getContext();
                    }
                    phasync::sleep(0.01);
                    throw new RuntimeException('boom');
                }, $factory);
            } catch (RuntimeException $e) {
                $out[] = [$e->getMessage(), phasync::getContext() === $own];
            }
        }

        return $out;
    }))->toBe([['boom', true], ['boom', true]]);
    expect($factory->calls)->toBe(1);
});

test('cancelling the coroutine while the closure waits, with no context created, ends it with the cancellation', function () {
    $factory = lazyFactory();
    expect(phasync::run(function () use ($factory) {
        $own    = null;
        $worker = phasync::go(function () use (&$own, $factory) {
            $own = phasync::getContext();
            try {
                phasync::withContext(fn () => phasync::sleep(5), $factory);
            } catch (CancelledException) {
                return phasync::getContext() === $own;
            }
        });
        phasync::sleep(0.01);
        phasync::cancel($worker);

        return phasync::await($worker);
    }))->toBeTrue();
    expect($factory->calls)->toBe(0);
});

test('cancelling the context that was created cancels the coroutine waiting in it', function () {
    $factory = lazyFactory();
    expect(phasync::run(function () use ($factory) {
        $worker = phasync::go(function () use ($factory) {
            try {
                phasync::withContext(function () {
                    phasync::getContext();
                    phasync::sleep(5);
                }, $factory);
            } catch (CancelledException) {
                return 'cancelled';
            }
        });
        phasync::sleep(0.01);
        phasync::cancel($factory->last);

        return phasync::await($worker);
    }))->toBe('cancelled');
});

test('nested: an inner lazy context asking for itself brings the outer one into being first', function () {
    $outer = lazyFactory();
    $inner = lazyFactory();
    expect(phasync::run(function () use ($outer, $inner) {
        return phasync::withContext(function () use ($outer, $inner) {
            $in = phasync::withContext(fn () => phasync::getContext() === $inner->last, $inner);

            return [$in, $outer->calls, phasync::getContext() === $outer->last];
        }, $outer);
    }))->toBe([true, 1, true]);
    expect($inner->calls)->toBe(1);
});

test('nested: entering another context brings the outer one into being, an inner one nobody asks for stays uncreated', function () {
    $outer = lazyFactory();
    $inner = lazyFactory();
    phasync::run(function () use ($outer, $inner) {
        phasync::withContext(fn () => phasync::withContext(fn () => null, $inner), $outer);
    });
    expect([$outer->calls, $inner->calls])->toBe([1, 0]);
});

test('a plain context entered inside a lazy one brings the lazy one into being first', function () {
    $lazy  = lazyFactory();
    $plain = new stdClass();
    expect(phasync::run(function () use ($lazy, $plain) {
        return phasync::withContext(function () use ($lazy, $plain) {
            $in = phasync::withContext(fn () => phasync::getContext() === $plain, $plain);

            return [$in, $lazy->calls, phasync::getContext() === $lazy->last];
        }, $lazy);
    }))->toBe([true, 1, true]);
});

test('a lazy context entered inside a plain one takes over from it once asked', function () {
    $plain = new stdClass();
    $lazy  = lazyFactory();
    expect(phasync::run(function () use ($plain, $lazy) {
        return phasync::withContext(function () use ($plain, $lazy) {
            return phasync::withContext(fn () => [phasync::getContext() === $plain, $lazy->calls], $lazy);
        }, $plain);
    }))->toBe([false, 1]);
});

test('every scope gets a context of its own', function () {
    $factory = lazyFactory();
    expect(phasync::run(function () use ($factory) {
        $a = phasync::withContext(fn () => phasync::getContext(), $factory);
        $b = phasync::withContext(fn () => phasync::getContext(), $factory);

        return $a !== $b;
    }))->toBeTrue();
});

test('a factory that hands out a context used before is refused, as with a plain context', function () {
    $context = new stdClass();
    $factory = lazyFactory($context);
    phasync::run(function () use ($factory, $context) {
        phasync::withContext(fn () => null, $context);
        expect(fn () => phasync::withContext(fn () => phasync::getContext(), $factory))->toThrow(ContextUsedException::class);
    });
});

test('coroutines in lazy scopes at once each keep their own', function () {
    $fa = lazyFactory();
    $fb = lazyFactory();
    expect(phasync::run(function () use ($fa, $fb) {
        $a = phasync::go(fn () => phasync::withContext(function () use ($fa) {
            $mine = phasync::getContext();
            phasync::sleep(0.02);

            return $mine === phasync::getContext() && $mine === $fa->last;
        }, $fa));
        $b = phasync::go(fn () => phasync::withContext(function () use ($fb) {
            phasync::sleep(0.01);
            $mine = phasync::getContext();
            phasync::sleep(0.02);

            return $mine === phasync::getContext() && $mine === $fb->last;
        }, $fb));

        return [phasync::await($a), phasync::await($b), $fa->last !== $fb->last];
    }))->toBe([true, true, true]);
});

test('a nested phasync::run() inside brings the context into being', function () {
    $factory = lazyFactory();
    phasync::run(function () use ($factory) {
        phasync::withContext(fn () => phasync::run(fn () => 1), $factory);
    });
    expect($factory->calls)->toBe(1);
});

test('a switch-aware context is resumed when it is created, and the coroutine goes on in it', function () {
    $context = new class implements SwitchAwareInterface {
        public array $log = [];

        public function resume(): void
        {
            $this->log[] = 'resume';
        }

        public function suspend(): void
        {
            $this->log[] = 'suspend';
        }
    };
    $factory = lazyFactory($context);
    phasync::run(function () use ($factory, $context) {
        phasync::withContext(function () use ($context) {
            expect($context->log)->toBe([]);
            phasync::getContext();
            expect($context->log)->toBe(['resume']);
            phasync::sleep(0.01);
            expect($context->log)->toBe(['resume']);
        }, $factory);
    });
});
