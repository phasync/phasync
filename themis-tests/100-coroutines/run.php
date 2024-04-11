<?php

use phasync\Loop;
use function phasync\{run, go, sleep};

require(__DIR__ . '/../../vendor/autoload.php');

// Parameters for the benchmark
$numberOfCoroutines = 5000; // Number of coroutines to test
$sleepDuration = 0.5; // Duration to simulate an I/O operation, in seconds

// Start measuring time
$startTime = microtime(true);

run(function() use ($numberOfCoroutines, $sleepDuration) {
    for ($i = 0; $i < $numberOfCoroutines; $i++) {
        go(function() use ($sleepDuration) {
            // Simulate an I/O operation with sleep
            sleep($sleepDuration);
            // Optionally perform some computation here
        });
    }
});

// Stop measuring time
$endTime = microtime(true);

// Calculate and display the benchmark results
$duration = $endTime - $startTime;
echo "Executed $numberOfCoroutines coroutines with $sleepDuration second sleep each in $duration seconds.\n";
