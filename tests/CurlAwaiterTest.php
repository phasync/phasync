<?php

use phasync\Services\CurlAwaiter;

// A local `php -S` instead of a public service: the timing test needs a server whose delay is the only latency
beforeAll(function () {
    $probe = \stream_socket_server('tcp://127.0.0.1:0');
    $port  = (int) \substr(\strrchr(\stream_socket_get_name($probe, false), ':'), 1);
    \fclose($probe);
    $GLOBALS['curlMultiServer'] = \proc_open(
        [\PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/Fixtures/http-router.php'],
        [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
        $pipes,
        null,
        ['PHP_CLI_SERVER_WORKERS' => '8'] + \getenv(),
    );
    $GLOBALS['curlMultiBase'] = "http://127.0.0.1:$port";
    for ($i = 0; $i < 100 && !@\fsockopen('127.0.0.1', $port); ++$i) {
        \usleep(20000);
    }
});

afterAll(function () {
    \proc_terminate($GLOBALS['curlMultiServer']);
    \proc_close($GLOBALS['curlMultiServer']);
});

test('test basic curl multi functionality', function () {
    expect(function () {
        phasync::run(function () {
            $a = phasync::go(function () {
                $ch = \curl_init($GLOBALS['curlMultiBase'] . '/get');
                \curl_setopt($ch, \CURLOPT_RETURNTRANSFER, true);

                return CurlAwaiter::await($ch);
            });

            $b = phasync::go(function () {
                $ch = \curl_init($GLOBALS['curlMultiBase'] . '/get');
                \curl_setopt($ch, \CURLOPT_RETURNTRANSFER, true);

                return CurlAwaiter::await($ch);
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

                return CurlAwaiter::await($ch);
            });

            $b = phasync::go(function () {
                $ch = \curl_init($GLOBALS['curlMultiBase'] . '/get');
                \curl_setopt($ch, \CURLOPT_RETURNTRANSFER, true);

                return CurlAwaiter::await($ch);
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

                return CurlAwaiter::await($ch);
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
                    $ch = \curl_init($GLOBALS['curlMultiBase'] . '/get');
                    \curl_setopt($ch, \CURLOPT_RETURNTRANSFER, true);

                    return CurlAwaiter::await($ch);
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
            $ch = \curl_init($GLOBALS['curlMultiBase'] . '/delay/1');
            \curl_setopt($ch, \CURLOPT_RETURNTRANSFER, true);
            CurlAwaiter::await($ch);
        }

        return \microtime(true) - $start;
    });

    $concurrentTime = phasync::run(function () {
        $start   = \microtime(true);
        $handles = [];

        for ($i = 0; $i < 3; ++$i) {
            $handles[] = phasync::go(function () {
                $ch = \curl_init($GLOBALS['curlMultiBase'] . '/delay/1');
                \curl_setopt($ch, \CURLOPT_RETURNTRANSFER, true);

                return CurlAwaiter::await($ch);
            });
        }

        foreach ($handles as $handle) {
            phasync::await($handle);
        }

        return \microtime(true) - $start;
    });

    expect($concurrentTime)->toBeLessThan($sequentialTime * 0.8);
});

test('sequential requests are not paced by a polling interval (#133)', function () {
    $elapsed = phasync::run(function () {
        $start = \microtime(true);
        for ($i = 0; $i < 50; ++$i) {
            $ch = \curl_init($GLOBALS['curlMultiBase'] . '/get');
            \curl_setopt($ch, \CURLOPT_RETURNTRANSFER, true);
            CurlAwaiter::await($ch);
        }

        return \microtime(true) - $start;
    });

    // 50 requests used to take 3 s: every curl round trip waited 20 ms
    expect($elapsed)->toBeLessThan(1.0);
});

test('a request that starts while the loop backs off from a slow one is not delayed (#138)', function () {
    $server = \stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    $name   = \stream_socket_get_name($server, false);
    $port   = (int) \substr($name, \strrpos($name, ':') + 1);

    $average = phasync::run(function () use ($server, $port) {
        phasync::go(function () use ($server) {
            for ($n = 0; $n < 13; ++$n) {
                phasync::readable($server);
                $client = \stream_socket_accept($server);
                phasync::go(function () use ($client, $n) {
                    phasync::readable($client);
                    \fread($client, 8192);
                    if (0 === $n) {
                        phasync::sleep(1.5);
                    }
                    \fwrite($client, "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nok");
                    \fclose($client);
                });
            }
        });
        $request = static function () use ($port) {
            $ch = \curl_init("http://127.0.0.1:$port/");
            \curl_setopt($ch, \CURLOPT_RETURNTRANSFER, true);

            return CurlAwaiter::await($ch);
        };
        $slow = phasync::go($request);
        phasync::sleep(0.1);

        $total = 0.0;
        for ($i = 0; $i < 12; ++$i) {
            $start = \microtime(true);
            $request();
            $total += \microtime(true) - $start;
            phasync::sleep(0.1); // long enough for the loop to back off to its 10 ms again
        }
        phasync::await($slow);

        return $total / 12;
    });
    \fclose($server);

    // up to 10 ms each while the loop slept through its backoff
    expect($average)->toBeLessThan(0.003);
});
