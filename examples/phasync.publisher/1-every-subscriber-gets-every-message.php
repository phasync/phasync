<?php

phasync::run(function () {
    phasync::publisher($subscribers, $publisher);

    foreach (['mailer', 'audit log'] as $name) {
        $subscription = $subscribers->subscribe();
        phasync::go(function () use ($subscription, $name) {
            foreach ($subscription as $event) {
                echo "$name got $event\n";
            }
            echo "$name: publisher closed\n";
        });
    }

    $publisher->write('user.created');
    $publisher->write('user.deleted');
    $publisher->close();
});
