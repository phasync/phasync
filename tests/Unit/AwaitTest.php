<?php

use phasync\UsageError;

use function phasync\{await, run, go, sleep};

test('using go() outside of run()', function() {
    expect(function() {
        go(function() {});
    })->toThrow(UsageError::class);
});

test('await inside run()', function() {
    run(function() {
        $future = go(function() {
            sleep(0.1);
            return 1;
        });
        expect(await($future))->toBe(1);
    });
});

test('naive attempt to use sleep() directly', function() {
    $t = microtime(true);
    sleep(0.1); // Assume standard sleep() behavior
    expect(microtime(true) - $t)->toBeGreaterThan(0.1);
});

test('naive coroutine launch without run()', function() {
    expect(function() {
        $result = go(function() {
            return 1;
        });     
    })->toThrow(UsageError::class);
});  

test('attempting to await outside run()', function() {
    expect(function() {
        $future = go(function() {
            return 1;
        }); 
        await($future);  
    })->toThrow(UsageError::class); // Expect an explicit error since this is incorrect usage
});

test('assuming await blocks the entire process', function () {
    $startTime = microtime(true);

    run(function () {
        // A long wait
        sleep(0.2); 

        // Simulate some work
        go(function () {
            sleep(0.1); // Smaller delay
        });
    });

    $endTime = microtime(true);
    $elapsed = $endTime - $startTime;

    // Should be around 0.2 (since coroutines run concurrently, not sequentially)
    expect($elapsed)->toBeGreaterThanOrEqual(0.3); 
    expect($elapsed)->toBeLessThan(0.33);
});

test('nested await() calls', function() {
    run(function() {
        $innerFuture = go(function() {
            sleep(0.1);
            return 2;
        });

        $outerFuture = go(function() use ($innerFuture) {
            $innerResult = await($innerFuture);
            sleep(0.1);
            return $innerResult + 1;
        });

        expect(await($outerFuture))->toBe(3);
    });
});

test('exception handling within asynchronous functions', function() {
    expect(function() {
        run(function() {
            $future = go(function() {
                throw new Exception("Async Exception");
            });

            await($future);
        });
    })->toThrow(new Exception("Async Exception"));
});
