<?php

require('vendor/autoload.php');

phasync::run(function() {

    phasync::publisher($publisher, $channel);

    phasync::go(function() use ($channel) {
        for ($i = 0; $i < 1000; $i++) {
            $channel->write('.');
        }
    });

    phasync::go(function() use ($publisher) {
        echo "Reader 1 subscribing\n";
        $reader = $publisher->subscribe();

        while ($message = $reader->read()) {
            echo "1 >>>" . $message . "\n";
        }
    });

    phasync::go(function() use ($publisher) {
        echo "Reader 2 subscribing\n";
        $reader = $publisher->subscribe();

        while ($message = $reader->read()) {
            echo "2 >>>" . $message . "\n";
            phasync::sleep(0.001);
        }
    });

});