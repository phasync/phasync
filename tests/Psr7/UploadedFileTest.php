<?php

namespace Tests\Psr7;

use Http\Psr7Test\UploadedFileIntegrationTest;
use phasync\Psr\UploadedFile;

/*
 * The php-http/psr7-integration-tests suite (what Nyholm/psr7 and others run) against phasync's own PSR-7 classes.
 */
final class UploadedFileTest extends UploadedFileIntegrationTest
{
    public function createSubject()
    {
        $path = \tempnam(\sys_get_temp_dir(), 'phasync-upload');
        \file_put_contents($path, 'writing to tempfile');

        return new UploadedFile($path, 'file.txt', 'text/plain', 19, \UPLOAD_ERR_OK, true);
    }
}
