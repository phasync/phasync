<?php

use phasync\Util\Event;

test('Event calls listeners in order, then once-listeners, with the arguments', function () {
    $event = new Event();
    $log   = [];
    $event->once(function (...$a) use (&$log) { $log[] = ['once', $a]; });
    $event->listen(function (...$a) use (&$log) { $log[] = ['a', $a]; }, function (...$a) use (&$log) { $log[] = ['b', $a]; });
    $event->trigger(1, 'x');
    expect($log)->toBe([['a', [1, 'x']], ['b', [1, 'x']], ['once', [1, 'x']]]);
});

test('Event forgets once-listeners after they ran', function () {
    $event = new Event();
    $n     = 0;
    $event->once(function () use (&$n) { ++$n; });
    $event->trigger();
    $event->trigger();
    expect($n)->toBe(1)->and($event->hasListeners())->toBeFalse();
});

test('Event off removes both kinds of listener', function () {
    $event = new Event();
    $n     = 0;
    $a     = function () use (&$n) { ++$n; };
    $b     = function () use (&$n) { $n += 10; };
    $event->listen($a, $b);
    $event->once($a);
    $event->off($a);
    $event->trigger();
    expect($n)->toBe(10);
    $event->off($b);
    expect($event->hasListeners())->toBeFalse();
});

test('Event hasListeners', function () {
    $event = new Event();
    expect($event->hasListeners())->toBeFalse();
    $event->once(fn () => null);
    expect($event->hasListeners())->toBeTrue();
});

test('Event propagates a listener exception and skips the later listeners', function () {
    $event = new Event();
    $ran   = [];
    $event->listen(function () use (&$ran) {
        $ran[] = 1;
        throw new RuntimeException('boom');
    }, function () use (&$ran) { $ran[] = 2; });
    $event->once(function () use (&$ran) { $ran[] = 3; });
    expect(fn () => $event->trigger())->toThrow(RuntimeException::class, 'boom');
    expect($ran)->toBe([1]);
});
