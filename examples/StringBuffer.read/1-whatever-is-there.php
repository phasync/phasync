<?php

use phasync\TimeoutException;
use phasync\Util\StringBuffer;

phasync::run(function () {
    $buffer = new StringBuffer();
    $buffer->write('abc');
    var_dump($buffer->read(100));        // returns what is buffered, less than asked for
    var_dump($buffer->read(100, 0));     // empty, and a timeout of 0 does not wait

    try {
        $buffer->read(100, 0.01);        // nothing arrives in time
    } catch (TimeoutException) {
        echo "no data within 0.01 s\n";
    }

    $buffer->end();
    var_dump($buffer->read(100));        // ended and empty
});
