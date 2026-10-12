<?php

use phasync\Util\Console;
use phasync\Util\ConsoleLogger;
use Psr\Log\LoggerInterface;

function import(LoggerInterface $logger): void
{
    $logger->debug('reading {file}', ['file' => 'users.csv']);
    $logger->warning('{count} rows skipped', ['count' => 2]);
}

$stream = fopen('php://memory', 'w+');
import(new ConsoleLogger(new Console($stream, color: false)));

rewind($stream);
echo preg_replace('/^\S+ \S+ /m', '', stream_get_contents($stream));   // without the time
