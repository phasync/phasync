<?php

use function phasync\await;
use function phasync\file_get_contents;
use function phasync\go;
use function phasync\run;
use function phasync\sleep;

// Test for sleep() function inside phasync::run()
test('test sleep() inside phasync::run()', function () {
    phasync::run(function () {
        $startTime = \microtime(true);
        sleep(0.1); // Sleep for 0.1 seconds
        $endTime = \microtime(true);
        $elapsed = $endTime - $startTime;
        // Ensure that the elapsed time is approximately 0.1 seconds
        expect($elapsed)->toBeGreaterThan(0.09);
        expect($elapsed)->toBeLessThan(0.12);
    });
});

// Test for sleep() function outside phasync::run()
test('test sleep() outside phasync::run()', function () {
    $startTime = \microtime(true);
    sleep(0.1); // Sleep for 0.1 seconds
    $endTime = \microtime(true);
    $elapsed = $endTime - $startTime;
    // Ensure that the elapsed time is approximately 0.1 seconds
    expect($elapsed)->toBeGreaterThan(0.09);
    expect($elapsed)->toBeLessThan(0.12);
});

// Test for file_get_contents() function inside phasync::run()
test('test file_get_contents() inside phasync::run()', function () {
    phasync::run(function () {
        $filename = __FILE__; // Replace with your file name
        $content  = file_get_contents($filename);
        expect($content)->toBeString(); // Ensure the content is a string
        // Add assertions to validate the content as needed
    });
});

// Test for file_get_contents() function outside phasync::run()
test('test file_get_contents() outside phasync::run()', function () {
    $filename = __FILE__; // Replace with your file name
    $content  = file_get_contents($filename);
    expect($content)->toBeString(); // Ensure the content is a string
    // Add assertions to validate the content as needed
});

// Add similar tests for other file functions, stream functions, and sleep()

// Test for go() function inside phasync::run()
test('test go() inside phasync::run()', function () {
    phasync::run(function () {
        $fiber = go(function () {
            return 42; // Any value you want to return
        });
        expect($fiber)->toBeInstanceOf(Fiber::class); // Verify the return value is a Fiber instance
    });
});

// Test for go() function outside phasync::run()
test('test go() outside phasync::run()', function () {
    expect(function () {
        $fiber = go(function () {
            return 42; // Any value you want to return
        });
    })->toThrow(LogicException::class); // Expect LogicException when go() is used outside phasync::run()
});

// Test for await() function inside phasync::run()
test('test await() inside phasync::run()', function () {
    phasync::run(function () {
        $fiber = go(function () {
            return 42;
        });
        $result = await($fiber);
        expect($result)->toBe(42); // Verify the return value
    });
});

// Test for await() function outside phasync::run()
test('test await() outside phasync::run()', function () {
    expect(function () {
        $fiber = go(function () {
            return 42;
        });
        $result = await($fiber);
    })->toThrow(LogicException::class); // Expect LogicException when await() is used outside phasync::run()
});
