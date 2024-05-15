<?php

use function phasync\run;

test('test the run() return value when not blocking', function () {
    expect(run(function() {
        return 1;
    }))->toBe(1);
});

test('test the run() return value when blocked once', function() {
    expect(run(function() {
        phasync\sleep(0.01);
        return 1;
    }))->toBe(1);
});

test('test the return value from an inner run()', function() {
    expect(run(function() {
        phasync\sleep(0.01);
        return run(function() {
            phasync\sleep(0.01);
            return 1;
        });
    }))->toBe(1);
});

test('test deeply nested run() calls', function() {
    expect(run(function() {
        return run(function() {
            return run(function() {
                phasync\sleep(0.01);
                return 1;
            });
        });
    }))->toBe(1);
});

test('test that run() throws exception when not blocked', function() {
    expect(function() {
        run(function() {
          throw new Exception("Yes");
        });
    })->toThrow(new Exception("Yes"));
});

test('test that run() throws exception when blocked', function() {
    expect(function() {
        run(function() {
            phasync\sleep(0.01);
            throw new Exception("Yes");
        });
    })->toThrow(new Exception("Yes"));
});

test('test error propagation in nested run() calls', function() {
    expect(function() {
        run(function() {
            run(function() {
                throw new Exception("Inner Error");
            });
        });
    })->toThrow(new Exception("Inner Error"));
});

test('test multiple concurrent runs', function() {
    $results = [];
    run(function() use (&$results) {
        phasync\go(function() use (&$results) {
            phasync\sleep(0.02); // Slightly longer sleep
            $results[] = 'Coroutine 1';
        });

        phasync\go(function() use (&$results) {
            $results[] = 'Coroutine 2'; 
        });
    });

    expect($results)->toEqual(['Coroutine 2', 'Coroutine 1']); 
    // Note: The order may vary due to concurrency 
});

test('test that run() throws a lost exception in an orphaned go()', function() {
    expect(function() {
        run(function() {
            phasync\go(function() {
                throw new Exception("Yes");
            });            
        });
    })->toThrow(new Exception("Yes"));
});

test('test that run() throws a lost exception in an orphaned go() when blocked', function() {
    expect(function() {
        run(function() {
            phasync\go(function() {
                throw new Exception("Yes");
            });            
            phasync\sleep(0.1);
        });
    })->toThrow(new Exception("Yes"));
});

test('test that run() throws the exception from the inner go, even when a return value is provided', function() {
    expect(function() {
        run(function() {
            phasync\go(function() {
                phasync\sleep(0.01);
                throw new Exception("Yes");
            });
            return 1;
        });
    })->toThrow(new Exception("Yes"));
});

test('complex nested run() calls concurrently', function() {
    phasync::run(function() {
        $startTime = microtime(true);
        $totalTime = 0;
        $wg = phasync::waitGroup();

        phasync::go(function() use ($wg, &$totalTime) {
            $startTime = microtime(true);
            $wg->add();
            phasync::run(function() use (&$totalTime) {
                $startTime = microtime(true);
                phasync::sleep(0.2);
                $totalTime += microtime(true) - $startTime;
                phasync::run(function() use (&$totalTime) {
                    $startTime = microtime(true);
                    phasync::sleep(0.2);
                    $totalTime += microtime(true) - $startTime;
                });
            });
            $totalTime += microtime(true) - $startTime;
            $wg->done();
        });
        phasync::go(function() use ($wg, &$totalTime) {
            $startTime = microtime(true);
            $wg->add();
            phasync::run(function() use (&$totalTime) {
                $startTime = microtime(true);
                phasync::sleep(0.2);
                $totalTime += microtime(true) - $startTime;
            });
            $totalTime += microtime(true) - $startTime;
            $wg->done();
        });
        phasync::go(function() use ($wg, &$totalTime) {
            $startTime = microtime(true);
            $wg->add();
            phasync::run(function() use (&$totalTime) {
                $startTime = microtime(true);
                phasync::sleep(0.2);
                $totalTime += microtime(true) - $startTime;
            });
            $totalTime += microtime(true) - $startTime;
            $wg->done();
        });
        $wg->wait();
        expect(microtime(true)-$startTime)->toBeLessThan(0.5)->toBeGreaterThan(0.35);
        expect($totalTime)->toBeLessThan(1.7)->toBeGreaterThan(1.5);
    });
});