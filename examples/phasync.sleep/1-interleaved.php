<?php

phasync::run(function () {
    foreach (['a' => 0.02, 'b' => 0.05] as $name => $interval) {
        phasync::go(function () use ($name, $interval) {
            for ($i = 1; $i <= 3; ++$i) {
                phasync::sleep($interval);
                echo "$name$i ";
            }
        });
    }
});
echo "\n";
