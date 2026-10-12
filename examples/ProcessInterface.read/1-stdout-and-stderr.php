<?php

use phasync\Util\Process;
use phasync\Util\ProcessInterface;

phasync::run(function () {
    $process = Process::run(PHP_BINARY, ['-r', 'fwrite(STDERR, "warning: disk almost full\n"); echo "42\n";']);

    echo 'stdout: ', $process->read(ProcessInterface::STDOUT);
    echo 'stderr: ', $process->read(ProcessInterface::STDERR);
});
