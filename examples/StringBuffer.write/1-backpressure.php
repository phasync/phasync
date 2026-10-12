<?php

use phasync\Util\StringBuffer;

phasync::run(function () {
    $buffer = new StringBuffer(maxSize: 8);

    phasync::go(function () use ($buffer) {
        foreach (['aaaa', 'bbbb', 'cccc', 'dddd'] as $chunk) {
            $buffer->write($chunk);       // waits while 8 bytes are unread
            echo "wrote $chunk\n";
        }
        $buffer->end();
    });

    while (!$buffer->eof()) {
        $data = $buffer->read(4);
        if ('' !== $data) {
            echo "  read $data\n";
        }
    }
});
