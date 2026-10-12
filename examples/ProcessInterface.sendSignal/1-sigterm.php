<?php

use phasync\Util\Process;

phasync::run(function () {
    $child = Process::run('sh', ['-c', 'trap "echo cleaning up; exit 3" TERM; echo ready; while true; do sleep 0.01; done']);
    echo 'child: ', $child->read();       // wait until it has set its handler

    var_dump($child->sendSignal(15));     // SIGTERM
    echo 'child: ', $child->read();
    while ($child->isRunning()) {
        phasync::sleep(0.01);
    }
    echo 'exit code: ', $child->getExitCode(), "\n";
    var_dump($child->sendSignal(15));     // not running any more: nothing is sent
});
