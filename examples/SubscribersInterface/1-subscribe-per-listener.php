<?php

phasync::run(function () {
    phasync::publisher($subscribers, $publisher);

    $total = 0;
    phasync::go(function () use ($subscribers) {
        foreach ($subscribers->subscribe() as $price) {
            echo "ticker: $price\n";
        }
    });
    phasync::go(function () use ($subscribers, &$total) {
        foreach ($subscribers->subscribe() as $price) {
            $total += $price;
        }
        echo "sum: $total\n";
    });

    foreach ([101, 99, 103] as $price) {
        $publisher->write($price);
    }
    $publisher->close();
});
