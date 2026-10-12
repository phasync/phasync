<?php

use phasync\Util\Process;

phasync::run(function () {
    $process = Process::run('sleep', ['30']);
    var_dump($process->isRunning());

    var_dump($process->stop());           // SIGTERM ends sleep at once
    var_dump($process->isRunning());
    var_dump($process->getExitCode());    // -1: ended by a signal
});
