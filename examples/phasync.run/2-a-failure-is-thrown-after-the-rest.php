<?php

// A failure nobody awaits does not stop the other coroutines: run() throws it once they have ended.
try {
    phasync::run(function () {
        phasync::go(function () {
            phasync::sleep(0.05);
            echo "slow job finished\n";
        });
        phasync::go(function () {
            phasync::sleep(0.01);
            throw new RuntimeException('the database is gone');
        });
    });
} catch (RuntimeException $e) {
    echo 'run() threw: ', $e->getMessage(), "\n";
}
