<?php

use phasync\Util\StringBuffer;

phasync::run(function () {
    $buffer = new StringBuffer();
    $buffer->write('ABCDEFG');
    $buffer->end();

    var_dump($buffer->readFixed(4));   // "ABCD"
    var_dump($buffer->readFixed(4));   // only 3 left at the end: null
    var_dump($buffer->read(10));       // the 3 bytes are still there
});
