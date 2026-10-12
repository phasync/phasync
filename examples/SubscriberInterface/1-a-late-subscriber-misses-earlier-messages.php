<?php

phasync::run(function () {
    phasync::publisher($subscribers, $publisher);

    $early = $subscribers->subscribe();
    $publisher->write('first');
    phasync::sleep(0.01);
    $late = $subscribers->subscribe();
    $publisher->write('second');
    $publisher->close();

    foreach (['early' => $early, 'late' => $late] as $name => $subscription) {
        phasync::go(function () use ($name, $subscription) {
            foreach ($subscription as $message) {
                echo "$name: $message\n";
            }
        });
    }
});
