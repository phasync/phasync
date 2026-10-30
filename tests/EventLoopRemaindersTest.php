<?php

/*
 * The corners of EventLoop that the behaviour tests don't reach on their way: clear() inside a
 * coroutine, the poller the environment offers, a clock that jumps, an in-place wait, a coroutine started in another scope, a terminated waiter and a
 * failure that arrives after its run() ended.
 *
 * Every test starts from, and leaves behind, a fresh driver: several of them change loop state
 * by reflection.
 */

use phasync\EventLoop;
use phasync\TimeoutException;

beforeEach(fn () => phasyncResetProcessState());
afterEach(fn () => phasyncResetProcessState());

$loopProperty = static function (EventLoop $loop, string $name): ReflectionProperty {
    return new ReflectionProperty($loop, $name);
};

test('clear() inside a coroutine drops everything else and leaves that coroutine alone in its frame', function () {
    $result = phasync::run(function () {
        $loop    = phasync::getLoop();
        $other   = phasync::go(fn () => phasync::sleep(5));
        $before  = $loop->getFullState();
        $fiber   = phasync::getFiber();
        $frame   = phasync::frame();

        $loop->clear();

        $after = $loop->getFullState();
        expect($before['frames'])->toBe(2);
        expect($before['scheduler'])->toBe(1);
        expect($after['frames'])->toBe(1);
        expect($after['scheduler'])->toBe(0);
        expect($loop->getCurrentFiber())->toBe($fiber);
        expect($frame->live)->toBe(1);

        // the coroutine that stayed still waits and returns normally
        phasync::sleep(0.01);
        unset($other);

        return 'survived';
    });

    expect($result)->toBe('survived');
});

test('clear() gives the loop the poller the environment offers: Suspensions when the extension is loaded, else stream_select', function () use ($loopProperty) {
    $loop = (new ReflectionMethod(phasync::class, 'getDriver'))->invoke(null);
    $loop->clear();

    $poller = $loopProperty($loop, 'poller')->getValue($loop);
    if (\class_exists(phasync\ext\Suspension::class, false)) {
        expect($poller)->toBeInstanceOf(phasync\SuspensionPoller::class);
    } else {
        expect($poller)->toBeInstanceOf(phasync\StreamSelectPoller::class);
    }
});

test('a fresh process registers the loop\'s shutdown hook once: it runs before shutdown functions registered later', function () {
    // The hook registers when the first loop of the process is made, which no test can undo
    $script = <<<'PHP'
        <?php
        require %s;
        phasync::run(fn () => null);
        register_shutdown_function(static function () {
            $exiting = new ReflectionProperty(\phasync\EventLoop::class, 'exiting');
            echo 'exiting=' . ($exiting->getValue() ? '1' : '0');
        });
        PHP;
    $file = \tempnam(\sys_get_temp_dir(), 'phasync-shutdown-');
    \file_put_contents($file, \sprintf($script, \var_export(\dirname(__DIR__) . '/vendor/autoload.php', true)));

    try {
        $output = [];
        $exit   = null;
        \exec(\escapeshellarg(\PHP_BINARY) . ' ' . \escapeshellarg($file) . ' 2>&1', $output, $exit);
        expect($exit)->toBe(0);
        expect(\implode("\n", $output))->toBe('exiting=1');
    } finally {
        @\unlink($file);
    }
});

test('a clock that jumps far ahead expires every timeout that fell in the gap, and keeps those still ahead', function () use ($loopProperty) {
    $caught = phasync::run(function () use ($loopProperty) {
        $loop = phasync::getLoop();
        $near = new stdClass();
        $far  = new stdClass();

        $expiring = phasync::go(function () use ($near) {
            phasync::awaitFlag($near, 5.0);
        });
        $keeping = phasync::go(function () use ($far) {
            try {
                phasync::awaitFlag($far, 3600.0);
            } catch (phasync\CancelledException) {
                return 'cancelled';
            }
        });
        phasync::sleep(0.001);

        // Ten seconds pass in one step: a gap of 1000 slots, past the 64 that are walked one by one
        $last = $loopProperty($loop, 'lastTimeoutSlot');
        $real = $last->getValue($loop);
        try {
            (new ReflectionMethod($loop, 'checkTimeouts'))->invoke($loop, $real + 1000);
            $caught = null;
            try {
                phasync::await($expiring);
            } catch (TimeoutException $e) {
                $caught = $e;
            }
        } finally {
            $last->setValue($loop, $real);
        }

        $stillWaiting = !$keeping->isTerminated();
        phasync::cancel($keeping);

        return [$caught, $stillWaiting, phasync::await($keeping)];
    });

    expect($caught[0])->toBeInstanceOf(TimeoutException::class);
    expect($caught[1])->toBeTrue();
    expect($caught[2])->toBe('cancelled');
});

test('a service started in another scope than the creating one gets a Fiber of its own scope', function () use ($loopProperty) {
    $result = phasync::run(function () use ($loopProperty) {
        $loop     = phasync::getLoop();
        $polyfill = $loopProperty($loop, 'scopePolyfill');
        $was      = $polyfill->getValue($loop);
        $seen     = null;
        $service  = null;
        (new Scope())->run(function () use ($loop, $polyfill, $was, &$seen, &$service) {
            $inner = phasync::go(function () use ($loop, $polyfill, $was, &$seen, &$service) {
                // Without the polyfill, a Fiber belongs to the scope it is constructed in: create()
                // makes the service's Fiber inside the root scope
                $polyfill->setValue($loop, false);
                try {
                    $service = phasync::service(function () use (&$seen) {
                        $seen = Scope::getCurrent();

                        return 'served';
                    });
                } finally {
                    $polyfill->setValue($loop, $was);
                }
            });
            phasync::await($inner);
        });
        phasync::await($service); // the loop starts it, in its turn

        return [$seen, $service->isTerminated(), $service->getReturn(), $was];
    });

    if (!$result[3]) {
        // With the extension the Scope is native, and the service runs in the root scope
        expect($result[0])->toBe(Scope::getRootScope());
    }
    expect($result[0])->toBeInstanceOf(Scope::class);
    expect($result[1])->toBeTrue();
    expect($result[2])->toBe('served');
});

test('raising a flag skips a waiter that has terminated and counts the live ones', function () use ($loopProperty) {
    $count = phasync::run(function () use ($loopProperty) {
        $loop = phasync::getLoop();
        $flag = new stdClass();
        $live = phasync::go(function () use ($flag) {
            phasync::awaitFlag($flag);
        });
        phasync::sleep(0.001);

        // A waiter that ended without being taken off the flag's list
        $dead = new Fiber(static fn () => null);
        $dead->start();
        $loop->whenFlagged($flag, \PHP_FLOAT_MAX, $dead);

        $count = phasync::raiseFlag($flag);
        phasync::await($live);

        $pending = $loopProperty($loop, 'pending')->getValue($loop);
        $pending->offsetUnset($dead);

        return $count;
    });

    expect($count)->toBe(1);
});

test('a failure that arrives after its run() ended is thrown to whoever releases it', function () {
    $unhandled = new ReflectionMethod(EventLoop::class, 'unhandled');
    $frame     = null;
    phasync::run(function () use (&$frame) {
        $frame = phasync::frame();
    });
    expect($frame->ended)->toBeTrue();

    $loop = (new ReflectionMethod(phasync::class, 'getDriver'))->invoke(null);
    expect(fn () => $unhandled->invoke($loop, $frame, new RuntimeException('late failure'), 1))
        ->toThrow(RuntimeException::class, 'late failure');
    expect(fn () => $unhandled->invoke($loop, null, new RuntimeException('frameless failure'), 2))
        ->toThrow(RuntimeException::class, 'frameless failure');
});
