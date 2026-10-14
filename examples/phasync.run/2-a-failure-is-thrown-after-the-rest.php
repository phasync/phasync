<?php

try {
    phasync::run(function () {
        phasync::go(function () {
            try {
                phasync::sleep(1);
                echo "never printed\n";
            } finally {
                echo "slow job cleans up\n";
            }
        });
        phasync::go(function () {
            phasync::sleep(0.01);
            throw new RuntimeException('the database is gone');
        });
    });
} catch (RuntimeException $e) {
    echo 'run() threw: ', $e->getMessage(), "\n";
}
