<?php

use function phasync\go;
use phasync\Legacy\Channel\Channel;
use function phasync\run;
use function phasync\sleep;

require __DIR__ . '/../vendor/autoload.php';

/*
 * This example demonstrates how the channel is automatically
 * closed (read() returns null) when no writer can ever write
 * to the channel.
 */
run(function () {
    [$reader, $writer] = Channel::create(2);

    go(static function () use ($reader) {
        while (null !== ($task = $reader->read())) {
            echo "Consumer: Received $task\n";
            // Simulate some processing time
            sleep(0.1);
        }
        echo "Consumer: Channel closed\n";
    });

    go(function () use ($writer) {
        $writer->write('Single message');
    });
});

echo "Completed\n";
