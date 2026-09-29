<?php

// A timeout that fires once the process has no file descriptors left
require __DIR__ . '/../../vendor/autoload.php';

$hard = \posix_getrlimit()['hard openfiles']; // PHP 8.2 takes no resource argument
\posix_setrlimit(\POSIX_RLIMIT_NOFILE, 64, \is_int($hard) ? $hard : -1);
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
