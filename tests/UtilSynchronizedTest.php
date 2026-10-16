<?php

use phasync\Util\Synchronized;

test('Synchronized with object token', function () {
    $log = phasync::run(function () {
        $log   = [];
        $token = new stdClass();

        $a = phasync::go(function () use ($token, &$log) {
            Synchronized::run($token, function () use (&$log) {
                $log[] = 'a';
                phasync::sleep(0.01);
            });
        });

        $b = phasync::go(function () use ($token, &$log) {
            Synchronized::run($token, function () use (&$log) {
                $log[] = 'b';
            });
        });

        phasync::await($a);
        phasync::await($b);

        return $log;
    });

    expect($log)->toBe(['a', 'b']);
});

test('Synchronized reuses same lock for same object token', function () {
    phasync::run(function () {
        $token = new stdClass();
        $log   = [];

        $a = phasync::go(function () use ($token, &$log) {
            Synchronized::run($token, function () use (&$log) {
                $log[] = 'a1';
                phasync::sleep(0.01);
            });
        });

        $b = phasync::go(function () use ($token, &$log) {
            Synchronized::run($token, function () use (&$log) {
                $log[] = 'b';
            });
        });

        phasync::sleep(0.02);

        phasync::go(function () use ($token, &$log) {
            Synchronized::run($token, function () use (&$log) {
                $log[] = 'a2';
            });
        });

        phasync::await($a);
        phasync::await($b);

        expect(\in_array('a1', $log, true))->toBeTrue();
    });
});

test('Synchronized different tokens are independent', function () {
    phasync::run(function () {
        $token1 = new stdClass();
        $token2 = new stdClass();
        $log    = [];

        $a = phasync::go(function () use ($token1, &$log) {
            Synchronized::run($token1, function () use (&$log) {
                $log[] = 'a1';
                phasync::sleep(0.01);
                $log[] = 'a2';
            });
        });

        $b = phasync::go(function () use ($token2, &$log) {
            Synchronized::run($token2, function () use (&$log) {
                $log[] = 'b1';
                $log[] = 'b2';
            });
        });

        phasync::await($a);
        phasync::await($b);

        // b should not be blocked by a since they use different tokens
        expect($log[0])->toBe('a1');
        expect($log[1])->toBe('b1');
    });
});
