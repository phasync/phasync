<?php

use phasync\Legacy\Channel\Channel;
use function phasync\{run, go, sleep};

require __DIR__ . '/../vendor/autoload.php';

/**
 * This example demonstrates a common way to use channels.
 * The channel is buffered, allowing two messages to exist
 * on the channel at any one time.
 * 
 * Channels are a form of queue, where multiple worker
 * coroutines can process messages, and multiple worker 
 * coroutines can provide messages.
 */
run(function() {

    [$reader, $writer] = Channel::create(2);

    // Producer Coroutine
    go(function() use ($writer) {
        for ($i = 0; $i < 5; $i++) {
            $task = "Task $i";
            echo "Producer: Sending $task\n";
            $writer->write($task);
        }
    });

    // Consumer Coroutine
    go(function() use ($reader) {
        while (null !== ($task = $reader->read())) { 
            echo "Consumer: Received $task\n";
            // Simulate some processing time
            sleep(1); 
        }
        echo "Consumer: Channel closed\n";
    });
});

echo "Completed\n";