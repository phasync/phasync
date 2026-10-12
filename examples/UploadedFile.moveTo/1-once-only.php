<?php

use phasync\Psr\StringStream;
use phasync\Psr\UploadedFile;

// From a stream, as a server that parsed multipart data in memory makes it
$file   = new UploadedFile(new StringStream('report contents'), 'report.txt', 'text/plain', 15);
$target = sys_get_temp_dir() . '/report-' . getmypid() . '.txt';   // must not exist yet

$file->moveTo($target);
echo file_get_contents($target), "\n";

try {
    $file->moveTo($target);               // PSR-7: a second move fails
} catch (RuntimeException $e) {
    echo get_class($e), "\n";
}
unlink($target);

$failed = new UploadedFile('', 'big.iso', 'application/octet-stream', 0, UPLOAD_ERR_INI_SIZE);
try {
    $failed->moveTo($target);
} catch (RuntimeException $e) {
    echo 'upload error ', $failed->getError(), ': ', get_class($e), "\n";
}
