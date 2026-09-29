<?php

// A timeout that fires once the process has no file descriptors left
require __DIR__ . '/../../vendor/autoload.php';

\posix_setrlimit(\POSIX_RLIMIT_NOFILE, 64, \posix_getrlimit(\POSIX_RLIMIT_NOFILE)[1]);
phasync::run(static function () {
    \class_exists(phasync\Internal\Flag::class); // flags in use already, as in a running server
    $files = [];
    while (false !== $f = @\fopen('/dev/null', 'r')) {
        $files[] = $f;
    }
    try {
        phasync::awaitFlag($flag = new stdClass(), 0.01);
    } catch (Throwable $e) {
        echo \get_class($e);
    }
});
