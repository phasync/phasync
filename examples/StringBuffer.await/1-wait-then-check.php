<?php

use phasync\Util\StringBuffer;

phasync::run(function () {
    $buffer = new StringBuffer();
    phasync::go(function () use ($buffer) {
        phasync::sleep(0.02);
        $buffer->write('ready');
    });

    $buffer->await(0.01);                 // a timeout does not throw
    var_dump($buffer->isReady());
    $buffer->await();
    var_dump($buffer->isReady(), $buffer->read(10));
});
