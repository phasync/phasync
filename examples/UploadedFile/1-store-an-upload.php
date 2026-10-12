<?php

use phasync\Psr\UploadedFile;

$tmp = tempnam(sys_get_temp_dir(), 'upload');
file_put_contents($tmp, 'PNG image bytes');

// As a server builds it from $_FILES: the name and type are what the client claimed
$file = new UploadedFile($tmp, '../../avatar.png', 'image/png', 15, UPLOAD_ERR_OK);

echo 'claimed name: ', $file->getClientFilename(), "\n";
echo 'safe name:    ', basename($file->getClientFilename()), "\n";
echo 'size:         ', $file->getSize(), "\n";
echo 'content:      ', $file->getStream()->getContents(), "\n";

$target = sys_get_temp_dir() . '/avatar-' . getmypid() . '.png';
$file->moveTo($target);
echo 'moved:        ', is_file($target) && !is_file($tmp) ? 'yes' : 'no', "\n";
unlink($target);
