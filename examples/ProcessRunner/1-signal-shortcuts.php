<?php

use phasync\Util\Process;
use phasync\Util\ProcessRunner;

phasync::run(function () {
    $child = Process::run('sh', ['-c', 'trap "echo reloading" HUP; trap "echo interrupted; exit 130" INT; echo ready; while true; do sleep 0.01; done']);
    var_dump($child instanceof ProcessRunner);
    echo $child->read();

    $child->sighup();                     // the same as sendSignal(1)
    echo $child->read();
    $child->sigint();                     // the same as sendSignal(2): Ctrl+C
    echo $child->read();

    while ($child->isRunning()) {
        phasync::sleep(0.01);
    }
    echo 'exit code: ', $child->getExitCode(), "\n";
});
