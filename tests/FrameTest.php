<?php

use phasync\TimeoutException;

test('a frame refuses cancel(), setTimeout() and requestCancellation() after its run() returned', function () {
    $frame = null;
    phasync::run(function () use (&$frame) {
        $frame = phasync::frame();
    });

    expect(fn () => $frame->cancel())->toThrow(LogicException::class, 'The run() of this frame has returned');
    expect(fn () => $frame->setTimeout(1.0))->toThrow(LogicException::class, 'The run() of this frame has returned');
    expect(fn () => $frame->requestCancellation())->toThrow(LogicException::class, 'The run() of this frame has returned');
});

test('a timed out frame whose function then fails with something else throws that failure', function () {
    $run = fn () => phasync::run(function () {
        phasync::frame()->setTimeout(0.01);
        try {
            phasync::sleep(5);
        } catch (phasync\CancelledException) {
            throw new RuntimeException('unrelated');
        }
    });

    expect($run)->toThrow(RuntimeException::class, 'unrelated');
});

test('a timed out frame throws the TimeoutException', function () {
    $run = fn () => phasync::run(function () {
        phasync::frame()->setTimeout(0.01);
        phasync::sleep(5);
    });

    expect($run)->toThrow(TimeoutException::class);
});
