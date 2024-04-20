<?php

use phasync\UsageError;

use function phasync\await;
use function phasync\defer;
use function phasync\file_get_contents;
use function phasync\go;
use function phasync\run;
use function phasync\sleep;

test('exception thrown inside coroutine', function() {
    expect(function() {
        run(function() {
            go(function() {
                throw new Exception("Exception inside coroutine");
            });
        });
    })->toThrow(new Exception("Exception inside coroutine"));
});
test('error propagation in nested coroutines', function() {
    expect(function() {
        run(function() {
            go(function() {
                go(function() {
                    throw new Exception("Nested exception");
                });
            });
        });
    })->toThrow(new Exception("Nested exception"));
});
test('exception handling with awaited coroutines', function() {
    expect(function() {
        run(function() {
            $future = go(function() {
                throw new Exception("Exception in awaited coroutine");
            });
            await($future);
        });
    })->toThrow(new Exception("Exception in awaited coroutine"));
});
test('exception in deferred cleanup function', function() {
    expect(function() {
        run(function() {
            defer(function() {
                throw new Exception("Exception in cleanup");
            });
            // Some asynchronous operation
            sleep(0.5);
        });
    })->toThrow(new Exception("Exception in cleanup"));
});
/*
test('cancellation of coroutines', function() {
    run(function() {
        $future = go(function() {
            // Long-running operation
            sleep(1);
        });

        // Cancel the coroutine
        cancel($future);

        // Attempt to await the cancelled coroutine
        expect(function() {
            await($future);
        })->toThrow(new CoroutineCancelledException("Coroutine was cancelled"));
    });
});
*/