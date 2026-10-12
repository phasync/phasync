<?php

use phasync\Util\Queue;
use phasync\Util\QueueInterface;

function flush_log(QueueInterface $lines): void
{
    // lock() keeps other coroutines from adding lines between these steps
    $lines->lock(function () use ($lines) {
        echo count($lines), " lines:\n";
        while ($lines->tryDequeue($line)) {
            echo "  $line\n";
        }
    });
}

phasync::run(function () {
    $lines = new Queue();
    $lines->enqueue('GET /');
    $lines->enqueue('GET /about');
    flush_log($lines);
    var_dump($lines->isEmpty());
});
