<?php

/*
 * phasync::withContext(): a closure run in the current coroutine as a coroutine of a context.
 */

use phasync\CancelledException;
use phasync\Context\DefaultContext;
use phasync\ContextUsedException;

test('inside, getContext() is the given context, also after a suspension; afterwards the own one again', function () {
    expect(phasync::run(function () {
        $own     = phasync::getContext();
        $context = new DefaultContext();
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

        return phasync::withContext(fn () => [Fiber::getCurrent() === $fiber, 42], new DefaultContext());
    }))->toBe([true, 42]);
});

test('coroutines started inside belong to the context and keep running after it returns', function () {
    expect(phasync::run(function () {
        $context = new DefaultContext();
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
        $context = new DefaultContext();
        $fiber   = Fiber::getCurrent();
        $inside  = phasync::withContext(fn () => isset($context->getFibers()[$fiber]), $context);

        return [$inside, isset($context->getFibers()[$fiber])];
    }))->toBe([true, false]);
});

test('an exception leaves the closure as it is, and the own context is restored', function () {
    expect(phasync::run(function () {
        $own = phasync::getContext();
        try {
            phasync::withContext(function () {
                phasync::sleep(0.01);
                throw new RuntimeException('boom');
            }, new DefaultContext());
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
                phasync::withContext(fn () => phasync::sleep(5), new DefaultContext());
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
        $outer = new DefaultContext();
        $inner = new DefaultContext();

        return phasync::withContext(function () use ($outer, $inner) {
            $in = phasync::withContext(fn () => phasync::getContext() === $inner, $inner);

            return [$in, phasync::getContext() === $outer];
        }, $outer);
    }))->toBe([true, true]);
});

test('a context can be used once, as with go()', function () {
    phasync::run(function () {
        $context = new DefaultContext();
        phasync::withContext(fn () => null, $context);
        expect(fn () => phasync::withContext(fn () => null, $context))->toThrow(ContextUsedException::class);
    });
});

test('outside a coroutine it throws LogicException', function () {
    expect(fn () => phasync::withContext(fn () => null, new DefaultContext()))->toThrow(LogicException::class);
});
