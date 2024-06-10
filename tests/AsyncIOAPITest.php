<?php

use phasync;

phasync::setDefaultTimeout(10);

test('test readable stream within coroutine', function () {
    expect(function () {
        phasync::run(function () {
            $fp = \fopen('php://temp', 'w+');
            \fwrite($fp, 'test data');
            \rewind($fp);

            $data = phasync::go(function () use ($fp) {
                $readableStream = phasync::readable($fp);

                return \fread($readableStream, 1024);
            });

            $result = phasync::await($data);
            expect($result)->toBe('test data');

            \fclose($fp);
        });
    })->not->toThrow(Throwable::class);
});

test('test writable stream within coroutine', function () {
    expect(function () {
        phasync::run(function () {
            $fp = \fopen('php://temp', 'w+');

            phasync::go(function () use ($fp) {
                $writableStream = phasync::writable($fp);
                \fwrite($writableStream, 'test data');
                \fflush($writableStream);
            });

            $data = phasync::go(function () use ($fp) {
                \rewind($fp);

                return \fread($fp, 1024);
            });

            $result = phasync::await($data);
            expect($result)->toBe('test data');

            \fclose($fp);
        });
    })->not->toThrow(Throwable::class);
});

test('test readable stream outside coroutine', function () {
    expect(function () {
        $fp = \fopen('php://temp', 'w+');
        \fwrite($fp, 'test data');
        \rewind($fp);
        \stream_set_blocking($fp, false);

        $readableStream = phasync::readable($fp);
        $result         = \fread($readableStream, 1024);

        expect($result)->toBe('test data');

        \fclose($fp);
    })->not->toThrow(Throwable::class);
});

test('test writable stream outside coroutine', function () {
    expect(function () {
        $fp = \fopen('php://temp', 'w+');
        \stream_set_blocking($fp, false);

        $writableStream = phasync::writable($fp);
        \fwrite($writableStream, 'test data');
        \fflush($writableStream);

        \rewind($fp);
        $result = \fread($fp, 1024);

        expect($result)->toBe('test data');

        \fclose($fp);
    })->not->toThrow(Throwable::class);
});

test('test stream function for readable state', function () {
    expect(function () {
        phasync::run(function () {
            $fp = \fopen('php://temp', 'w+');
            \fwrite($fp, 'test data');
            \rewind($fp);

            $data = phasync::go(function () use ($fp) {
                phasync::stream($fp, phasync::READABLE);

                return \fread($fp, 1024);
            });

            $result = phasync::await($data);
            expect($result)->toBe('test data');

            \fclose($fp);
        });
    })->not->toThrow(Throwable::class);
});

test('test stream function for writable state', function () {
    expect(function () {
        phasync::run(function () {
            $fp = \fopen('php://temp', 'w+');

            phasync::go(function () use ($fp) {
                phasync::stream($fp, phasync::WRITABLE);
                \fwrite($fp, 'test data');
                \fflush($fp);
            });

            $data = phasync::go(function () use ($fp) {
                \rewind($fp);

                return \fread($fp, 1024);
            });

            $result = phasync::await($data);
            expect($result)->toBe('test data');

            \fclose($fp);
        });
    })->not->toThrow(Throwable::class);
});
