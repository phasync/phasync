<?php

use phasync\UsageError;

use function phasync\{await, run, go, sleep};

test('performance and scalability', function() {
    $startTime = microtime(true);

    run(function() {
        $futures = [];
        for ($i = 0; $i < 5000; $i++) {
            $futures[] = go(function() {
                sleep(0.01);
                return true;
            });
        }

        // Wait for all tasks to complete
        foreach ($futures as $future) {
            await($future);
        }
    });

    $endTime = microtime(true);
    $elapsed = $endTime - $startTime;

    // Ensure that the elapsed time is reasonable
    expect($elapsed)->toBeLessThan(0.5); // Assuming all tasks complete within 1 second
});

test('large-scale concurrent tasks', function() {
    $numTasks = 10000; // Increase the number of tasks
    $startTime = microtime(true);

    run(function() use ($numTasks) {
        $futures = [];
        for ($i = 0; $i < $numTasks; $i++) {
            $futures[] = go(function() {
                sleep(0.1);
                return true;
            });
        }

        // Wait for all tasks to complete
        foreach ($futures as $future) {
            await($future);
        }
    });

    $endTime = microtime(true);
    $elapsed = $endTime - $startTime;

    // Ensure that the elapsed time is reasonable
    expect($elapsed)->toBeLessThan(2); // Assuming all tasks complete within 1 second
});

test('CPU-bound tasks', function() {
    $numTasks = 1000;
    $startTime = microtime(true);

    run(function() use ($numTasks) {
        $futures = [];
        for ($i = 0; $i < $numTasks; $i++) {
            $futures[] = go(function() {
                // Perform CPU-bound computation (e.g., factorial calculation)
                $result = 1;
                for ($j = 1; $j <= 1000; $j++) {
                    $result *= $j;
                }
                return $result;
            });
        }

        // Wait for all tasks to complete
        foreach ($futures as $future) {
            await($future);
        }
    });

    $endTime = microtime(true);
    $elapsed = $endTime - $startTime;

    // Ensure that the elapsed time is reasonable
    expect($elapsed)->toBeLessThan(1); // Assuming all tasks complete within 1 second
});

test('mixed I/O and CPU-bound tasks', function() {
    $numTasks = 5000;
    $startTime = microtime(true);

    run(function() use ($numTasks) {
        $futures = [];
        for ($i = 0; $i < $numTasks; $i++) {
            if ($i % 2 == 0) {
                // I/O-bound task
                $futures[] = go(function() {
                    sleep(0.01);
                    return true;
                });
            } else {
                // CPU-bound task
                $futures[] = go(function() {
                    // Perform CPU-bound computation
                    $result = 1;
                    for ($j = 1; $j <= 1000; $j++) {
                        $result *= $j;
                    }
                    return $result;
                });
            }
        }

        // Wait for all tasks to complete
        foreach ($futures as $future) {
            await($future);
        }
    });

    $endTime = microtime(true);
    $elapsed = $endTime - $startTime;

    // Ensure that the elapsed time is reasonable
    expect($elapsed)->toBeLessThan(1); // Assuming all tasks complete within 1 second
});
