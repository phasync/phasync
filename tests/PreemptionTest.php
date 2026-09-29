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
            preemptBusy(0.2);
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
        $request = new stdClass();
        phasync::withContext(function () use (&$log) {
            phasync::go(function () use (&$log) {
                $log[] = 'A1 starts';
                preemptBusy(0.1);
                $log[] = 'A1 done';
            });
            phasync::go(function () use (&$log) {
                phasync::sleep(0.01);
                $log[] = 'A2';
            });
        }, $request);
        phasync::go(function () use (&$log) { // another request, which does run meanwhile
            phasync::sleep(0.02);
            $log[] = 'B';
        }, context: new stdClass());
        phasync::sleep(0.2);

        return $log;
    });
    expect($log)->toBe(['A1 starts', 'B', 'A1 done', 'A2']);
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

test('a loop with nothing else to run just goes on after yielding', function () {
    $n = phasync::run(fn () => preemptBusy(0.05));
    expect($n)->toBeGreaterThan(0);
});
