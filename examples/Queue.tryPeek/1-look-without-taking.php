<?php

use phasync\Util\Queue;

$queue = new Queue();
$queue->enqueue(['id' => 7, 'attempts' => 3]);

if ($queue->tryPeek($next) && $next['attempts'] >= 3) {
    echo "job {$next['id']} has failed {$next['attempts']} times; still queued: ", count($queue), "\n";
}
