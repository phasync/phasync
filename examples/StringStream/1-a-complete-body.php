<?php

use phasync\Psr\StringStream;

$stream = new StringStream('Hello, world');

echo $stream->read(5), "\n";
echo 'position: ', $stream->tell(), ', size: ', $stream->getSize(), "\n";
echo $stream->getContents(), "\n";            // the rest
var_dump($stream->eof());

$stream->seek(-5, SEEK_END);
echo $stream->read(5), "\n";
echo (string) $stream, "\n";                  // the whole content, whatever the position
var_dump($stream->isWritable());
