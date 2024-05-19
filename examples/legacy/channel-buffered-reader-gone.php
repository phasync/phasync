<?php

use phasync\Legacy\Channel\Channel;
use function phasync\{run, go, sleep};

require __DIR__ . '/../vendor/autoload.php';

/**
 * This example demonstrates how the writer will
 * throw an exception if no possible readers will
 * ever read the message.
 */
$channel = run(function() {
    $channel = new Channel(1);
    $writer = $channel->getWriter();

    go(static function() use ($writer) {
        echo "Writing to channel\n";
        $writer->write("Single message");
        echo "Wrote to channel\n";
    });
});

echo "Completed\n";