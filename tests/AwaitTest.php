<?php

use phasync\UsageError;

test('using go() outside of run()', function() {
    expect(function() {
        phasync::go(function() {});
    })->toThrow(UsageError::class);
});

test('await inside run()', function() {
    /**
     * This test can fail if the driver does not raise a flag with
     * the $fiber when it is terminated.
     */
    phasync::run(function() {
        $future = phasync::go(function() {
            phasync::sleep(0.1);
            return 1;
        });
        expect(phasync::await($future))->toBe(1);
    });
});

test('naive attempt to use sleep() directly', function() {
    $t = microtime(true);
    phasync::sleep(0.1); // Assume standard sleep() behavior
    expect(microtime(true) - $t)->toBeGreaterThan(0.1);
});

test('naive coroutine launch without run()', function() {
    expect(function() {
        $result = phasync::go(function() {
            return 1;
        });     
    })->toThrow(UsageError::class);
});  

test('assuming await blocks the entire process', function () {
    $startTime = microtime(true);

    phasync::run(function () {
        // A long wait
        phasync::sleep(0.2); 

        // Simulate some work
        phasync::go(function () {
            phasync::sleep(0.1); // Smaller delay
        });
    });

    $endTime = microtime(true);
    $elapsed = $endTime - $startTime;

    // Should be around 0.3 (since the inner coroutine must complete before run() returns)
    expect($elapsed)->toBeGreaterThanOrEqual(0.3); 
    expect($elapsed)->toBeLessThan(0.33);
});

test('nested await() calls', function() {
    phasync::run(function() {
        $innerFuture = phasync::go(function() {
            phasync::sleep(0.1);
            return 2;
        });

        $outerFuture = phasync::go(function() use ($innerFuture) {
            $innerResult = phasync::await($innerFuture);
            phasync::sleep(0.1);
            return $innerResult + 1;
        });

        expect(phasync::await($outerFuture))->toBe(3);
    });
});

test('exception handling within asynchronous functions', function() {
    expect(function() {
        phasync::run(function() {
            $future = phasync::go(function() {
                throw new Exception("Async Exception");
            });

            phasync::await($future);
        });
    })->toThrow(new Exception("Async Exception"));
});
