<?php
use phasync\Channel;
use function phasync\{run, go, sleep};

require(__DIR__ . '/../vendor/autoload.php');

run(function() {
    $channel = new Channel(2); // A buffer size of 2

    // Producer Coroutine
    go(function() use ($channel) {
        for ($i = 0; $i < 5; $i++) {
            $task = "Task $i";
            echo "Producer: Sending $task\n";
            $channel->write($task);
        }
        $channel->close();
    });

    // Consumer Coroutine
    go(function() use ($channel) {
        while (null !== ($task = $channel->read())) { 
            echo "Consumer: Received $task\n";
            // Simulate some processing time
            sleep(1); 
        }
        echo "Consumer: Channel closed\n";
    });
});