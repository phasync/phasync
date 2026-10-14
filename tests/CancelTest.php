<?php

use phasync\CancelledException;

test('phasync::cancel() basic tests', function () {
    phasync::run(function () {
        $counter = 0;
        $test    = phasync::go(function () use (&$counter) {
            for ($i = 0; $i < 3; ++$i) {
                ++$counter;
                phasync::sleep(1);
            }
        });
        phasync::sleep(0.1);
        phasync::cancel($test);
        expect($counter)->toBe(1);
        expect(function () use ($test) {
            return phasync::await($test);
        })->toThrow(CancelledException::class);
    });
});

test('phasync::cancel() nested phasync::run()', function () {
    phasync::run(function () {
        $counter = 0;

        $test = phasync::go(function () use (&$counter) {
            return phasync::run(function () use (&$counter) {
                for ($i = 0; $i < 3; ++$i) {
                    ++$counter;
                    phasync::sleep(0.2);
                }
            });
        });
        phasync::sleep(0.1);
        phasync::cancel($test);
        expect($counter)->toBe(1);
        phasync::sleep(0.2);
        expect($counter)->toBe(1);
        expect(function () use ($test) {
            return phasync::await($test);
        })->toThrow(CancelledException::class);
    });
});

test('phasync::cancel() handled cancellation', function () {
    phasync::run(function () {
        $counter = 0;
        $test    = phasync::go(function () use (&$counter) {
            try {
                for ($i = 0; $i < 3; ++$i) {
                    ++$counter;
                    phasync::sleep(1);
                }
            } catch (CancelledException $e) {
                $counter *= -1; // a wait here would throw again (CAN-4)
            }
        });
        phasync::sleep(0.1);
        phasync::cancel($test);
        expect($counter)->toBe(1);
        expect(phasync::await($test))->toBeNull();
        expect($counter)->toBe(-1);
    });
});

test('a coroutine that cancels itself finishes the current step and meets the cancellation at its next wait (#110)', function () {
    $log = [];
    phasync::run(function () use (&$log) {
        try {
            phasync::cancel(Fiber::getCurrent(), 'self');
            $log[] = 'after cancel';
            phasync::sleep(0);
            $log[] = 'after wait';
        } catch (CancelledException $e) {
            $log[] = 'cancelled: ' . $e->getMessage();
        }
    });
    expect($log)->toBe(['after cancel', 'cancelled: self']);
});

test('a coroutine that cancels its own frame finishes the current step and meets the cancellation at its next wait (#110)', function () {
    $log = [];
    phasync::run(function () use (&$log) {
        phasync::go(function () use (&$log) {
            phasync::run(function () use (&$log) {
                try {
                    phasync::sleep(0.01);
                    phasync::frame()->cancel();            // "remove myself from the stage"
                    $log[] = 'after cancel';
                    phasync::sleep(1);
                    $log[] = 'after wait';
                } catch (CancelledException) {
                    $log[] = 'cancelled';
                }
            });
        });
    });
    expect($log)->toBe(['after cancel', 'cancelled']);
});

test('a cancelled coroutine cancels a list of frames in its finally block, to the end (#110)', function () {
    $log = [];
    phasync::run(function () use (&$log) {
        $frames = [];
        for ($i = 0; $i < 3; ++$i) {
            phasync::go(function () use (&$frames, &$log, $i) {
                phasync::run(function () use (&$frames, &$log, $i) {
                    $frames[] = phasync::frame();
                    try {
                        phasync::sleep(10);
                    } catch (CancelledException) {
                        $log[] = "job $i cancelled";
                    }
                });
            });
        }
        $boss = phasync::go(function () use (&$frames, &$log) {
            try {
                phasync::sleep(10);
            } finally {
                foreach ($frames as $frame) {
                    $frame->cancel();
                }
                $log[] = 'loop done';
            }
        });
        phasync::sleep(0.01);
        phasync::cancel($boss);
    });
    \sort($log);
    expect($log)->toBe(['job 0 cancelled', 'job 1 cancelled', 'job 2 cancelled', 'loop done']);
});
