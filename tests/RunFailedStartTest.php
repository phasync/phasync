<?php

test('a run that fails to start does not leave the loop running', function () {
    $ctx = new stdClass();
    phasync::run(fn () => 1, [], $ctx);
    expect(fn () => phasync::run(fn () => 1, [], $ctx))->toThrow(phasync\ContextUsedException::class);

    expect(phasync::isRunning())->toBeFalse();
    $loop   = new ReflectionProperty(phasync\EventLoop::class, 'rootRunContext');
    $driver = (new ReflectionMethod(phasync::class, 'getDriver'))->invoke(null);
    expect($loop->getValue($driver))->toBeNull();
});
