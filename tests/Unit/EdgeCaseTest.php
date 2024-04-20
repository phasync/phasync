<?php
use function phasync\{go, run, await, sleep};

test('race condition sensitivity', function() {
    $sharedResource = 0;

    run(function() use (&$sharedResource) {
        go(function() use (&$sharedResource) {
            for ($i = 0; $i < 100; $i++) {
                $sharedResource++;
            }
        });

        go(function() use (&$sharedResource) {
            for ($i = 0; $i < 100; $i++) {
                $sharedResource++;
            }
        });
    });

    expect($sharedResource)->toBe(200); // The expected value might not be reached due to race conditions
});

test('deadlock detection', function() {
    $lockA = false;
    $lockB = false;

    run(function() use (&$lockA, &$lockB) {
        $coroutine1 = go(function() use (&$lockA, &$lockB) {
            $lockA = true;
            sleep(0.1); // Simulate work and waiting for lockB
            if (!$lockB) {
                sleep(0.1);
            }
            $lockA = false;
        });

        $coroutine2 = go(function() use (&$lockA, &$lockB) {
            $lockB = true;
            sleep(0.1); // Simulate work and waiting for lockA
            if (!$lockA) {
                sleep(0.1);
            }
            $lockB = false;
        });

        await($coroutine1);
        await($coroutine2);
    });

    expect($lockA)->toBeFalse();
    expect($lockB)->toBeFalse();
});

test('memory leak detection', function() {
    $startMemory = memory_get_usage();

    run(function() {
        for ($i = 0; $i < 10000; $i++) {
            go(function() {
                sleep(0.01);
                return "data";
            });
        }
    });

    $endMemory = memory_get_usage();
    expect($endMemory - $startMemory)->toBeLessThan(5000000); // Example threshold
});

test('exception handling under high load', function() {
    run(function() {
        $exceptions = 0;
        $tasks = [];
        for ($i = 0; $i < 1000; $i++) {
            $tasks[] = go(function() use (&$exceptions) {
                try {
                    if (rand(0, 10) > 5) {
                        throw new Exception("Random failure");
                    }
                    return true;
                } catch (Exception $e) {
                    $exceptions++;
                    return false;
                }
            });
        }

        foreach ($tasks as $task) {
            await($task);
        }

        expect($exceptions)->toBeGreaterThan(0); // Expect at least some exceptions
    });
});

test('simple circular dependency deadlock', function() {
    run(function() {
        $future1 = go(function() use (&$future2) {
            sleep(0.5);
            return await($future2);
        });
        $future2 = go(function() use (&$future1) {
            return await($future1);
        });

        expect(function() use ($future1) {
            await($future1);
        })->toThrow(LogicException::class);
    });
});

test('deeper circular dependency deadlock', function() {
    run(function() {
        $future1 = go(function() use (&$future2) {
            sleep(1);
            return await($future2);
        });
        $future2 = go(function() use (&$future3) {
            sleep(0.5);
            return await($future3);
        });
        $future3 = go(function() use (&$future1) {
            return await($future1);
        });

        expect(function() use ($future1) {
            await($future1);
        })->toThrow(LogicException::class);
        expect(function() use ($future2) {
            await($future2);
        })->toThrow(LogicException::class);
        expect(function() use ($future3) {
            await($future3);
        })->toThrow(LogicException::class);
    });
});
