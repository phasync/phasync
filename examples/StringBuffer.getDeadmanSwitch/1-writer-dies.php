<?php

use phasync\Util\StringBuffer;

phasync::run(function () {
    $buffer = new StringBuffer();

    phasync::go(function () use ($buffer) {
        $switch = $buffer->getDeadmanSwitch();   // held for as long as this coroutine runs
        $buffer->write('partial');
        phasync::sleep(0.01);
        // returns without calling $buffer->end(), say after an error it handled badly
    });

    echo 'read: ', $buffer->read(100), "\n";
    try {
        $buffer->read(100);                       // would wait forever without the switch
    } catch (RuntimeException $e) {
        echo 'the reader gets ', get_class($e), "\n";
    }
});
