<?php

use phasync\Util\Process;
use phasync\Util\ProcessInterface;

phasync::run(function () {
    $process = Process::run(PHP_BINARY, ['-r', 'usleep(50000); echo "done";']);
    $stdout  = $process->getStream(ProcessInterface::STDOUT);

    try {
        phasync::readable($stdout, 0.01);
    } catch (phasync\TimeoutException) {
        echo "no output within 0.01 s\n";
    }
    phasync::readable($stdout);
    echo 'output: ', $process->read(), "\n";
});
