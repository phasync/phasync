<?php

use phasync\Util\Process;

phasync::run(function () {
    $process = Process::run('echo', ['hello from a child process']);

    echo $process->read();
    while ($process->isRunning()) {
        phasync::sleep(0.01);
    }
    echo 'exit code: ', $process->getExitCode(), "\n";
});
