<?php

phasync::run(function () {
    $poller = phasync::go(function () {
        for ($round = 1; $round <= 2; ++$round) {
            try {
                phasync::sleep(10);   // waits for the next round
            } catch (RuntimeException $e) {
                echo "round $round: woken by '", $e->getMessage(), "'\n";
            }
        }

        return 'carried on after both';
    });

    phasync::throw($poller, new RuntimeException('check now'));
    phasync::sleep(0.01);
    phasync::throw($poller, new RuntimeException('check again'));
    echo phasync::await($poller), "\n";
});
