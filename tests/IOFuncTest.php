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

it('can asynchronously read stream contents', function () {
    $filename        = 'testfile.txt';
    $expectedContent = 'Hello, stream!';
    \file_put_contents($filename, $expectedContent);

    $result = phasync::run(function () use ($filename) {
        $stream = \fopen($filename, 'r');

        return \phasync\stream_get_contents($stream);
    });

    expect($result)->toBe($expectedContent);
    \unlink($filename);
});

it('can asynchronously write stream contents', function () {
    $filename = 'testfile.txt';
    $data     = 'Hello, stream write!';

    $result = phasync::run(function () use ($filename, $data) {
        $stream = \fopen($filename, 'w');
        \phasync\fwrite($stream, $data);
        \fclose($stream);
    });

    expect(\file_get_contents($filename))->toBe($data);
    \unlink($filename);
});

it('can asynchronously read a line from a file', function () {
    $filename        = 'testfile.txt';
    $expectedContent = "Line 1\nLine 2\nLine 3";
    \file_put_contents($filename, $expectedContent);

    $result = phasync::run(function () use ($filename) {
        $stream = \fopen($filename, 'r');

        return \phasync\fgets($stream);
    });

    expect($result)->toBe("Line 1\n");
    \unlink($filename);
});

it('can asynchronously write a CSV line to a file', function () {
    $filename        = 'testfile.csv';
    $data            = ['Name', 'Age', 'Email'];
    $expectedContent = 'Name,Age,Email';

    $result = phasync::run(function () use ($filename, $data) {
        $stream = \fopen($filename, 'w');
        \phasync\fputcsv($stream, $data);
        \fclose($stream);
    });

    expect(\trim(\file_get_contents($filename)))->toBe($expectedContent);
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

it('can asynchronously truncate a file', function () {
    $filename       = 'testfile.truncate';
    $initialContent = 'This is some long content.';
    \file_put_contents($filename, $initialContent);

    $result = phasync::run(function () use ($filename) {
        $stream = \fopen($filename, 'r+');
        \phasync\ftruncate($stream, 10);
        \fclose($stream);
    });

    expect(\file_get_contents($filename))->toBe('This is so');
    \unlink($filename);
});
