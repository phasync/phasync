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
        foreach ([$path, $path . '.out'] as $file) {
            if (\is_file($file)) {
                \unlink($file);
            }
        }
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

/* ------------------------------------------------------------------ IO-8 */

// IO-8: waiting on a stream inside run() makes it non-blocking, so the read or write after
// the wait cannot block the process. phasync-ext's auto-managed streams are left blocking:
// the extension suspends their reads and writes itself.
if (!\function_exists('iochar_auto_managed')) {
    function iochar_auto_managed($stream): bool
    {
        return \function_exists('phasync\ext\is_auto_managed') && \phasync\ext\is_auto_managed($stream);
    }
}

test('IO-8: readable() and writable() inside run() leave a blocking stream non-blocking', function () {
    $modes = phasync::run(function () {
        [$a, $b] = iochar_pair();
        [$c, $d] = iochar_pair();
        $auto = [iochar_auto_managed($a), iochar_auto_managed($c)];
        \fwrite($b, 'x');
        phasync::readable($a);
        phasync::writable($c);

        return [[\stream_get_meta_data($a)['blocked'], \stream_get_meta_data($c)['blocked']], $auto];
    });

    expect($modes[0])->toBe($modes[1]);
});

test('IO-8: a stream set back to blocking after a wait is made non-blocking again by the next wait', function () {
    $blocked = phasync::run(function () {
        [$a, $b] = iochar_pair();
        \fwrite($b, 'xy');
        phasync::readable($a);
        \stream_set_blocking($a, true);
        $auto = iochar_auto_managed($a);
        phasync::readable($a);

        return [\stream_get_meta_data($a)['blocked'], $auto];
    });

    expect($blocked[0])->toBe($blocked[1]);
});

/* ------------------------------------------------------------------ IO-7 */

// IO-7: on a stock POSIX build, stream_select() fails outright (and used to fail *silently*, see
// docs/SEMANTICS.md) once any watched resource's real file descriptor number reaches FD_SETSIZE
// (1024). These tests open comfortably more than 1024 real streams rather than trying to detect
// the exact boundary: (int) casting a stream resource gives PHP's own resource-id counter, not
// the kernel fd, and the two are not guaranteed to stay in lockstep (verified: they can already
// differ by dozens once phasync's own autoloader has opened a few files first), so a wide margin
// is the only reliable way to reach the real threshold without depending on FFI.
if (!\function_exists('iochar_push_past_fd_setsize')) {
    /** @return resource[] every socket end created, kept referenced so the fds stay open */
    function iochar_push_past_fd_setsize(): array
    {
        $kept = [];
        for ($i = 0; $i < 1300; ++$i) {
            $kept[] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        }

        return $kept;
    }
}

test('IO-7: a stream past FD_SETSIZE makes readable() throw IOException naming the real cause, instead of hanging', function () {
    $kept   = iochar_push_past_fd_setsize();
    $high   = \end($kept)[0];
    $result = phasync::run(function () use ($high) {
        $start = \microtime(true);
        try {
            phasync::readable($high, 3.0);

            return ['returned normally', 0.0];
        } catch (Throwable $e) {
            return [$e::class, \microtime(true) - $start];
        }
    });

    expect($result[0])->toBe(IOException::class);
    // Loud and fast: nowhere near the 3 s timeout it used to silently wait out.
    expect($result[1])->toBeLessThan(1.0);
})->group('divergence')->skip(\extension_loaded('phasync'), 'the phasync extension has no FD_SETSIZE limit');

test('IO-7: the failure is delivered to every fiber waiting that tick, not only the one with the bad descriptor', function () {
    $kept    = iochar_push_past_fd_setsize();
    $high    = \end($kept)[0];
    [$a, $b] = iochar_pair(); // an ordinary, otherwise healthy pair in the very same batch

    $result = phasync::run(function () use ($high, $a) {
        $log     = [];
        $victims = [
            'high'     => phasync::go(function () use ($high, &$log) {
                try {
                    phasync::readable($high, 3.0);
                } catch (Throwable $e) {
                    $log['high'] = $e::class;
                }
            }),
            'innocent' => phasync::go(function () use ($a, &$log) {
                try {
                    phasync::readable($a, 3.0);
                } catch (Throwable $e) {
                    $log['innocent'] = $e::class;
                }
            }),
        ];
        foreach ($victims as $victim) {
            phasync::await($victim);
        }

        return $log;
    });

    expect($result)->toBe(['high' => IOException::class, 'innocent' => IOException::class]);
})->group('divergence')->skip(\extension_loaded('phasync'), 'the phasync extension has no FD_SETSIZE limit');

test('IO-7: with the phasync extension, a stream past FD_SETSIZE is waited on like any other', function () {
    $kept     = iochar_push_past_fd_setsize();
    [$a, $b]  = \end($kept);
    $received = phasync::run(function () use ($a, $b) {
        phasync::go(function () use ($b) {
            phasync::sleep(0.05);
            \fwrite($b, 'hello');
        });

        return \fread(phasync::readable($a, 3.0), 100);
    });

    expect($received)->toBe('hello');
})->skip(!\extension_loaded('phasync'), 'needs the phasync extension');

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
/*
 * phasync\io was narrowed in 2.0.0 to file_get_contents()/file_put_contents()/flock() only.
 * fread(), fgets(), fgetc(), fgetcsv(), fputcsv(), fwrite(), ftruncate() and
 * stream_get_contents() are removed: zero usage anywhere outside this file's own tests, and
 * fgetc() had a real bug (it called the native blocking \fread(), not self::fread(), so it
 * never waited at all) that sat undetected because nothing exercised it. See CHANGELOG.md.
 */

test('IO-1: io::file_get_contents() and io::file_put_contents() read and write files inside a coroutine', function () {
    iochar_with_file("line1\nline2\nline3", function (string $path) {
        phasync::run(function () use ($path) {
            expect(io::file_get_contents($path))->toBe("line1\nline2\nline3");
            // The failing fopen() also emits a PHP warning, on top of the exception.
            $warnings = [];
            \set_error_handler(function (int $no, string $str) use (&$warnings) {
                $warnings[] = $str;

                return true;
            });
            try {
                expect(fn () => io::file_get_contents('/nonexistent/file'))->toThrow(Exception::class, 'Unable to open file');
            } finally {
                \restore_error_handler();
            }
            expect($warnings)->toHaveCount(1);
            expect($warnings[0])->toContain('Failed to open stream');

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

test('IO-1: io::flock() throws TypeError for a non-resource when called inside a coroutine', function () {
    phasync::run(function () {
        expect(fn () => io::flock('nope', \LOCK_SH))->toThrow(TypeError::class);
    });
});

test('IO-1: outside a coroutine the io helpers do the plain PHP function', function () {
    iochar_with_file("l1\nl2\n", function (string $path) {
        expect(io::file_get_contents($path))->toBe("l1\nl2\n");
        expect(io::file_put_contents($path . '.out', 'z'))->toBe(1);
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
                // 1 ms of CPU work; not usleep(), which phasync-ext turns into a suspension
                for ($until = \hrtime(true) + 1_000_000; \hrtime(true) < $until;);
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
