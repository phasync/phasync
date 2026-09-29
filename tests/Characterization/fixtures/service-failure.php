<?php

/*
 * Fixture for ScopesTest (SCO-6): a service coroutine that throws. Run as a separate
 * process: the failure ends the run, services and all.
 */

require __DIR__ . '/../../../vendor/autoload.php';

try {
    $result = phasync::run(function () {
        phasync::service(function () {
            phasync::sleep(0.01);
            throw new RuntimeException('service failure');
        });
        phasync::sleep(0.05);

        return 'main done';
    });
    echo "run returned: $result\n";
} catch (Throwable $e) {
    echo 'run threw ' . \get_class($e) . ': ' . $e->getMessage() . "\n";
}
