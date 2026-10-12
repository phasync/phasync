<?php

use phasync\Util\Process;

phasync::run(function () {
    $child = Process::run('sh', ['-c', 'trap "echo stopping after Ctrl+C; exit 130" INT; echo working; while true; do sleep 0.01; done']);
    echo $child->read();

    $child->sigint();
    echo $child->read();
    while ($child->isRunning()) {
        phasync::sleep(0.01);
    }
    echo 'exit code: ', $child->getExitCode(), "\n";
});
