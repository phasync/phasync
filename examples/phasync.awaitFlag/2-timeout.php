<?php

use phasync\TimeoutException;

phasync::run(function () {
    $reply = new stdClass();

    try {
        phasync::awaitFlag($reply, 0.01);
    } catch (TimeoutException) {
        echo "no reply within 0.01 s\n";
    }
});
