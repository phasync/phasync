<?php
require "../vendor/autoload.php";

use function phasync\{run, go, fread, sleep};

$start_time = microtime(true);

/**
 * This example demonstrate various ways that coroutines
 * behave asynchronously, while appearing to be written
 * entirely synchronously.
 */
run(function() {
    echo "Launching 1000 sleepy coroutines...\n";
    for ($i = 0; $i < 1000; $i++) {
        go(function() {
            sleep(3.5);
        });
    }

    echo "Opening this file 10 times in 10 coroutines...\n";
    for ($i = 0; $i < 10; $i++) {
        go(function() {
            $fp = fopen(__FILE__, 'r');
            fread($fp, 10);
            fread($fp, 10);
            fread($fp, 10);
            fclose($fp);
        });
    }

    go(function() {
        echo "First coroutine going to sleep for 2 seconds...\n";
        sleep(2);
        echo "First coroutine finished\n";
    });
    go(function() {
        echo "Second coroutine does some quick work and finishes\n";
    });
    go(function() {
        $fp = fopen(__FILE__, "r");
        echo "Third coroutine tries to read a file...\n";
        $bytes = fread($fp, 65536);
        echo "Third coroutine finishes after reading " . strlen($bytes) ."\n";
    });
    go(function() {
        echo "Fourth coroutine does some works and sleeps for 1 second\n";
        sleep(1);
        echo "Fourth coroutine finishes\n";
    });
});

echo "Total time is " . number_format(microtime(true) - $start_time, 3) . " seconds\n";
echo "Peak memory usage was " . memory_get_peak_usage() . "\n";