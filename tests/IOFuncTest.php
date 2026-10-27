<?php

it('can asynchronously read file contents', function () {
    $filename        = \tempnam(\sys_get_temp_dir(), 'phasync');
    $expectedContent = 'Hello, world!';
    \file_put_contents($filename, $expectedContent);

    $result = phasync::run(function () use ($filename) {
        return \phasync\file_get_contents($filename);
    });

    expect($result)->toBe($expectedContent);
    \unlink($filename);
});

it('can asynchronously write file contents', function () {
    $filename = \tempnam(\sys_get_temp_dir(), 'phasync');
    $data     = 'Hello, async world!';

    $result = phasync::run(function () use ($filename, $data) {
        return \phasync\file_put_contents($filename, $data);
    });

    expect($result)->toBe(\strlen($data));
    expect(\file_get_contents($filename))->toBe($data);
    \unlink($filename);
});

it('can asynchronously lock and unlock a file', function () {
    $filename = \tempnam(\sys_get_temp_dir(), 'phasync');
    \file_put_contents($filename, 'Locked file.');

    $result = phasync::run(function () use ($filename) {
        $stream = \fopen($filename, 'r+');
        $locked = \phasync\flock($stream, \LOCK_EX);

        return $locked;
    });

    expect($result)->toBeTrue();
    \unlink($filename);
});

it('writes a stream resource read to its end, as the native function does (#76)', function () {
    $filename = \tempnam(\sys_get_temp_dir(), 'phasync');
    try {
        $result = phasync::run(function () use ($filename) {
            $memory = \fopen('php://memory', 'w+');
            \fwrite($memory, \str_repeat('m', 100000));
            \rewind($memory);
            $fromMemory = \phasync\file_put_contents($filename, $memory);
            $inFile     = \file_get_contents($filename);

            [$a, $b] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
            phasync::go(function () use ($b) {
                foreach (['first ', 'second'] as $chunk) {
                    phasync::sleep(0.01);
                    \fwrite($b, $chunk);
                }
                \fclose($b);
            });
            $fromSocket = \phasync\file_put_contents($filename, $a, \FILE_APPEND);

            return [$fromMemory, $inFile, $fromSocket, \stream_get_meta_data($a)['blocked']];
        });
        expect($result)->toBe([100000, \str_repeat('m', 100000), 12, true]);
        expect(\file_get_contents($filename))->toBe(\str_repeat('m', 100000) . 'first second');
    } finally {
        \unlink($filename);
    }
});

it('throws for a missing file without a PHP warning, and the exception says why (#101)', function () {
    $warnings = [];
    \set_error_handler(function (int $code, string $message) use (&$warnings) {
        $warnings[] = $message;

        return true;
    });
    try {
        [$exception, $putException] = phasync::run(function () {
            $result = [];
            try {
                \phasync\file_get_contents(__DIR__ . '/no-such-file.txt');
            } catch (Exception $e) {
                $result[] = $e;
            }
            try {
                \phasync\file_put_contents(__DIR__ . '/no-such-dir/file.txt', 'x');
            } catch (Exception $e) {
                $result[] = $e;
            }

            return $result;
        });
    } finally {
        \restore_error_handler();
    }
    expect($warnings)->toBe([]);
    expect($exception->getMessage())->toContain('no-such-file.txt')->toContain('No such file or directory');
    expect($putException->getMessage())->toContain('no-such-dir/file.txt')->toContain('No such file or directory');
});
