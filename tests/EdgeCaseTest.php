<?php

test('race condition sensitivity', function () {
    $sharedResource = 0;

    phasync::run(function () use (&$sharedResource) {
        phasync::go(function () use (&$sharedResource) {
            for ($i = 0; $i < 100; ++$i) {
                ++$sharedResource;
            }
        });

        phasync::go(function () use (&$sharedResource) {
            for ($i = 0; $i < 100; ++$i) {
                ++$sharedResource;
            }
        });
    });

    expect($sharedResource)->toBe(200); // The expected value might not be reached due to race conditions
});

test('deadlock detection', function () {
    $lockA = false;
    $lockB = false;

    phasync::run(function () use (&$lockA, &$lockB) {
        $coroutine1 = phasync::go(function () use (&$lockA, &$lockB) {
            $lockA = true;
            phasync::sleep(0.1); // Simulate work and waiting for lockB
            if (!$lockB) {
                phasync::sleep(0.1);
            }
            $lockA = false;
        });

        $coroutine2 = phasync::go(function () use (&$lockA, &$lockB) {
            $lockB = true;
            phasync::sleep(0.1); // Simulate work and waiting for lockA
            if (!$lockA) {
                phasync::sleep(0.1);
            }
            $lockB = false;
        });

        phasync::await($coroutine1);
        phasync::await($coroutine2);
    });

    expect($lockA)->toBeFalse();
    expect($lockB)->toBeFalse();
});

test('memory leak detection', function () {
    $burst = function () {
        phasync::run(function () {
            for ($i = 0; $i < 10000; ++$i) {
                phasync::go(function () {
                    phasync::sleep(0.01);

                    return 'data';
                });
            }
        });
    };

    // The first bursts grow pools and caches that later bursts reuse; under the extension those grow
    // in a few one-time steps (a few hundred KB), at a burst of their own. A leak grows memory with
    // every burst, so the smallest growth of several bursts is what to bound.
    $burst();
    $growth = [];
    for ($i = 0; $i < 6; ++$i) {
        \gc_collect_cycles();
        $before = \memory_get_usage();
        $burst();
        \gc_collect_cycles();
        $growth[] = \memory_get_usage() - $before;
    }

    expect(\min($growth))->toBeLessThan(100000);
});

test('exception handling under high load', function () {
    phasync::run(function () {
        $exceptions = 0;
        $tasks      = [];
        for ($i = 0; $i < 1000; ++$i) {
            $tasks[] = phasync::go(function () use (&$exceptions) {
                try {
                    if (\mt_rand(0, 10) > 5) {
                        throw new Exception('Random failure');
                    }

                    return true;
                } catch (Exception $e) {
                    ++$exceptions;

                    return false;
                }
            });
        }

        foreach ($tasks as $task) {
            phasync::await($task);
        }

        expect($exceptions)->toBeGreaterThan(0); // Expect at least some exceptions
    });
});

test('simple circular dependency deadlock', function () {
    phasync::run(function () {
        $future1 = phasync::go(function () use (&$future2) {
            phasync::sleep(0.5);

            return phasync::await($future2);
        });
        $future2 = phasync::go(function () use (&$future1) {
            return phasync::await($future1);
        });

        expect(function () use ($future1) {
            phasync::await($future1);
        })->toThrow(LogicException::class);
        expect(function () use ($future2) {
            phasync::await($future2);
        })->toThrow(LogicException::class);
    });
});

test('deeper circular dependency deadlock', function () {
    phasync::run(function () {
        $future1 = phasync::go(function () use (&$future2) {
            phasync::sleep(0.8);

            return phasync::await($future2);
        });
        $future2 = phasync::go(function () use (&$future3) {
            phasync::sleep(0.4);

            return phasync::await($future3);
        });
        $future3 = phasync::go(function () use (&$future1) {
            return phasync::await($future1);
        });

        expect(function () use ($future1) {
            phasync::await($future1);
        })->toThrow(LogicException::class);
        expect(function () use ($future2) {
            phasync::await($future2);
        })->toThrow(LogicException::class);
        expect(function () use ($future3) {
            phasync::await($future3);
        })->toThrow(LogicException::class);
    });
});
