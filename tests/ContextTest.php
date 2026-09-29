<?php

use phasync\ContextUsedException;

test('context outside of corotuine', function () {
    expect(function () {
        phasync::getContext();
    })->toThrow(LogicException::class);
});

test('a context is used once', function () {
    phasync::run(function () {
        $context = new stdClass();
        phasync::await(phasync::go(fn () => null, context: $context));
        expect(fn () => phasync::go(fn () => null, context: $context))->toThrow(ContextUsedException::class);
        expect(fn () => phasync::withContext(fn () => null, $context))->toThrow(ContextUsedException::class);
    });
});

/** A context that keeps what reaches its handler. */
final class HandlingContext implements phasync\Context\ExceptionHandlerInterface
{
    public array $handled = [];

    public function handleException(Throwable $exception): void
    {
        $this->handled[] = $exception->getMessage() . (null === Fiber::getCurrent() ? ' (in the loop)' : ' (in a coroutine)');
    }
}

test('a failure nobody awaits goes to its context\'s handler: the coroutine ends, the others run on, run() returns', function () {
    $context = new HandlingContext();
    $result  = phasync::run(function () {
        phasync::go(function () {
            throw new RuntimeException('handled');
        });
        phasync::sleep(0.02); // not dropped

        return 'main done';
    }, context: $context);
    expect([$result, $context->handled])->toBe(['main done', ['handled (in the loop)']]);
});

test('a failure in a context nested in one with a handler goes to that handler', function () {
    $outer  = new HandlingContext();
    $result = phasync::run(function () {
        phasync::withContext(function () {
            phasync::go(function () {
                phasync::sleep(0.01);
                throw new RuntimeException('from the nested context');
            });
        }, new stdClass());
        phasync::sleep(0.03);

        return 'ok';
    }, context: $outer);
    expect([$result, $outer->handled])->toBe(['ok', ['from the nested context (in the loop)']]);
});

test('a nested run() without a handler fails alone: its coroutines are dropped, and it throws into the calling coroutine, whose run goes on', function () {
    $log    = [];
    $result = phasync::run(function () use (&$log) {
        $sibling = phasync::go(function () use (&$log) {
            phasync::sleep(0.03);
            $log[] = 'outer sibling finished';
        });
        try {
            phasync::run(function () use (&$log) {
                phasync::go(function () use (&$log) {
                    try {
                        phasync::sleep(1);
                    } finally {
                        $log[] = 'inner waiter unwound';
                    }
                });
                phasync::go(function () {
                    phasync::sleep(0.01);
                    throw new RuntimeException('inner failure');
                });
                phasync::sleep(1);
            });
        } catch (RuntimeException $e) {
            $log[] = 'caught ' . $e->getMessage();
        }
        phasync::await($sibling);

        return 'outer done';
    });
    expect($result)->toBe('outer done');
    expect($log)->toBe(['inner waiter unwound', 'caught inner failure', 'outer sibling finished']);
});

test('getRootContext(): a run()\'s context is its own root; a context entered from it is a root; contexts entered from that share its root', function () {
    $r = phasync::run(function () {
        $run     = phasync::getContext();
        $out     = ['run is its own root' => phasync::getRootContext() === $run];
        $request = new stdClass();
        phasync::withContext(function () use (&$out, $request) {
            $out['entered from the run is a root'] = phasync::getRootContext() === $request;
            phasync::withContext(function () use (&$out, $request) {
                $out['nested withContext: same root'] = phasync::getRootContext() === $request;
            }, new stdClass());
            phasync::await(phasync::go(function () use (&$out, $request) {
                $out['go(context:) in it: same root'] = phasync::getRootContext() === $request;
            }, context: new stdClass()));
            phasync::await(phasync::go(function () use (&$out, $request) {
                $out['plain go() in it: same root'] = phasync::getRootContext() === $request;
            }));
            $out['a nested run() is a root of its own'] = phasync::run(fn () => phasync::getRootContext() === phasync::getContext());
        }, $request);

        return $out;
    });
    expect(\array_filter($r, fn ($v) => true !== $v))->toBe([]);
});

test('a root context is not kept alive by its root entry once its coroutines are done', function () {
    $ref = phasync::run(function () {
        $request = new stdClass();
        $ref     = WeakReference::create($request);
        phasync::withContext(fn () => phasync::getRootContext(), $request);
        unset($request);

        return $ref;
    });
    \gc_collect_cycles();
    expect($ref->get())->toBeNull();
});

test('getRootContext() outside a coroutine throws LogicException', function () {
    expect(fn () => phasync::getRootContext())->toThrow(LogicException::class);
});
