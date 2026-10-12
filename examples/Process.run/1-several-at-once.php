<?php

use phasync\Util\Process;

phasync::run(function () {
    $start = microtime(true);
    $jobs  = [];
    foreach (['first', 'second', 'third'] as $name) {
        $jobs[$name] = phasync::go(function () use ($name) {
            // No shell: arguments are passed as they are, so quoting is never needed
            $process = Process::run(PHP_BINARY, ['-r', 'usleep(200000); echo getenv("JOB"), " ", getcwd();'], cwd: sys_get_temp_dir(), env: ['JOB' => $name]);
            $output = '';
            while ('' !== ($chunk = $process->read())) {
                $output .= $chunk;
            }

            return $output;
        });
    }
    foreach ($jobs as $name => $job) {
        echo str_replace(sys_get_temp_dir(), '<tmp>', phasync::await($job)), "\n";
    }
    printf("three 0.2 s processes ran side by side: %s\n", microtime(true) - $start < 0.6 ? 'yes' : 'no');
});
