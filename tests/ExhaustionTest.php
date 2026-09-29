<?php

test('a timeout still throws TimeoutException when the process has no file descriptors left', function () {
    if (!\function_exists('posix_setrlimit')) {
        $this->markTestSkipped('needs posix');
    }
    $output = \shell_exec(\escapeshellarg(\PHP_BINARY) . ' ' . \escapeshellarg(__DIR__ . '/Fixtures/timeout-without-descriptors.php') . ' 2>&1');
    expect($output)->toBe('phasync\TimeoutException');
});
