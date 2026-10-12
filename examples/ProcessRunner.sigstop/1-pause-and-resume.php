<?php

use phasync\Util\Process;

phasync::run(function () {
    $process = Process::run('sleep', ['30']);

    $process->sigstop();
    phasync::sleep(0.05);
    var_dump($process->isStopped());

    $process->sigcont();
    phasync::sleep(0.05);
    var_dump($process->isStopped());

    $process->sigkill();                  // cannot be caught
    while ($process->isRunning()) {
        phasync::sleep(0.01);
    }
    echo "killed\n";
});
