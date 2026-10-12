<?php

use phasync\Util\StringBuffer;

phasync::run(function () {
    $buffer = new StringBuffer();
    $buffer->write("GET / HTTP/1.1\r\n");
    $buffer->end();

    $start = $buffer->readFixed(4);
    echo 'sniffed: ', var_export($start, true), "\n";
    $buffer->unread($start);              // put it back for the real parser

    echo 'parser reads: ', var_export($buffer->read(100), true), "\n";
});
