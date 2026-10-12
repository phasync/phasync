<?php

// Long work that yields now and then lets the other coroutines run in between
phasync::run(function () {
    foreach (['red', 'blue'] as $name) {
        phasync::go(function () use ($name) {
            for ($i = 1; $i <= 3; ++$i) {
                echo "$name $i\n";
                phasync::yield();
            }
        });
    }
});
