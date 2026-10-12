<?php

use function phasync\file_put_contents;

$log = sys_get_temp_dir() . '/phasync-example-' . getmypid() . '.log';

phasync::run(function () use ($log) {
    file_put_contents($log, "started\n");
    foreach (['worker 1', 'worker 2'] as $worker) {
        phasync::go(fn () => file_put_contents($log, "$worker done\n", FILE_APPEND | LOCK_EX));
    }
});

echo \file_get_contents($log);
unlink($log);
