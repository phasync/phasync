<?php

// Two stages connected by channels; each stage is a coroutine
phasync::run(function () {
    phasync::channel($numbers, $numbersIn, 2);
    phasync::channel($squares, $squaresIn, 2);

    phasync::go(function () use ($numbersIn) {
        foreach (range(1, 5) as $n) {
            $numbersIn->write($n);
        }
        $numbersIn->close();
    });

    phasync::go(function () use ($numbers, $squaresIn) {
        foreach ($numbers as $n) {
            $squaresIn->write($n * $n);
        }
        $squaresIn->close();
    });

    foreach ($squares as $square) {   // ends when the second stage closes
        echo $square, "\n";
    }
});
