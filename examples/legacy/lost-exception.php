<?php

use function phasync\{run, go, sleep, await};

require(__DIR__ . '/../vendor/autoload.php');

/**
 * This example demonstrates how an exception is surfaced to the top
 * scope, even if the exception occurs inside a coroutine which has
 * nobody waiting for it. The coroutine would normally be garbage
 * collected at the end of the run()-function.
 */
$t = microtime(true);
try {
    run(function() {
        $task = go(function() {
            sleep(0.1);
            throw new Exception("This exception will not be handled");
        });
        await($task); // Wait for the coroutine to finish and handle its exception
    });
} catch (Exception $e) {
    echo "Caught exception: " . $e->getMessage() . "\n";
}
echo "Time: " . (microtime(true) - $t) . " seconds\n";
