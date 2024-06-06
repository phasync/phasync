<?php

use phasync\Util\WaitGroup;

require '../vendor/autoload.php';

use function phasync\await;
use function phasync\go;
use function phasync\run;
use function phasync\sleep;

$start_time = \microtime(true);

/**
 * This example demonstrates how an exception is thrown
 * when many coroutines are waiting for a single future
 * result.
 */
$exceptions_handled = run(function () {
    echo "Setting up a future value which throws an exception.\n";

    // A single result will be provided
    $future = go(function () {
        sleep(1);
        echo "The exception is being thrown.\n";
        throw new Exception('Exception from future value');
    });

    $count = 0;

    $wg = new WaitGroup();

    $lt = \microtime(true);
    for ($i = 0; $i < 5000; ++$i) {
        go(function () use (&$count, $future, $wg) {
            $wg->add();
            try {
                $result = await($future);
                echo "No exception\n";
            } catch (Throwable $e) {
                ++$count;
            }
            $wg->done();
        });
    }
    echo 'Launched 5000 coroutines in ' . (\microtime(true) - $lt) . " seconds\n";

    $wg->await();

    return $count;
});

echo "Handled $exceptions_handled exceptions.\n";

echo 'Total time is ' . \number_format(\microtime(true) - $start_time, 3) . " seconds\n";
echo 'Peak memory usage was ' . \memory_get_peak_usage() . "\n";
