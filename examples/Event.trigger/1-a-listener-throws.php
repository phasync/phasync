<?php

use phasync\Util\Event;

$event = new Event();
$event->listen(fn (int $n) => print("first listener: $n\n"));
$event->listen(function (int $n) {
    throw new RuntimeException("listener failed on $n");
});
$event->listen(fn (int $n) => print("third listener: $n\n"));   // not reached

try {
    $event->trigger(42);
} catch (RuntimeException $e) {
    echo 'trigger() threw: ', $e->getMessage(), "\n";
}
