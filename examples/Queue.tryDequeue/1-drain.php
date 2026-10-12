<?php

use phasync\Util\Queue;

$queue = new Queue();
$queue->enqueue('first');
$queue->enqueue('second');

while ($queue->tryDequeue($value)) {
    echo "took $value, ", count($queue), " left\n";
}
var_dump($queue->tryDequeue($value), $value);   // empty: false, and $value is null
