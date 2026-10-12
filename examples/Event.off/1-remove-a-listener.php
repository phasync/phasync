<?php

use phasync\Util\Event;

$tick    = new Event();
$printer = function (int $n) {
    echo "tick $n\n";
};
$tick->listen($printer);

$tick->trigger(1);
$tick->off($printer);        // the same closure object that was added
$tick->trigger(2);

var_dump($tick->hasListeners());
