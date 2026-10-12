<?php

use phasync\ChannelException;

phasync::run(function () {
    phasync::channel($read, $write);
    try {
        $read->read();   // nobody else uses the channel: likely a deadlock
    } catch (ChannelException $e) {
        echo 'without activate(): ', $e->getMessage(), "\n";
    }

    phasync::channel($read, $write);
    $read->activate();   // the writer comes later, on purpose
    phasync::go(function () use ($write) {
        phasync::sleep(0.2);
        $write->write('worth the wait');
    });
    echo 'with activate(): ', $read->read(), "\n";
});
