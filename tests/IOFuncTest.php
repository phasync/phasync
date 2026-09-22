<?php

it('can asynchronously read file contents', function () {
    $filename        = 'testfile.txt';
    $expectedContent = 'Hello, world!';
    \file_put_contents($filename, $expectedContent);

    $result = phasync::run(function () use ($filename) {
        return \phasync\file_get_contents($filename);
    });

    expect($result)->toBe($expectedContent);
    \unlink($filename);
});

it('can asynchronously write file contents', function () {
    $filename = 'testfile.txt';
    $data     = 'Hello, async world!';

    $result = phasync::run(function () use ($filename, $data) {
        return \phasync\file_put_contents($filename, $data);
    });

    expect($result)->toBe(\strlen($data));
    expect(\file_get_contents($filename))->toBe($data);
    \unlink($filename);
});

it('can asynchronously lock and unlock a file', function () {
    $filename = 'testfile.lock';
    \file_put_contents($filename, 'Locked file.');

    $result = phasync::run(function () use ($filename) {
        $stream = \fopen($filename, 'r+');
        $locked = \phasync\flock($stream, \LOCK_EX);

        return $locked;
    });

    expect($result)->toBeTrue();
    \unlink($filename);
});
