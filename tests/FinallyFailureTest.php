<?php

test('a coroutine with a finally() callback still fails the run', function () {
    $ran = false;
    expect(function () use (&$ran) {
        phasync::run(function () use (&$ran) {
            phasync::go(function () use (&$ran) {
                phasync::finally(function () use (&$ran) {
                    $ran = true;
                });
                phasync::sleep(0.01);
                throw new RuntimeException('boom');
            });
        });
    })->toThrow(new RuntimeException('boom'));
    expect($ran)->toBeTrue();
});
