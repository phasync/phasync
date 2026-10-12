<?php

use phasync\ChannelException;

phasync::run(function () {
    phasync::channel($read, $write, 1);
    $read->close();   // closing either end closes the channel

    try {
        $write->write('late');
    } catch (ChannelException $e) {
        echo $e->getMessage(), "\n";
    }
});
