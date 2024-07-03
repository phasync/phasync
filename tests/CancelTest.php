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
                $counter *= -1;
                phasync::sleep(0.2);
            }
        });
        phasync::sleep(0.1);
        phasync::cancel($test);
        expect($counter)->toBe(1);
        expect(phasync::await($test))->toBeNull();
        expect($counter)->toBe(-1);
    });
});
