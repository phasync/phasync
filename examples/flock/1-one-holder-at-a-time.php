<?php

use function phasync\flock;

$path = sys_get_temp_dir() . '/phasync-example-' . getmypid() . '.lock';

phasync::run(function () use ($path) {
    foreach (['job A', 'job B'] as $job) {
        phasync::go(function () use ($path, $job) {
            $fp = fopen($path, 'c');   // each its own handle, as two processes would have
            flock($fp, LOCK_EX);       // waits for the other holder, letting coroutines run
            echo "$job has the lock\n";
            phasync::sleep(0.01);
            echo "$job releases it\n";
            flock($fp, LOCK_UN);
            fclose($fp);
        });
    }
});

unlink($path);
