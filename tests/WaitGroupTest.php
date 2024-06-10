<?php

use phasync\Util\WaitGroup;

phasync::setDefaultTimeout(10);

test('test WaitGroup add and done functionality', function () {
    expect(function () {
        phasync::run(function () {
            $wg = new WaitGroup();
            $wg->add();
            $sharedVar = 0;

            phasync::go(function () use ($wg, &$sharedVar) {
                phasync::sleep(0.1); // Simulate some work
                ++$sharedVar;
                $wg->done();
            });

            $wg->await(); // Should wait until the coroutine calls done()
            expect($sharedVar)->toBe(1);
        });
    })->not->toThrow(Throwable::class);
});

test('test WaitGroup await with multiple coroutines', function () {
    expect(function () {
        phasync::run(function () {
            $wg        = new WaitGroup();
            $sharedVar = 0;

            // Add work for three coroutines
            $wg->add();
            $wg->add();
            $wg->add();

            phasync::go(function () use ($wg, &$sharedVar) {
                phasync::sleep(0.1); // Simulate some work
                ++$sharedVar;
                $wg->done();
            });

            phasync::go(function () use ($wg, &$sharedVar) {
                phasync::sleep(0.2); // Simulate some work
                ++$sharedVar;
                $wg->done();
            });

            phasync::go(function () use ($wg, &$sharedVar) {
                phasync::sleep(0.3); // Simulate some work
                ++$sharedVar;
                $wg->done();
            });

            $wg->await(); // Should wait until all coroutines call done()
            expect($sharedVar)->toBe(3);
        });
    })->not->toThrow(Throwable::class);
});

test('test WaitGroup await with no work', function () {
    expect(function () {
        phasync::run(function () {
            $wg = new WaitGroup();
            $wg->await(); // Should not block since no work was added
        });
    })->not->toThrow(Throwable::class);
});

test('test WaitGroup done without add', function () {
    expect(function () {
        phasync::run(function () {
            $wg = new WaitGroup();
            $wg->done(); // Should throw LogicException
        });
    })->toThrow(LogicException::class);
});

test('test WaitGroup add and done repeatedly', function () {
    expect(function () {
        phasync::run(function () {
            $wg        = new WaitGroup();
            $sharedVar = 0;

            // Add and done repeatedly
            $wg->add();
            phasync::go(function () use ($wg, &$sharedVar) {
                phasync::sleep(0.1); // Simulate some work
                ++$sharedVar;
                $wg->done();
            });

            $wg->await(); // Wait for the first coroutine to finish
            expect($sharedVar)->toBe(1);

            $wg->add();
            phasync::go(function () use ($wg, &$sharedVar) {
                phasync::sleep(0.1); // Simulate some work
                ++$sharedVar;
                $wg->done();
            });

            $wg->await(); // Wait for the second coroutine to finish
            expect($sharedVar)->toBe(2);

            // Ensure it works for multiple adds and dones
            for ($i = 0; $i < 5; ++$i) {
                $wg->add();
                phasync::go(function () use ($wg, &$sharedVar) {
                    phasync::sleep(0.1); // Simulate some work
                    ++$sharedVar;
                    $wg->done();
                });
            }

            $wg->await(); // Wait for all coroutines to finish
            expect($sharedVar)->toBe(7);
        });
    })->not->toThrow(Throwable::class);
});
