<?php
use phasync\Util\WaitGroup;
use function phasync\{run, go, file_get_contents, sleep};

require __DIR__ . '/../vendor/autoload.php';

$fileList = [__FILE__, 'coroutines.php', 'channel.php'];

/**
 * This example demonstrates how a WaitGroup can be used to
 * easily wait for many simultaneous coroutines.
 */
run(function() use ($fileList) {
    $waitGroup = new WaitGroup();

    foreach ($fileList as $file) {
        $waitGroup->add(); // Signal that we have one more task
        go(function() use ($file, $waitGroup) {
            try {
                echo "Started processing file: $file\n";
                for ($i = 0; $i < 10; $i++) {
                    $contents = file_get_contents($file);
                }
                echo "Finished processing: $file\n";
            } finally {
                $waitGroup->done(); // Signal task is done
            }
        });
    }

    $waitGroup->wait(); // Wait for all file processing to finish
    echo "All files processed!\n";
});