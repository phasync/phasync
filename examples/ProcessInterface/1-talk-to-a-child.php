<?php

use phasync\Util\Process;

phasync::run(function () {
    // A child that answers each line it reads
    $child = Process::run(PHP_BINARY, ['-r', 'while (false !== ($l = fgets(STDIN))) { echo strtoupper($l); }']);

    foreach (["ping\n", "hello\n"] as $line) {
        $child->write($line);
        echo 'child said: ', $child->read();
    }
    $child->stop();
    var_dump($child->isRunning());
});
