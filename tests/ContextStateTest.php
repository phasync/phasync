<?php

/*
 * Context-local state: phasync::$contextState is bound to the array of the running coroutine's
 * context once phasync::enableContextState() or phasync::adoptContextState() has turned it on.
 */

use phasync\Context\ContextFactoryInterface;

/** The state is on or off per event loop: each test gets a new loop, and leaves none behind. */
function contextStateFreshLoop(): void
{
    \gc_collect_cycles();
    (new ReflectionProperty('phasync', 'driver'))->setValue(null, null);
    phasync::$contextState         = [];
    phasync::$contextStateDefaults = [];
}
beforeEach(fn () => contextStateFreshLoop());
afterEach(fn () => contextStateFreshLoop());

test('contexts see only their own state, also after waiting; coroutines of a context share it', function () {
    phasync::enableContextState();
    $seen = [];
    phasync::run(function () use (&$seen) {
        $coroutine = function (string $name) use (&$seen) {
            phasync::$contextState['k'] = $name;
            phasync::sleep(0.01);
            $seen[$name][]              = phasync::$contextState['k'];
            phasync::$contextState['k'] = $name . '2';
            phasync::sleep(0.01);
            $seen[$name][] = phasync::$contextState['k'];
            phasync::await(phasync::go(function () use ($name, &$seen) {
                $seen[$name][] = 'child ' . phasync::$contextState['k'];
            }));
        };
        $a = phasync::go($coroutine, ['a'], new stdClass());
        $b = phasync::go($coroutine, ['b'], new stdClass());
        phasync::await($a);
        phasync::await($b);
    });
    expect($seen)->toBe(['a' => ['a', 'a2', 'child a2'], 'b' => ['b', 'b2', 'child b2']]);
});

test('a new context starts with a copy of the defaults', function () {
    phasync::$contextStateDefaults = ['a' => 1];
    phasync::enableContextState();
    $seen = [];
    phasync::run(function () use (&$seen) {
        phasync::await(phasync::go(function () use (&$seen) {
            $seen[]                     = phasync::$contextState['a'];
            phasync::$contextState['a'] = 2;
            phasync::sleep(0.01);
            $seen[] = phasync::$contextState['a'];
        }, [], new stdClass()));
        phasync::await(phasync::go(function () use (&$seen) {
            $seen[] = phasync::$contextState['a'];
        }, [], new stdClass()));
    });
    expect($seen)->toBe([1, 2, 1]);
    expect(phasync::$contextStateDefaults)->toBe(['a' => 1]);
});

test('an adopted array is shared by reference, also with a later context', function () {
    $shared = ['n' => 0];
    phasync::run(function () use (&$shared) {
        phasync::await(phasync::go(function () use (&$shared) {
            phasync::adoptContextState($shared);
            phasync::$contextState['n'] = 1;
            expect($shared['n'])->toBe(1);
            phasync::sleep(0.01);
            ++phasync::$contextState['n'];
        }, [], new stdClass()));
        phasync::await(phasync::go(function () use (&$shared) {
            phasync::adoptContextState($shared);
            expect(phasync::$contextState['n'])->toBe(2);
            phasync::$contextState['n'] = 3;
        }, [], new stdClass()));
    });
    expect($shared['n'])->toBe(3);
});

test('live contexts that adopted different arrays stay separate across waits', function () {
    $one  = ['v' => 'one'];
    $two  = ['v' => 'two'];
    $seen = [];
    phasync::run(function () use (&$one, &$two, &$seen) {
        $a = phasync::go(function () use (&$one, &$seen) {
            phasync::adoptContextState($one);
            phasync::sleep(0.01);
            $seen[]                     = phasync::$contextState['v'];
            phasync::$contextState['v'] = 'one!';
        }, [], new stdClass());
        $b = phasync::go(function () use (&$two, &$seen) {
            phasync::adoptContextState($two);
            phasync::sleep(0.01);
            $seen[]                     = phasync::$contextState['v'];
            phasync::$contextState['v'] = 'two!';
        }, [], new stdClass());
        phasync::await($a);
        phasync::await($b);
    });
    expect($seen)->toBe(['one', 'two']);
    expect([$one['v'], $two['v']])->toBe(['one!', 'two!']);
});

test('adopting outside a coroutine only binds the live array', function () {
    $state = ['x' => 1];
    phasync::adoptContextState($state);
    phasync::$contextState['x'] = 2;
    expect($state['x'])->toBe(2);
});

test('while off, the state is an ordinary shared array', function () {
    phasync::$contextState = ['k' => 0];
    phasync::run(function () {
        phasync::await(phasync::go(function () {
            phasync::$contextState['k'] = 1;
            phasync::sleep(0.01);
        }, [], new stdClass()));
        phasync::await(phasync::go(fn () => phasync::$contextState['k']++, [], new stdClass()));
    });
    expect(phasync::$contextState)->toBe(['k' => 2]);
});

test('enabling inside a coroutine gives its context an array at once', function () {
    phasync::$contextStateDefaults = ['d' => 1];
    phasync::run(function () {
        phasync::enableContextState();
        phasync::enableContextState();
        expect(phasync::$contextState)->toBe(['d' => 1]);
        phasync::$contextState['m'] = 1;
        $other                      = phasync::go(function () {
            expect(phasync::$contextState)->toBe(['d' => 1]);
        }, [], new stdClass());
        expect(phasync::$contextState)->toBe(['d' => 1, 'm' => 1]);
        phasync::await($other);
        phasync::sleep(0.01);
        expect(phasync::$contextState)->toBe(['d' => 1, 'm' => 1]);
    });
});

test('a withContext() has its own state, and the caller gets its own back', function () {
    phasync::enableContextState();
    phasync::run(function () {
        phasync::$contextState['k'] = 'outer';
        phasync::withContext(function () {
            expect(phasync::$contextState)->toBe([]);
            phasync::$contextState['k'] = 'inner';
            phasync::sleep(0.01);
            expect(phasync::$contextState['k'])->toBe('inner');
        }, new stdClass());
        expect(phasync::$contextState['k'])->toBe('outer');
    });
});

test('adopting in a withContext() with a factory creates the context', function () {
    $request = ['r' => 1];
    $factory = new class implements ContextFactoryInterface {
        public int $calls = 0;

        public function createContext(): object
        {
            ++$this->calls;

            return new stdClass();
        }
    };
    phasync::run(function () use (&$request, $factory) {
        phasync::enableContextState();
        phasync::$contextState['k'] = 'outer';
        phasync::withContext(function () use (&$request, $factory) {
            expect($factory->calls)->toBe(0);
            phasync::adoptContextState($request);
            expect($factory->calls)->toBe(1);
            phasync::$contextState['r'] = 2;
            phasync::await(phasync::go(function () {
                ++phasync::$contextState['r'];
                phasync::sleep(0.01);
            }));
            phasync::sleep(0.01);
            expect(phasync::$contextState['r'])->toBe(3);
        }, $factory);
        expect(phasync::$contextState)->toBe(['k' => 'outer']);
    });
    expect($request['r'])->toBe(3);
});

test('a cancelled or failed context does not leave its state for the next coroutine', function () {
    phasync::enableContextState();
    $seen = [];
    phasync::run(function () use (&$seen) {
        $victim = phasync::go(function () {
            phasync::$contextState['k'] = 'victim';
            try {
                phasync::sleep(10);
            } finally {
                phasync::$contextState['k'] = 'victim unwound';
            }
        }, [], new stdClass());
        phasync::$contextState['k'] = 'main';
        phasync::sleep(0);
        phasync::cancel($victim);
        $failing = phasync::go(function () {
            phasync::$contextState['k'] = 'failing';
            throw new RuntimeException('boom');
        }, [], new stdClass());
        try {
            phasync::await($failing);
        } catch (RuntimeException) {
        }
        $seen[] = phasync::$contextState['k'];
        phasync::sleep(0.01);
        phasync::await(phasync::go(function () use (&$seen) {
            $seen[] = phasync::$contextState;
        }, [], new stdClass()));
        $seen[] = phasync::$contextState['k'];
    });
    expect($seen)->toBe(['main', [], 'main']);
});
