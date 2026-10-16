<?php

use phasync\Internal\Scheduler;

test('fibers come out in the order of their timestamps', function () {
    $scheduler = new Scheduler();
    $late      = new Fiber(static fn () => null);
    $early     = new Fiber(static fn () => null);
    $scheduler->schedule(20.0, $late);
    $scheduler->schedule(10.0, $early);

    expect($scheduler->getNextTimestamp())->toBe(10.0);
    expect($scheduler->extract())->toBe($early);
    expect($scheduler->getNextTimestamp())->toBe(20.0);
    expect($scheduler->extract())->toBe($late);
});

test('an empty scheduler has no next timestamp and extracts nothing', function () {
    $scheduler = new Scheduler();

    expect($scheduler->getNextTimestamp())->toBeNull();
    expect($scheduler->extract())->toBeNull();
});

test('a fiber cannot be scheduled twice', function () {
    $scheduler = new Scheduler();
    $fiber     = new Fiber(static fn () => null);
    $scheduler->schedule(1.0, $fiber);

    expect(fn () => $scheduler->schedule(2.0, $fiber))->toThrow(LogicException::class, 'The Fiber is already scheduled');
});

test('fibers can only be added with schedule()', function () {
    $scheduler = new Scheduler();

    expect(fn () => $scheduler->insert(new Fiber(static fn () => null)))->toThrow(LogicException::class, "Can't insert this way");
});
