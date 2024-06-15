<?php

use phasync\Services\CurlMulti;

phasync::setDefaultTimeout(10);

test('test basic curl multi functionality', function () {
    expect(function () {
        phasync::run(function () {
            $a = phasync::go(function () {
                $ch = \curl_init('https://httpbin.org/get');
                \curl_setopt($ch, \CURLOPT_RETURNTRANSFER, true);

                return CurlMulti::await($ch);
            });

            $b = phasync::go(function () {
                $ch = \curl_init('https://httpbin.org/get');
                \curl_setopt($ch, \CURLOPT_RETURNTRANSFER, true);

                return CurlMulti::await($ch);
            });

            expect(phasync::await($a))->toBe(true);
            expect(phasync::await($b))->toBe(true);
        });
    })->not->toThrow(Throwable::class);
});

test('test curl multi with error handling', function () {
    expect(function () {
        phasync::run(function () {
            $a = phasync::go(function () {
                $ch = \curl_init('http://nonexistent.domain');
                \curl_setopt($ch, \CURLOPT_RETURNTRANSFER, true);

                return CurlMulti::await($ch);
            });

            $b = phasync::go(function () {
                $ch = \curl_init('https://httpbin.org/get');
                \curl_setopt($ch, \CURLOPT_RETURNTRANSFER, true);

                return CurlMulti::await($ch);
            });

            expect(phasync::await($a))->toBe(false);
            expect(phasync::await($b))->toBe(true);
        });
    })->not->toThrow(Throwable::class);
});

test('test curl multi with invalid URL', function () {
    expect(function () {
        phasync::run(function () {
            $a = phasync::go(function () {
                $ch = \curl_init('invalid-url');
                \curl_setopt($ch, \CURLOPT_RETURNTRANSFER, true);

                return CurlMulti::await($ch);
            });

            expect(phasync::await($a))->toBe(false);
        });
    })->not->toThrow(Throwable::class);
});

test('test multiple concurrent curl requests', function () {
    expect(function () {
        phasync::run(function () {
            $handles = [];

            for ($i = 0; $i < 10; ++$i) {
                $handles[] = phasync::go(function () {
                    $ch = \curl_init('https://httpbin.org/get');
                    \curl_setopt($ch, \CURLOPT_RETURNTRANSFER, true);

                    return CurlMulti::await($ch);
                });
            }

            foreach ($handles as $handle) {
                expect(phasync::await($handle))->toBe(true);
            }
        });
    })->not->toThrow(Throwable::class);
});

test('test concurrent versus sequential execution time', function () {
    $sequentialTime = phasync::run(function () {
        $start = \microtime(true);

        for ($i = 0; $i < 5; ++$i) {
            $ch = \curl_init('https://httpbin.org/delay/1');
            \curl_setopt($ch, \CURLOPT_RETURNTRANSFER, true);
            CurlMulti::await($ch);
        }

        return \microtime(true) - $start;
    });

    $concurrentTime = phasync::run(function () {
        $start   = \microtime(true);
        $handles = [];

        for ($i = 0; $i < 3; ++$i) {
            $handles[] = phasync::go(function () {
                $ch = \curl_init('https://httpbin.org/delay/1');
                \curl_setopt($ch, \CURLOPT_RETURNTRANSFER, true);

                return CurlMulti::await($ch);
            });
        }

        foreach ($handles as $handle) {
            phasync::await($handle);
        }

        return \microtime(true) - $start;
    });

    expect($concurrentTime)->toBeLessThan($sequentialTime * 0.8);
});
