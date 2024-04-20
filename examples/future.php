<?php
require("../vendor/autoload.php");

use function phasync\{await, run, go, sleep};

$start_time = microtime(true);

/**
 * This example demonstrates how a future value can be awaited.
 * When awaiting, the coroutine is blocked until the other
 * coroutine completes generating the result.
 */
run(function() {
    echo "Setting up a future value to return in 1 second.\n";
    // A single result will be provided
    $future = go(function() {
        sleep(1);
        echo "Value is being returned.\n";
        return microtime(true);
    });

    for ($i = 0; $i < 10; $i++) {
        go(function() use ($i, $future) {
            $result = await($future);
            echo "Waiting routine $i received: $result\n";
        });
    }
});

echo "Total time is " . number_format(microtime(true) - $start_time, 3) . " seconds\n";
echo "Peak memory usage was " . memory_get_peak_usage() . "\n";