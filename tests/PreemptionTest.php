<?php

/*
 * Preemption with phasync-ext (set_preempt_function()): a coroutine that runs a whole interval in a
 * PHP loop yields to other root contexts (requests); its own root stays frozen until it resumes.
 * Without the extension these tests are skipped: scheduling is cooperative only.
 */

beforeEach(function () {
    if (!\function_exists('phasync\ext\set_preempt_function')) {
        $this->markTestSkipped('needs phasync-ext with set_preempt_function()');
    }
});

/** Busy for $seconds in a PHP loop, without suspending. */
function preemptBusy(float $seconds): int
{
    $n   = 0;
    $end = \microtime(true) + $seconds;
    while (\microtime(true) < $end) {
        ++$n;
    }

    return $n;
}

test('a CPU-bound request no longer starves another: the other runs while it loops', function () {
    $log = phasync::run(function () {
        $log = [];
        $a   = phasync::go(function () use (&$log) {
            $log[] = 'A starts';
            preemptBusy(0.5);
            $log[] = 'A done';
        }, context: new stdClass());
        $b = phasync::go(function () use (&$log) {
            for ($i = 0; $i < 3; ++$i) {
                phasync::sleep(0.01);
                $log[] = "B $i";
            }
        }, context: new stdClass());
        phasync::await($a);
        phasync::await($b);

        return $log;
    });
    expect($log)->toBe(['A starts', 'B 0', 'B 1', 'B 2', 'A done']);
});

test('a preempted request is frozen: its other coroutines run only after its loop is done', function () {
    $log = phasync::run(function () {
        $log     = [];
        phasync::go(function () use (&$log) { // another request, which does run meanwhile
            phasync::sleep(0.02);
            $log[] = 'B';
        }, context: new stdClass());
        $request = new stdClass();
        phasync::withContext(function () use (&$log) {
            phasync::go(function () use (&$log) {
                $log[] = 'A1 starts';
                preemptBusy(0.1);
                $log[] = 'A1 done';
            });
            phasync::go(function () use (&$log) {
                $log[] = 'A2'; // its creator is of the request too: it waits for A1's loop
            });
            phasync::go(function () use (&$log) {
                phasync::sleep(0.01);
                $log[] = 'A3'; // ready meanwhile: held until A1 resumes
            });
        }, $request);
        phasync::sleep(0.2);

        return $log;
    });
    expect($log)->toBe(['A1 starts', 'B', 'A1 done', 'A2', 'A3']);
});

test('a coroutine held for a frozen request can be cancelled: it throws once the request thaws', function () {
    $log = phasync::run(function () {
        $log     = [];
        $waiting = null;
        phasync::go(function () use (&$log, &$waiting) { // another request
            phasync::sleep(0.03);
            phasync::cancel($waiting);
            $log[] = 'cancel';
        }, context: new stdClass());
        phasync::withContext(function () use (&$log, &$waiting) {
            $waiting = phasync::go(function () use (&$log) {
                try {
                    phasync::sleep(0.01);
                    $log[] = 'not cancelled';
                } catch (phasync\CancelledException) {
                    $log[] = 'cancelled';
                }
            });
            phasync::go(function () use (&$log) {
                preemptBusy(0.1);
                $log[] = 'loop done';
            });
        }, new stdClass());
        phasync::sleep(0.2);

        return $log;
    });
    expect($log)->toBe(['cancel', 'loop done', 'cancelled']);
});

test('#[\phasync\Uninterruptible] code is not preempted', function () {
    $log = phasync::run(function () {
        $log = [];
        phasync::go(function () use (&$log) {
            (#[phasync\Uninterruptible] static function () {
                preemptBusy(0.1);
            })();
            $log[] = 'A done';
        }, context: new stdClass());
        phasync::go(function () use (&$log) {
            phasync::sleep(0.01);
            $log[] = 'B';
        }, context: new stdClass());
        phasync::sleep(0.2);

        return $log;
    });
    expect($log)->toBe(['A done', 'B']);
});

test('a Fiber that a coroutine runs itself is not preempted', function () {
    $log = phasync::run(function () {
        $log = [];
        phasync::go(function () use (&$log) {
            $inner = new Fiber(static fn () => preemptBusy(0.05));
            $inner->start();
            $log[] = $inner->isTerminated() ? 'inner done' : 'inner suspended';
        }, context: new stdClass());
        phasync::sleep(0.1);

        return $log;
    });
    expect($log)->toBe(['inner done']);
});

test('a loop with nothing else to run just goes on after yielding', function () {
    $n = phasync::run(fn () => preemptBusy(0.05));
    expect($n)->toBeGreaterThan(0);
});

test('PHASYNC_PREEMPT_INTERVAL, defined before the loop starts, replaces PREEMPT_INTERVAL', function () {
    // In a child process (a constant can't be undefined again), from a file (code run with -r is
    // never preempted). A runs 50 ms in a loop; B wakes after 2 ms. At the default 20 ms A runs to
    // its end first; at 1 ms B gets its turn while A loops.
    $base = \tempnam(\sys_get_temp_dir(), 'phasync-preempt-');
    $file = "$base.php";
    \file_put_contents($file, '<?php
        require ' . \var_export(\dirname(__DIR__) . '/vendor/autoload.php', true) . ';
        if ("" !== $argv[1]) { define("PHASYNC_PREEMPT_INTERVAL", (float) $argv[1]); }
        echo implode(",", phasync::run(function () {
            $log = [];
            $a = phasync::go(function () use (&$log) { $end = microtime(true) + 0.05; while (microtime(true) < $end) {} $log[] = "A"; }, context: new stdClass());
            $b = phasync::go(function () use (&$log) { phasync::sleep(0.002); $log[] = "B"; }, context: new stdClass());
            phasync::await($a);
            phasync::await($b);

            return $log;
        }));');
    try {
        $child = static fn (string $interval) => \shell_exec(\escapeshellarg(\PHP_BINARY) . ' ' . \escapeshellarg($file) . ' ' . \escapeshellarg($interval));
        expect($child(''))->toBe('A,B');
        expect($child('0.001'))->toBe('B,A');
    } finally {
        \unlink($file);
        \unlink($base);
    }
});
