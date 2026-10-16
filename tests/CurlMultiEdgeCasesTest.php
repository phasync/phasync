<?php

use phasync\Services\CurlMulti;

/**
 * Edge case tests for CurlMulti class
 *
 * These tests cover error conditions and edge cases that are hard to trigger
 * in normal operation.
 */

// Port 1 on the loopback refuses at once: no network, no timeout
function refusedHandle(): CurlHandle
{
    $ch = \curl_init('http://127.0.0.1:1');
    \curl_setopt($ch, \CURLOPT_RETURNTRANSFER, true);

    return $ch;
}

test('CurlMulti returns an empty body for a refused connection', function () {
    $ch = refusedHandle();

    expect(phasync::run(fn () => CurlMulti::await($ch)))->toBe('');
    expect(\curl_errno($ch))->toBe(\CURLE_COULDNT_CONNECT);
});

test('CurlMulti multiple handles in parallel', function () {
    $handles = [refusedHandle(), refusedHandle(), refusedHandle()];

    $results = phasync::run(function () use ($handles) {
        $tasks = [];
        foreach ($handles as $ch) {
            $tasks[] = phasync::go(fn () => CurlMulti::await($ch));
        }

        return \array_map(phasync::await(...), $tasks);
    });

    expect($results)->toBe(['', '', '']);
    foreach ($handles as $ch) {
        expect(\curl_errno($ch))->toBe(\CURLE_COULDNT_CONNECT);
    }
});
