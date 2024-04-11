<?php
use phasync\Publisher;
use function phasync\{run, go, sleep};

require(__DIR__ . '/../vendor/autoload.php');

run(function() {
    $eventPublisher = new Publisher();

    // Coroutine 1 - Reacts to events
    go(function() use ($eventPublisher) {
        $channel = $eventPublisher->subscribe();
        while (null !== ($event = $channel->read())) {
            echo "Coroutine 1: Received event - $event\n";
        }
    });

    // Coroutine 2 - Also reacts to events, perhaps differently
    go(function() use ($eventPublisher) {
        $channel = $eventPublisher->subscribe();
        while (null !== ($event = $channel->read())) {
            echo "Coroutine 2: Event received - $event\n";
        }
    });

    // Event Generator (in the main flow)
    for ($i = 0; $i < 5; $i++) {
        $eventPublisher->publish("Event #$i");
        sleep(1); // Simulate some time between events
    }

    $eventPublisher->close();
});
