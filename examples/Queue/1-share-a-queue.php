<?php

use phasync\Util\Queue;

phasync::run(function () {
    $queue = new Queue();
    foreach (['a.jpg', 'b.jpg', 'c.jpg', 'd.jpg', 'e.jpg'] as $file) {
        $queue->enqueue($file);
    }

    foreach (['worker 1', 'worker 2'] as $worker) {
        phasync::go(function () use ($queue, $worker) {
            while ($queue->tryDequeue($file)) {
                echo "$worker resizes $file\n";
                phasync::sleep(0.01);
            }
            echo "$worker: queue empty\n";
        });
    }
});
