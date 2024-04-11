<?php
use phasync\WaitGroup;
use function phasync\{run, go, file_get_contents};

require(__DIR__ . '/../vendor/autoload.php');

$fileList = ['large_file_1.txt', 'large_file_2.txt', 'large_file_3.txt'];

run(function() use ($fileList) {
    $waitGroup = new WaitGroup();

    foreach ($fileList as $file) {
        $waitGroup->add(); // Signal that we have one more task
        go(function() use ($file, $waitGroup) {
            try {
                $contents = file_get_contents($file);
                echo "Finished processing: $file\n";
            } finally {
                $waitGroup->done(); // Signal task is done
            }
        });
    }

    $waitGroup->wait(); // Wait for all file processing to finish
    echo "All files processed!\n";
});