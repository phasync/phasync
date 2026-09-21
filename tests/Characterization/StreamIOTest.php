<?php

/*
 * Characterization tests for non-blocking stream IO: phasync::stream/readable/writable/io(),
 * the phasync\io helper functions and phasync::preempt()
 * (docs/SEMANTICS.md section 10, IO-1 .. IO-3).
 *
 * These pin how the code behaves TODAY. A failing test means "stop and tell the
 * maintainer" (see tests/Characterization/README.md).
 */

use phasync\io;
use phasync\IOException;
use phasync\TimeoutException;

uses()->group('characterization');

/**
 * Run PHP code in a separate process with a time limit, for behaviour that would
 * corrupt the driver of the test process.
 *
 * @return array{stdout: string, timedOut: bool}
 */
function iochar_child(string $code, float $limit): array
{
    $file = \tempnam(\sys_get_temp_dir(), 'iochar');
    \file_put_contents($file, "<?php\nrequire " . \var_export(\dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ";\n" . $code);
    $process = \proc_open([\PHP_BINARY, '-d', 'display_errors=0', $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    \stream_set_blocking($pipes[1], false);
    \stream_set_blocking($pipes[2], false);
    $stdout   = '';
    $timedOut = false;
    $deadline = \microtime(true) + $limit;
    while (true) {
        $stdout .= \stream_get_contents($pipes[1]);
        if (!\proc_get_status($process)['running']) {
            break;
        }
        if (\microtime(true) > $deadline) {
            $timedOut = true;
            \proc_terminate($process, \SIGKILL);
            break;
        }
        \usleep(20000);
    }
    $stdout .= \stream_get_contents($pipes[1]);
    \fclose($pipes[1]);
    \fclose($pipes[2]);
    \proc_close($process);
    \unlink($file);

    return ['stdout' => \trim($stdout), 'timedOut' => $timedOut];
}

/**
 * @return array{0: resource, 1: resource}
 */
function iochar_pair(): array
{
    return \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
}

function iochar_state(): array
{
    return (new ReflectionMethod('phasync', 'getDriver'))->invoke(null)->getFullState();
}

/**
 * Create a temporary file with the given content and pass its path to $test, then delete it.
 */
function iochar_with_file(string $content, Closure $test): void
{
    $path = \tempnam(\sys_get_temp_dir(), 'iochar');
    \file_put_contents($path, $content);
    try {
        $test($path);
    } finally {
        @\unlink($path);
        @\unlink($path . '.out');
    }
}

/* ------------------------------------------------------------------ IO-1 */

test('IO-1: stream() returns the events that fired as a bitmask, and 0 for something that is not a stream', function () {
    phasync::run(function () {
        [$a, $b] = iochar_pair();
        expect(phasync::stream($a, phasync::WRITABLE))->toBe(phasync::WRITABLE);
        expect(phasync::stream($a, phasync::READABLE | phasync::WRITABLE))->toBe(phasync::WRITABLE);

        \fwrite($b, 'x');
        expect(phasync::stream($a, phasync::READABLE | phasync::WRITABLE))->toBe(phasync::READABLE | phasync::WRITABLE);
        expect(phasync::stream($a, phasync::READABLE))->toBe(phasync::READABLE);

        expect(phasync::stream('not a stream'))->toBe(0);
    });
});

test('IO-1: readable() and writable() return the same resource', function () {
    phasync::run(function () {
        [$a, $b] = iochar_pair();
        \fwrite($b, 'x');
        expect(phasync::readable($a))->toBe($a);
        expect(phasync::writable($a))->toBe($a);
    });
});

test('IO-1: inside a coroutine the resource is switched to non-blocking mode', function () {
    phasync::run(function () {
        [$a, $b] = iochar_pair();
        expect(\stream_get_meta_data($a)['blocked'])->toBeTrue();
        phasync::stream($a, phasync::WRITABLE);
        expect(\stream_get_meta_data($a)['blocked'])->toBeFalse();
    });
});

test('IO-1: readable() suspends until data arrives, and other coroutines run meanwhile', function () {
    phasync::run(function () {
        [$a, $b] = iochar_pair();
        $log     = [];
        $reader  = phasync::go(function () use ($a, &$log) {
            $log[] = 'waiting';
            phasync::readable($a);
            $log[] = 'got ' . \fread($a, 100);
        });
        phasync::go(function () use ($b, &$log) {
            phasync::sleep(0.05);
            $log[] = 'writing';
            \fwrite($b, 'data');
        });
        phasync::await($reader);
        expect($log)->toBe(['waiting', 'writing', 'got data']);
    });
});

test('IO-1: several coroutines waiting on one resource are all resumed by one event, and only the first fread gets the data', function () {
    phasync::run(function () {
        [$a, $b] = iochar_pair();
        $got     = [];
        $waiters = [];
        foreach (['A', 'B', 'C'] as $name) {
            $waiters[] = phasync::go(function () use ($a, $name, &$got) {
                phasync::readable($a, 2.0);
                $got[] = $name . ':' . \var_export(\fread($a, 10), true);
            });
        }
        phasync::sleep(0.02);
        expect(iochar_state()['streams'])->toBeGreaterThanOrEqual(3);
        \fwrite($b, 'data');
        foreach ($waiters as $waiter) {
            phasync::await($waiter);
        }
        expect($got)->toBe(["A:'data'", "B:''", "C:''"]);
    });
});

test('IO-1: a coroutine waiting for readability is also resumed when the resource only becomes writable', function () {
    // SCH-3 says no spurious wake-ups. The driver resumes every coroutine waiting on a resource
    // when any of the requested events fires, whichever event that coroutine asked for.
    phasync::run(function () {
        [$a, $b] = iochar_pair();
        $log     = [];
        $reader  = phasync::go(function () use ($a, &$log) {
            phasync::readable($a);
            $log[] = 'readable';
        });
        $writer = phasync::go(function () use ($a, &$log) {
            phasync::writable($a);
            $log[] = 'writable';
        });
        phasync::sleep(0.05);
        expect($log)->toBe(['readable', 'writable']);
        expect(\fread($a, 10))->toBe('');
        phasync::await($reader);
        phasync::await($writer);
    });
})->group('surprise');

test('IO-1: readable() with a timeout throws TimeoutException when nothing arrives', function () {
    phasync::run(function () {
        [$a, $b] = iochar_pair();
        $start   = \microtime(true);
        expect(fn () => phasync::readable($a, 0.1))->toThrow(TimeoutException::class);
        expect(\microtime(true) - $start)->toBeGreaterThanOrEqual(0.1)->toBeLessThan(1.2);
    });
});

test('IO-1: when the peer closes, readable() returns, fread() gives an empty string and feof() is true', function () {
    phasync::run(function () {
        [$a, $b] = iochar_pair();
        $result  = null;
        $reader  = phasync::go(function () use ($a, &$result) {
            phasync::readable($a, 5.0);
            $result = [\fread($a, 10), \feof($a)];
        });
        phasync::sleep(0.02);
        \fclose($b);
        phasync::await($reader);
        expect($result)->toBe(['', true]);
    });
});

test('IO-1: readable() and writable() work on a regular file', function () {
    phasync::run(function () {
        $file = \fopen(__FILE__, 'r');
        expect(phasync::readable($file))->toBe($file);
        expect(phasync::writable($file))->toBe($file);
    });
});

/* ------------------------------------------------------------------ IO-2 */

test('IO-2: a resource closed while a coroutine waits on it throws IOException "Stream closed" in that coroutine', function () {
    phasync::run(function () {
        [$a, $b] = iochar_pair();
        $result  = null;
        $waiter  = phasync::go(function () use ($a, &$result) {
            try {
                phasync::readable($a, 5.0);
                $result = 'resumed';
            } catch (Throwable $e) {
                $result = \get_class($e) . ': ' . $e->getMessage();
            }
        });
        phasync::sleep(0.02);
        $streams = iochar_state()['streams'];
        $start   = \microtime(true);
        \fclose($a);
        phasync::await($waiter);
        expect($result)->toBe(IOException::class . ': Stream closed');
        expect(\microtime(true) - $start)->toBeLessThan(0.4);
        expect(iochar_state()['streams'])->toBe($streams - 1);
    });
});

test('IO-2: readable() and writable() on something that is not an open stream throw IOException', function () {
    phasync::run(function () {
        [$a, $b] = iochar_pair();
        \fclose($a);
        expect(fn () => phasync::readable('not a stream'))->toThrow(IOException::class, 'Not a valid stream resource');
        expect(fn () => phasync::readable($a))->toThrow(IOException::class, 'Not a valid stream resource');
        expect(fn () => phasync::writable($a))->toThrow(IOException::class, 'Not a valid stream resource');
    });
});

test('IO-2: waiting on a php://memory stream makes phasync::run() throw ValueError from the driver', function () {
    // stream_select() cannot poll memory streams and the driver does not guard against it. Run in
    // a child process because the driver of the test process would be left half finished.
    foreach (['readable', 'writable'] as $function) {
        $result = iochar_child(
            'try { phasync::run(function () { $m = fopen("php://memory", "w+"); phasync::' . $function . '($m); echo "returned"; }); }'
            . ' catch (Throwable $e) { echo get_class($e), ": ", $e->getMessage(); }',
            10.0
        );
        expect($result)->toBe(['stdout' => 'ValueError: No stream arrays were passed', 'timedOut' => false]);
    }
})->group('surprise');

test('IO-2: outside phasync::run() a blocking resource is reported ready at once, whatever the mode asks for', function () {
    [$a, $b] = iochar_pair();
    $start   = \microtime(true);
    expect(phasync::stream($a, phasync::READABLE))->toBe(phasync::READABLE);
    expect(phasync::stream($a, phasync::WRITABLE))->toBe(phasync::WRITABLE);
    expect(\microtime(true) - $start)->toBeLessThan(0.2);
});

test('IO-2: outside phasync::run() a non-blocking resource with data is reported readable', function () {
    [$a, $b] = iochar_pair();
    \stream_set_blocking($a, false);
    \fwrite($b, 'x');
    expect(phasync::stream($a, phasync::READABLE))->toBe(phasync::READABLE);
    expect(phasync::readable($a))->toBe($a);
});

test('IO-2: outside phasync::run() a non-blocking resource without data times out after about one second, ignoring the timeout argument', function () {
    [$a, $b] = iochar_pair();
    \stream_set_blocking($a, false);
    $start = \microtime(true);
    expect(fn () => phasync::stream($a, phasync::READABLE, 5.0))->toThrow(TimeoutException::class);
    expect(\microtime(true) - $start)->toBeGreaterThan(0.9)->toBeLessThan(2.0);
})->group('surprise');

/* ------------------------------------------------------------------ io() wrapper */

test('IO-1: io() returns anything that is not a stream unchanged, and never wraps twice', function () {
    phasync::run(function () {
        expect(phasync::io('text'))->toBe('text');
        [$a, $b] = iochar_pair();
        $wrapped = phasync::io($a);
        expect(\stream_get_meta_data($wrapped)['wrapper_type'])->toBe('user-space');
        expect(phasync::io($wrapped))->toBe($wrapped);
    });
});

test('IO-1: fread() on an io() wrapped stream suspends the coroutine, and fwrite() passes data through', function () {
    phasync::run(function () {
        [$a, $b]  = iochar_pair();
        $wrapped  = phasync::io($a);
        $ticks    = 0;
        $ticker   = phasync::go(function () use (&$ticks) {
            for ($i = 0; $i < 5; ++$i) {
                phasync::sleep(0.01);
                ++$ticks;
            }
        });
        phasync::go(function () use ($b) {
            phasync::sleep(0.06);
            \fwrite($b, 'late data');
        });
        expect(\fread($wrapped, 100))->toBe('late data');
        expect($ticks)->toBeGreaterThanOrEqual(4);
        phasync::await($ticker);

        expect(\fwrite($wrapped, 'hello'))->toBe(5);
        \stream_set_blocking($b, false);
        expect(\fread($b, 100))->toBe('hello');
    });
});

test('IO-1: an io() wrapped stream also works outside phasync::run()', function () {
    [$a, $b] = iochar_pair();
    $wrapped = phasync::io($a);
    \fwrite($b, 'outside');
    expect(\fread($wrapped, 100))->toBe('outside');
    expect(\fwrite($wrapped, 'x'))->toBe(1);
});

/* ------------------------------------------------------------------ phasync\io helpers */

test('IO-1: io::fread() and io::stream_get_contents() wait for data inside a coroutine and restore blocking mode', function () {
    phasync::run(function () {
        [$a, $b] = iochar_pair();
        phasync::go(function () use ($b) {
            phasync::sleep(0.03);
            \fwrite($b, 'abcdefghij');
        });
        $start = \microtime(true);
        expect(io::fread($a, 4))->toBe('abcd');
        expect(\microtime(true) - $start)->toBeGreaterThanOrEqual(0.02);
        expect(\stream_get_meta_data($a)['blocked'])->toBeTrue();

        [$c, $d] = iochar_pair();
        phasync::go(function () use ($d) {
            phasync::sleep(0.03);
            \fwrite($d, 'the whole thing');
            \fclose($d);
        });
        expect(io::stream_get_contents($c))->toBe('the whole thing');
    });
});

test('IO-1: io::fread() on a stream whose peer has closed returns an empty string', function () {
    phasync::run(function () {
        [$a, $b] = iochar_pair();
        \fclose($b);
        expect(io::fread($a, 10))->toBe('');
    });
});

test('IO-1: io::fgets() returns whatever is available, which may be a partial line', function () {
    // A line that arrives in two pieces is returned in two pieces, not waited for until "\n".
    phasync::run(function () {
        [$a, $b] = iochar_pair();
        phasync::go(function () use ($b) {
            phasync::sleep(0.03);
            \fwrite($b, "first\nsec");
            phasync::sleep(0.03);
            \fwrite($b, "ond\n");
        });
        expect(io::fgets($a))->toBe("first\n");
        expect(io::fgets($a))->toBe('sec');
    });
})->group('surprise');

test('IO-1: io::fgetc() does not wait: on an empty non-blocking stream it returns an empty string', function () {
    phasync::run(function () {
        [$a, $b] = iochar_pair();
        \stream_set_blocking($a, false);
        expect(io::fgetc($a))->toBe('');
        \fwrite($b, 'e');
        expect(io::fgetc($a))->toBe('e');
    });
})->group('surprise');

test('IO-1: io::file_get_contents() and io::file_put_contents() read and write files inside a coroutine', function () {
    iochar_with_file("line1\nline2\nline3", function (string $path) {
        phasync::run(function () use ($path) {
            expect(io::file_get_contents($path))->toBe("line1\nline2\nline3");
            expect(fn () => @io::file_get_contents('/nonexistent/file'))->toThrow(Exception::class, 'Unable to open file');

            $out = $path . '.out';
            expect(io::file_put_contents($out, 'written'))->toBe(7);
            expect(\file_get_contents($out))->toBe('written');
            expect(io::file_put_contents($out, '+more', \FILE_APPEND))->toBe(5);
            expect(\file_get_contents($out))->toBe('written+more');
            expect(io::file_put_contents($out, ['a', 'b', 'c']))->toBe(3);
            expect(\file_get_contents($out))->toBe('abc');
        });
    });
});

test('IO-1: io::fgets(), io::fgetcsv() and io::fputcsv() work on files inside a coroutine', function () {
    iochar_with_file("line1\nline2\n", function (string $path) {
        phasync::run(function () use ($path) {
            $file = \fopen($path, 'r');
            expect(io::fgets($file))->toBe("line1\n");
            expect(io::fgetcsv($file))->toBe(['line2']);

            $csv = \fopen($path . '.out', 'w+');
            expect(io::fputcsv($csv, ['x', 'y z', 'q"r']))->toBe(15);
            \rewind($csv);
            expect(\stream_get_contents($csv))->toBe("x,\"y z\",\"q\"\"r\"\n");
        });
    });
});

test('IO-1: io::ftruncate() truncates and reports 1 (a bool coerced to the declared int)', function () {
    iochar_with_file('', function (string $path) {
        phasync::run(function () use ($path) {
            $file = \fopen($path, 'w+');
            \fwrite($file, 'abcdef');
            expect(io::ftruncate($file, 3))->toBe(1);
            \rewind($file);
            expect(\stream_get_contents($file))->toBe('abc');
        });
    });
});

test('IO-1: io::flock() inside a coroutine waits for the lock by yielding, and LOCK_NB fails at once', function () {
    iochar_with_file('x', function (string $path) {
        phasync::run(function () use ($path) {
            $holder = \fopen($path, 'r');
            $waiter = \fopen($path, 'r');
            expect(io::flock($holder, \LOCK_SH))->toBeTrue();
            expect(io::flock($waiter, \LOCK_EX | \LOCK_NB, $wouldBlock))->toBeFalse();

            phasync::go(function () use ($holder) {
                phasync::sleep(0.05);
                \flock($holder, \LOCK_UN);
            });
            $start = \microtime(true);
            expect(io::flock($waiter, \LOCK_EX))->toBeTrue();
            expect(\microtime(true) - $start)->toBeGreaterThanOrEqual(0.04);
        });
    });
});

test('IO-1: the io helpers throw TypeError for a non-resource when called inside a coroutine', function () {
    phasync::run(function () {
        expect(fn () => io::fgets('nope'))->toThrow(TypeError::class);
        expect(fn () => io::fwrite('nope', 'x'))->toThrow(TypeError::class);
        expect(fn () => io::fgetcsv('nope'))->toThrow(TypeError::class);
        expect(fn () => io::stream_get_contents('nope'))->toThrow(TypeError::class);
    });
});

test('IO-1: outside a coroutine the io helpers do the plain PHP function', function () {
    iochar_with_file("l1\nl2\n", function (string $path) {
        [$a, $b] = iochar_pair();
        \fwrite($b, "l1\nl2\n");
        expect(io::fgets($a))->toBe("l1\n");
        expect(io::fread($a, 10))->toBe("l2\n");
        expect(io::file_get_contents($path))->toBe("l1\nl2\n");
        expect(io::file_put_contents($path . '.out', 'z'))->toBe(1);
        expect(io::fwrite($b, 'q'))->toBe(1);
    });
});

/* ------------------------------------------------------------------ IO-3 */

test('IO-3: preempt() outside a coroutine does nothing', function () {
    phasync::preempt();
    expect(true)->toBeTrue();
});

test('IO-3: preempt() in a busy loop lets siblings run, without it they wait, and the result is the same', function () {
    $run = function (bool $preempt): array {
        return phasync::run(function () use ($preempt) {
            $log   = [];
            $other = phasync::go(function () use (&$log) {
                for ($i = 0; $i < 3; ++$i) {
                    $log[] = 'other' . $i;
                    phasync::yield();
                }
            });
            $sum = 0;
            for ($i = 0; $i < 60; ++$i) {
                $sum += $i;
                \usleep(1000);
                if ($preempt) {
                    phasync::preempt();
                }
            }
            $log[] = 'busy done';
            phasync::await($other);

            return [$sum, $log];
        });
    };

    phasync::setPreemptInterval(1000);
    try {
        [$sumWith, $logWith]       = $run(true);
        [$sumWithout, $logWithout] = $run(false);
    } finally {
        phasync::setPreemptInterval(\intdiv(phasync::DEFAULT_PREEMPT_INTERVAL, 1000));
    }

    expect($sumWith)->toBe(1770)->and($sumWithout)->toBe(1770);
    expect($logWith)->toBe(['other0', 'other1', 'other2', 'busy done']);
    expect($logWithout)->toBe(['other0', 'busy done', 'other1', 'other2']);
});
