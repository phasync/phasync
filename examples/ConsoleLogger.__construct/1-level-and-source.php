<?php

use phasync\Util\Console;
use phasync\Util\ConsoleLogger;

$stream = fopen('php://memory', 'w+');
$logger = new ConsoleLogger(new Console($stream, color: false), level: 'warning', source: 'worker-2');

$logger->info('not written: below warning');
$logger->error('written, with the source column');

rewind($stream);
echo preg_replace('/^\S+ \S+ /m', '', stream_get_contents($stream));   // without the time

try {
    new ConsoleLogger(level: 'verbose');
} catch (InvalidArgumentException $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
