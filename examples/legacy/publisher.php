<?php
use phasync\Publisher\Publisher;
use function phasync\{run, go, sleep};

require __DIR__ . '/../vendor/autoload.php';

/**
 * This example demonstrates how a Publisher can be used
 * to provide a single message stream to many subscribing
 * coroutines.
 * 
 * WARNING! The Publisher class is not production ready. In
 * the below example, the $eventPublisher is referenced by the
 * coroutines that read from the event publisher. If the 
 * $eventPublisher->close() method is not invoked, a deadlock
 * can occur.
 */
run(function() {
    $eventPublisher = new Publisher();

    // Coroutine 1 - Reacts to events
    go(function() use ($eventPublisher) {
        $channel = $eventPublisher->subscribe();
        echo "Subscriber 1 waiting\n";
        while (null !== ($event = $channel->read())) {
            echo "Subscriber 1: Received event - $event\n";
        }
    });

    // Coroutine 2 - Also reacts to events, perhaps differently
    go(function() use ($eventPublisher) {
        $count = 2;
        $channel = $eventPublisher->subscribe();
        echo "Subscriber 2 waiting\n";
        while (null !== ($event = $channel->read())) {
            echo "Subscriber 2: Received event - $event\n";
            if (--$count === 0) return;
        }
    });

    // Coroutine 3 - Also reacts to events, perhaps differently
    go(function() use ($eventPublisher) {
        $channel = $eventPublisher->subscribe();
        echo "Subscriber 3 waiting\n";
        while (null !== ($event = $channel->read())) {
            echo "Subscriber 3: Received event - $event\n";
        }
    });

    // Event Generator (in the main flow)
    for ($i = 0; $i < 5; $i++) {
        echo "Writing\n";
        $eventPublisher->write("Event #$i");
        sleep(1); // Simulate some time between events
    }

    $eventPublisher->close();
});
