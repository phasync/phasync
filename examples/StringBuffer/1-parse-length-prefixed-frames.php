<?php

use phasync\Util\StringBuffer;

phasync::run(function () {
    $buffer = new StringBuffer();

    // The writer delivers bytes in arbitrary pieces, as a socket does
    phasync::go(function () use ($buffer) {
        $wire = pack('n', 5) . 'hello' . pack('n', 6) . 'frames';
        foreach (str_split($wire, 3) as $piece) {
            $buffer->write($piece);
            phasync::sleep(0.001);
        }
        $buffer->end();
    });

    // The reader asks for whole frames: a 2-byte length, then that many bytes
    while (null !== ($header = $buffer->readFixed(2))) {
        $length = unpack('n', $header)[1];
        echo "frame of $length bytes: ", $buffer->readFixed($length), "\n";
    }
    var_dump($buffer->eof());
});
