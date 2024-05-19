<?php
require "../vendor/autoload.php";

use function phasync\{run, go, fread, sleep, defer};

$start_time = microtime(true);

/**
 * This example demonstrate the defer() function, which
 * schedules a callback to be invoked when the coroutine
 * is completed. Deferred callbacks will be invoked in
 * LIFO order (Last In - First Out).
 */
run(function() {
    defer(function() {
        echo "Cleaning up\n";
    });
    sleep(1);
    echo "Done\n";
});

echo "Total time is " . number_format(microtime(true) - $start_time, 3) . " seconds\n";
echo "Peak memory usage was " . memory_get_peak_usage() . "\n";