<?php

/*
 * Characterization tests for docs/SEMANTICS.md section 12 (RT-1 .. RT-4).
 * They pin how phasync treats the surrounding PHP process TODAY. Read
 * tests/Characterization/README.md before changing anything here.
 */

use phasync\Context\DefaultContext;
use phasync\ContextUsedException;

uses()->group('characterization');

// ---------------------------------------------------------------------------
// RT-1: garbage collection state
// ---------------------------------------------------------------------------

test('RT-1: gc_enabled() is false inside run() and true again afterwards [DIVERGENCE]', function () {
    // The contract (RT-1) says phasync does not disable the collector for the lifetime of run().
    $before = \gc_enabled();
    $inside = phasync::run(static fn () => \gc_enabled());

    expect([$before, $inside, \gc_enabled()])->toBe([true, false, true]);
})->group('divergence');

test('RT-1: the collector stays off in nested run() and is re-enabled only when the outermost run() exits [DIVERGENCE]', function () {
    $log = [];
    phasync::run(static function () use (&$log) {
        $log[] = \gc_enabled();
        phasync::run(static function () use (&$log) {
            $log[] = \gc_enabled();
        });
        $log[] = \gc_enabled();
    });
    $log[] = \gc_enabled();

    expect($log)->toBe([false, false, false, true]);
})->group('divergence');

test('RT-1: the collector is off in child coroutines and after they suspend [DIVERGENCE]', function () {
    $states = phasync::run(static function () {
        $child = phasync::go(static function () {
            phasync::sleep(0.01);

            return \gc_enabled();
        });

        return [\gc_enabled(), phasync::await($child)];
    });

    expect($states)->toBe([false, false]);
})->group('divergence');

test('RT-1: the collector is re-enabled when run() exits with an exception', function () {
    try {
        phasync::run(static function () {
            throw new Exception('boom');
        });
    } catch (Exception) {
    }

    expect(\gc_enabled())->toBeTrue();
});

test('RT-1: run() enables the collector on exit even if the caller had disabled it [DIVERGENCE]', function () {
    // The contract (RT-1) says the previous state is restored.
    \gc_disable();
    try {
        phasync::run(static fn () => 1);
        $afterRun = \gc_enabled();
    } finally {
        \gc_enable();
    }

    expect($afterRun)->toBeTrue();
})->group('divergence');

test('RT-1: PHP\'s automatic cycle collection does not run while run() is active [DIVERGENCE]', function () {
    \gc_collect_cycles();
    $runsBefore = \gc_status()['runs'];
    $runsDuring = phasync::run(static function () {
        $before = \gc_status()['runs'];
        for ($i = 0; $i < 30000; ++$i) {
            $object       = new stdClass();
            $object->self = $object;
        }

        return \gc_status()['runs'] - $before;
    });

    expect($runsDuring)->toBe(0);
    expect(\gc_status()['runs'])->toBeGreaterThanOrEqual($runsBefore);
    \gc_collect_cycles();
})->group('divergence');

// ---------------------------------------------------------------------------
// RT-2: no handlers or ini changes
// ---------------------------------------------------------------------------

test('RT-2: run() does not replace the error handler or the exception handler', function () {
    // The test runner has its own handlers installed, so compare against them.
    $current = static function (): array {
        $error = \set_error_handler(static fn () => false);
        \restore_error_handler();
        $exception = \set_exception_handler(static fn () => null);
        \restore_exception_handler();

        return [$error, $exception];
    };
    $before = $current();
    $inside = phasync::run($current);

    expect($inside)->toBe($before);
    expect($current())->toBe($before);
});

test('RT-2: a user-installed error handler is still installed after run()', function () {
    $handler = static fn () => false;
    \set_error_handler($handler);
    try {
        phasync::run(static fn () => phasync::sleep(0.01));
        $current = \set_error_handler(static fn () => false);
        \restore_error_handler();
    } finally {
        \restore_error_handler();
    }

    expect($current)->toBe($handler);
});

test('RT-2: run() leaves error_reporting and common ini settings unchanged', function () {
    $snapshot = static fn () => [\error_reporting(), \ini_get('display_errors'), \ini_get('precision'), \ini_get('memory_limit'), \ini_get('max_execution_time')];
    $before   = $snapshot();
    phasync::run(static fn () => phasync::sleep(0.01));

    expect($snapshot())->toBe($before);
});

// ---------------------------------------------------------------------------
// RT-3: run() at any depth, and what is available where
// ---------------------------------------------------------------------------

test('RT-3: isRunning() is true only inside a coroutine', function () {
    expect([phasync::isRunning(), phasync::run(static fn () => phasync::isRunning()), phasync::isRunning()])
        ->toBe([false, true, false]);
});

test('RT-3: getFiber() and getContext() outside a coroutine throw LogicException', function () {
    expect(static fn () => phasync::getFiber())
        ->toThrow(LogicException::class, 'This function can not be used outside of a coroutine');
    expect(static fn () => phasync::getContext())
        ->toThrow(LogicException::class, 'This function can not be used outside of a coroutine');
});

test('RT-3: getContext() is a DefaultContext shared by a scope\'s coroutines and different in a nested run()', function () {
    $result = phasync::run(static function () {
        $context = phasync::getContext();

        return [
            $context::class,
            phasync::await(phasync::go(static fn () => phasync::getContext() === $context)),
            phasync::run(static fn () => phasync::getContext() === $context),
        ];
    });

    expect($result)->toBe([DefaultContext::class, true, false]);
});

test('RT-3: run() returns the closure\'s value and passes its arguments', function () {
    expect(phasync::run(static fn () => 'value'))->toBe('value');
    expect(phasync::run(static fn ($a, $b) => $a + $b, [2, 3]))->toBe(5);
});

test('RT-3: run() can be nested at any depth, also inside child coroutines', function () {
    expect(phasync::run(static fn () => phasync::run(static fn () => phasync::run(static fn () => 7))))->toBe(7);
    expect(phasync::run(static fn () => phasync::await(phasync::go(static fn () => phasync::run(static fn () => 'inner')))))
        ->toBe('inner');
});

test('RT-3: run() works again after a previous run() threw', function () {
    try {
        phasync::run(static function () {
            throw new Exception('first');
        });
    } catch (Exception) {
    }

    expect(phasync::run(static fn () => 'second'))->toBe('second');
});

test('RT-3: an explicit context is activated by run() and can not be reused', function () {
    $context = new DefaultContext();
    $seen    = phasync::run(static fn () => phasync::getContext() === $context, [], $context);

    expect([$seen, $context->isActivated()])->toBe([true, true]);
    expect(static fn () => phasync::run(static fn () => 2, [], $context))
        ->toThrow(ContextUsedException::class, "Can't use a context multiple times");
});

test('RT-3: onEnter/onExit hooks run once per outermost run(), not for nested run() calls', function () {
    $enter    = new ReflectionProperty(phasync::class, 'onEnterCallbacks');
    $exit     = new ReflectionProperty(phasync::class, 'onExitCallbacks');
    $original = [$enter->getValue(), $exit->getValue()];
    $log      = [];
    try {
        phasync::onEnter(static function () use (&$log) {
            $log[] = 'enter';
        });
        phasync::onExit(static function () use (&$log) {
            $log[] = 'exit';
        });
        phasync::run(static function () {
            phasync::run(static fn () => 1);
            phasync::sleep(0.01);
        });
        phasync::run(static fn () => 1);
    } finally {
        $enter->setValue(null, $original[0]);
        $exit->setValue(null, $original[1]);
    }

    expect($log)->toBe(['enter', 'exit', 'enter', 'exit']);
});

test('RT-3: defer() outside a coroutine only queues the callback, it runs during the next run()', function () {
    $ran = 0;
    phasync::defer(static function () use (&$ran) {
        ++$ran;
    });
    $before = $ran;
    phasync::run(static fn () => phasync::sleep(0.01));

    expect([$before, $ran])->toBe([0, 1]);
});

// ---------------------------------------------------------------------------
// RT-4: unsupported use fails loudly
// ---------------------------------------------------------------------------

test('RT-4: go() outside run() throws LogicException naming phasync::run()', function () {
    expect(static fn () => phasync::go(static fn () => 1))
        ->toThrow(LogicException::class, "Can't create a coroutine outside of a context. Use `phasync::run()` to launch a context.");
});

test('RT-4: select() outside run() throws LogicException naming phasync::run()', function () {
    expect(static fn () => phasync::select([]))
        ->toThrow(LogicException::class, "Can't use phasync::select() outside of phasync. Use `phasync::run()` to launch a context.");
});

test('RT-4: await() of a Fiber that phasync did not create throws LogicException', function () {
    $fiber = new Fiber(static fn () => 1);

    expect(static fn () => phasync::await($fiber))
        ->toThrow(LogicException::class, "Can't await a coroutine not from phasync");
});

test('RT-4: await() outside run() returns the result of a coroutine that already finished, or rethrows its exception', function () {
    $finished = null;
    $failed   = null;
    phasync::run(static function () use (&$finished, &$failed) {
        $finished = phasync::go(static fn () => 42);
        $failed   = phasync::go(static function () {
            throw new DomainException('failed');
        });
        try {
            phasync::await($failed);
        } catch (DomainException) {
        }
    });

    expect(phasync::await($finished))->toBe(42);
    expect(static fn () => phasync::await($failed))->toThrow(DomainException::class, 'failed');
});

test('RT-4: go() with run: true outside a coroutine runs the closure, but the returned Fiber can not be awaited [SURPRISE]', function () {
    $ran   = false;
    $fiber = phasync::go(static function () use (&$ran) {
        $ran = true;

        return 5;
    }, [], 1, null, true);

    expect($ran)->toBeTrue();
    expect($fiber)->toBeInstanceOf(Fiber::class);
    expect(static fn () => phasync::await($fiber))->toThrow(LogicException::class, "Can't await a coroutine not from phasync");
})->group('surprise');
