<?php

use phasync\UsageError;

use function phasync\await;
use function phasync\file_get_contents;
use function phasync\go;
use function phasync\run;
use function phasync\sleep;

// Test for sleep() function inside run()
test('test sleep() inside run()', function() {
    run(function() {
        $startTime = microtime(true);
        sleep(0.1); // Sleep for 0.1 seconds
        $endTime = microtime(true);
        $elapsed = $endTime - $startTime;
        // Ensure that the elapsed time is approximately 0.1 seconds
        expect($elapsed)->toBeGreaterThan(0.09);
        expect($elapsed)->toBeLessThan(0.12);
    });
});

// Test for sleep() function outside run()
test('test sleep() outside run()', function() {
    $startTime = microtime(true);
    sleep(0.1); // Sleep for 0.1 seconds
    $endTime = microtime(true);
    $elapsed = $endTime - $startTime;
    // Ensure that the elapsed time is approximately 0.1 seconds
    expect($elapsed)->toBeGreaterThan(0.09);
    expect($elapsed)->toBeLessThan(0.12);
});

// Test for file_get_contents() function inside run()
test('test file_get_contents() inside run()', function() {
    run(function() {
        $filename = __FILE__; // Replace with your file name
        $content = file_get_contents($filename);
        expect($content)->toBeString(); // Ensure the content is a string
        // Add assertions to validate the content as needed
    });
});

// Test for file_get_contents() function outside run()
test('test file_get_contents() outside run()', function() {
    $filename = __FILE__; // Replace with your file name
    $content = file_get_contents($filename);
    expect($content)->toBeString(); // Ensure the content is a string
    // Add assertions to validate the content as needed
});

// Add similar tests for other file functions, stream functions, and sleep()

// Test for go() function inside run()
test('test go() inside run()', function() {
    run(function() {
        $fiber = go(function() {
            return 42; // Any value you want to return
        });
        expect($fiber)->toBeInstanceOf(Fiber::class); // Verify the return value is a Fiber instance
    });
});

// Test for go() function outside run()
test('test go() outside run()', function() {
    expect(function() {
        $fiber = go(function() {
            return 42; // Any value you want to return
        });
    })->toThrow(UsageError::class); // Expect UsageError when go() is used outside run()
});

// Test for await() function inside run()
test('test await() inside run()', function() {
    run(function() {
        $fiber = go(function() {
            return 42;
        });
        $result = await($fiber);
        expect($result)->toBe(42); // Verify the return value
    });
});

// Test for await() function outside run()
test('test await() outside run()', function() {
    expect(function() {
        $fiber = go(function() {
            return 42;
        });
        $result = await($fiber);
    })->toThrow(UsageError::class); // Expect UsageError when await() is used outside run()
});
