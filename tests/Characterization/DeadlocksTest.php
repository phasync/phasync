<?php

/*
 * Characterization tests for docs/SEMANTICS.md section 9 (DLK-1 .. DLK-5).
 * They pin how deadlock handling behaves TODAY. Read tests/Characterization/README.md
 * before changing anything here.
 */

use phasync\ChannelException;
use phasync\TimeoutException;

uses()->group('characterization');

if (!\function_exists('dlkTimed')) {
    /** Run $fn and return [exception class or 'ok', elapsed seconds, exception message]. */
    function dlkTimed(Closure $fn): array
    {
        $start = \microtime(true);
        try {
            $fn();
            $outcome = ['ok', ''];
        } catch (Throwable $e) {
            $outcome = [$e::class, $e->getMessage()];
        }

        return [$outcome[0], \microtime(true) - $start, $outcome[1]];
    }

    /**
     * Run each PHP snippet as `phasync::run(function () { echo "waiting\n"; <snippet> echo "finished\n"; })`
     * in a child process, wait $seconds, and report for each whether it was still running and what it printed.
     * Every child is killed before returning.
     */
    function dlkRunStalled(array $snippets, float $seconds): array
    {
        $autoload  = \dirname(__DIR__, 2) . '/vendor/autoload.php';
        $processes = [];
        $files     = [];
        foreach ($snippets as $name => $snippet) {
            $file = \tempnam(\sys_get_temp_dir(), 'phasync-stall-');
            \file_put_contents($file, "<?php\nrequire " . \var_export($autoload, true) . ";\n"
                . "phasync::run(function () {\n    echo \"waiting\\n\";\n    $snippet\n    echo \"finished\\n\";\n});\n");
            $files[$name] = $file;
            $pipes        = [];
            $process      = \proc_open([\PHP_BINARY, '-d', 'display_errors=0', $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            \stream_set_blocking($pipes[1], false);
            $processes[$name] = [$process, $pipes];
        }

        try {
            \usleep((int) ($seconds * 1000000));
            $report = [];
            foreach ($processes as $name => [$process, $pipes]) {
                $status         = \proc_get_status($process);
                $report[$name]  = ['running' => $status['running'], 'stdout' => (string) \stream_get_contents($pipes[1])];
            }

            return $report;
        } finally {
            foreach ($processes as [$process, $pipes]) {
                \proc_terminate($process, 9);
                \fclose($pipes[1]);
                \fclose($pipes[2]);
                \proc_close($process);
            }
            foreach ($files as $file) {
                @\unlink($file);
            }
        }
    }
}

// ---------------------------------------------------------------------------
// DLK-1: time-based deadlock guessing
// ---------------------------------------------------------------------------

test('DLK-1: the creator of a channel reading it gets ChannelException("Likely deadlock") after about 0.1 s [DIVERGENCE]', function () {
    // The contract (DLK-1) says no time-based deadlock guessing.
    [$outcome, $seconds, $message] = phasync::run(static function () {
        phasync::channel($r, $w);

        return dlkTimed(static fn () => $r->read());
    });

    expect($outcome)->toBe(ChannelException::class);
    expect($message)->toStartWith('Likely deadlock detected');
    expect($seconds)->toBeGreaterThanOrEqual(0.09);
    expect($seconds)->toBeLessThan(0.6);
})->group('divergence');

test('DLK-1: the creator of a channel writing to it (unbuffered) gets ChannelException("Likely deadlock") [DIVERGENCE]', function () {
    [$outcome, $seconds, $message] = phasync::run(static function () {
        phasync::channel($r, $w);

        return dlkTimed(static fn () => $w->write('x'));
    });

    expect($outcome)->toBe(ChannelException::class);
    expect($message)->toStartWith('Likely deadlock detected');
    expect($seconds)->toBeGreaterThanOrEqual(0.09);
    expect($seconds)->toBeLessThan(0.6);
})->group('divergence');

test('DLK-1: the creator writing to a full buffered channel gets ChannelException("Likely deadlock") [DIVERGENCE]', function () {
    [$outcome, , $message] = phasync::run(static function () {
        phasync::channel($r, $w, 1);
        $w->write('a');

        return dlkTimed(static fn () => $w->write('b'));
    });

    expect($outcome)->toBe(ChannelException::class);
    expect($message)->toStartWith('Likely deadlock detected');
})->group('divergence');

test('DLK-1: activate() switches the creator heuristic off, so the same read times out normally instead [DIVERGENCE]', function () {
    [$outcome, $seconds] = phasync::run(static function () {
        phasync::channel($r, $w);
        $r->activate();

        return dlkTimed(static fn () => $r->read(0.1));
    });

    expect($outcome)->toBe(TimeoutException::class);
    expect($seconds)->toBeGreaterThanOrEqual(0.1);
})->group('divergence');

test('DLK-1: a channel read from another coroutine than the creator is not treated as a deadlock, it just waits [DIVERGENCE]', function () {
    [$outcome, , $message] = phasync::run(static function () {
        phasync::channel($r, $w);
        $reader = phasync::go(static fn () => dlkTimed(static fn () => $r->read(0.1)));

        return phasync::await($reader);
    });

    expect($outcome)->toBe(TimeoutException::class);
    expect($message)->toBe('Channel read operation timed out');
})->group('divergence');

test('DLK-1: Channel::read() suspends at least once (via phasync::sleep) even when a value is already buffered [DIVERGENCE]', function () {
    // The contract (CHN-10) says read() does not insert phasync::sleep() calls. The sibling
    // below counts how often it got to run while read() was executing.
    $slices = phasync::run(static function () {
        phasync::channel($r, $w, 2);
        $w->write('a');
        $count   = 0;
        $stop    = false;
        $sibling = phasync::go(static function () use (&$count, &$stop) {
            while (!$stop) {
                ++$count;
                phasync::sleep(0);
            }
        });
        $before = $count;
        $r->read();
        $during = $count - $before;
        $stop   = true;
        phasync::await($sibling);

        return $during;
    });

    expect($slices)->toBeGreaterThanOrEqual(1);
})->group('divergence');

test('DLK-1: Channel::write() suspends at least once (via phasync::sleep) even when the buffer has room [DIVERGENCE]', function () {
    $slices = phasync::run(static function () {
        phasync::channel($r, $w, 2);
        $count   = 0;
        $stop    = false;
        $sibling = phasync::go(static function () use (&$count, &$stop) {
            while (!$stop) {
                ++$count;
                phasync::sleep(0);
            }
        });
        $before = $count;
        $w->write('a');
        $during = $count - $before;
        $stop   = true;
        phasync::await($sibling);

        return $during;
    });

    expect($slices)->toBeGreaterThanOrEqual(1);
})->group('divergence');

// ---------------------------------------------------------------------------
// DLK-2: await cycles are detected when they form
// ---------------------------------------------------------------------------

test('DLK-2: a coroutine awaiting itself throws InvalidArgumentException', function () {
    $result = phasync::run(static function () {
        try {
            phasync::await(Fiber::getCurrent());
        } catch (Throwable $e) {
            return [$e::class, $e->getMessage()];
        }

        return 'no exception';
    });

    expect($result)->toBe([InvalidArgumentException::class, "A fiber can't block itself"]);
});

test('DLK-2: a two-coroutine await cycle throws LogicException in the coroutine that closes the cycle, immediately', function () {
    $start  = \microtime(true);
    $result = phasync::run(static function () {
        $a   = null;
        $b   = null;
        $log = [];
        $a   = phasync::go(static function () use (&$b, &$log) {
            phasync::sleep(0.01);
            try {
                phasync::await($b);
            } catch (Throwable $e) {
                $log[] = 'a: ' . $e::class . ': ' . $e->getMessage();
            }
        });
        $b = phasync::go(static function () use (&$a, &$log) {
            phasync::sleep(0.02);
            try {
                phasync::await($a);
            } catch (Throwable $e) {
                $log[] = 'b: ' . $e::class . ': ' . $e->getMessage();
            }
        });
        phasync::await($a);
        phasync::await($b);

        return $log;
    });

    expect($result)->toBe(['b: LogicException: Await cycle deadlock detected']);
    expect(\microtime(true) - $start)->toBeLessThan(0.4);
});

test('DLK-2: a three-coroutine await cycle throws LogicException in the last coroutine to join it', function () {
    $log = phasync::run(static function () {
        $fibers = [];
        $log    = [];
        foreach ([0, 1, 2] as $i) {
            $fibers[$i] = phasync::go(static function () use ($i, &$fibers, &$log) {
                phasync::sleep(0.01 * ($i + 1));
                try {
                    phasync::await($fibers[($i + 1) % 3]);
                } catch (Throwable $e) {
                    $log[] = $i . ': ' . $e::class;
                }
            });
        }
        foreach ($fibers as $fiber) {
            phasync::await($fiber);
        }

        return $log;
    });

    expect($log)->toBe(['2: LogicException']);
});

// ---------------------------------------------------------------------------
// DLK-3 / DLK-4: stalls
// ---------------------------------------------------------------------------

test('DLK-3: a global stall (nothing can ever wake anyone) is not detected, run() just keeps waiting [DIVERGENCE]', function () {
    // The contract (DLK-3) says run() throws DeadlockException. Today the child processes
    // below are still running, silently, after the observation window.
    $report = dlkRunStalled([
        'flag'    => '$flag = new stdClass(); phasync::awaitFlag($flag); unset($flag);',
        'channel' => 'phasync::channel($r, $w); $reader = phasync::go(fn () => $r->read()); phasync::await($reader);',
    ], 2.0);

    expect($report['flag']['running'])->toBeTrue();
    expect($report['flag']['stdout'])->toBe("waiting\n");
    expect($report['channel']['running'])->toBeTrue();
    expect($report['channel']['stdout'])->toBe("waiting\n");
})->group('divergence');

test('DLK-4: a blocked coroutine is left alone while others make progress (partial stalls are not detected)', function () {
    $log = phasync::run(static function () {
        $log    = [];
        $flag   = new stdClass();
        $waiter = phasync::go(static function () use ($flag, &$log) {
            try {
                phasync::awaitFlag($flag, 0.6);
            } catch (TimeoutException) {
                $log[] = 'waiter timed out';
            }
        });
        $worker = phasync::go(static function () use (&$log) {
            for ($i = 0; $i < 3; ++$i) {
                phasync::sleep(0.1);
                $log[] = "progress $i";
            }
        });
        phasync::await($worker);
        phasync::await($waiter);

        return $log;
    });

    expect($log)->toBe(['progress 0', 'progress 1', 'progress 2', 'waiter timed out']);
});

// ---------------------------------------------------------------------------
// DLK-5: prevention by construction (channel ends close each other)
// ---------------------------------------------------------------------------

test('DLK-5: dropping the last reference to the writer end makes a blocked reader see end-of-stream', function () {
    [$outcome, $seconds] = phasync::run(static function () {
        phasync::channel($r, $w);
        $reader = phasync::go(static function () use ($r) {
            $start = \microtime(true);

            return [$r->read(), \microtime(true) - $start];
        });
        unset($w);

        return phasync::await($reader);
    });

    expect($outcome)->toBeNull();
    expect($seconds)->toBeLessThan(0.3);
});

test('DLK-5: dropping the last reference to the reader end makes a writer throw ChannelException("Channel is closed")', function () {
    $result = phasync::run(static function () {
        phasync::channel($r, $w);
        $writer = phasync::go(static function () use ($w) {
            try {
                $w->write('x');
            } catch (Throwable $e) {
                return [$e::class, $e->getMessage()];
            }

            return 'no exception';
        });
        unset($r);

        return phasync::await($writer);
    });

    expect($result)->toBe([ChannelException::class, 'Channel is closed']);
});

test('DLK-5: while the writer end is still referenced somewhere, a blocked reader keeps waiting', function () {
    [$outcome, , $message] = phasync::run(static function () {
        phasync::channel($r, $w);
        $reader = phasync::go(static fn () => dlkTimed(static fn () => $r->read(0.1)));
        $result = phasync::await($reader);
        unset($w);

        return $result;
    });

    expect($outcome)->toBe(TimeoutException::class);
    expect($message)->toBe('Channel read operation timed out');
});

test('DLK-5: a writer end that is part of a reference cycle is not released by unset(), so the reader is not woken [SURPRISE]', function () {
    // Release relies on refcount destructors. Inside run() the cycle collector is off
    // (RT-1), so a cycle keeps the writer alive and the reader times out instead of
    // seeing end-of-stream.
    [$outcome] = phasync::run(static function () {
        phasync::channel($r, $w);
        $holder       = new stdClass();
        $holder->self = $holder;
        $holder->w    = $w;
        $reader       = phasync::go(static fn () => dlkTimed(static fn () => $r->read(0.1)));
        unset($w, $holder);

        return phasync::await($reader);
    });

    expect($outcome)->toBe(TimeoutException::class);
})->group('surprise');
