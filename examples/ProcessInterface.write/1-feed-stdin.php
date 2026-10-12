<?php

use phasync\Util\Process;

phasync::run(function () {
    $sort = Process::run('sort');
    $data = "pear\napple\nfig\n";
    while ('' !== $data) {
        $written = $sort->write($data);        // may take less than all of it
        $data    = substr($data, $written);
    }
    fclose($sort->getStream(0));               // sort prints once its input ends

    $sorted = '';
    while ('' !== ($chunk = $sort->read())) {
        $sorted .= $chunk;
    }
    echo $sorted;
});
