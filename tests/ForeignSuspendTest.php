<?php

/*
 * phasync/phasync#164: code that suspends the coroutine it runs in with Fiber::suspend(), waiting
 * for nothing phasync knows of (Drupal's EntityStorageBase::loadMultiple() does, to batch entity
 * loads), goes on in the next tick, as from phasync::yield(): its Fiber::suspend() returns null.
 */

test('a coroutine that suspends its own Fiber goes on in the next tick, its Fiber::suspend() returning null', function () {
    $log = [];
    phasync::run(function () use (&$log) {
        phasync::go(function () use (&$log) {
            phasync::yield();
            $log[] = 'other';
        });
        $log[] = 'before';
        $got   = Fiber::suspend('immediate');
        $log[] = 'after ' . \var_export($got, true);
    });
    expect($log[0])->toBe('before')->and($log)->toContain('other', 'after NULL');
});

test('a coroutine that suspends its own Fiber in its first run goes on, and can be awaited', function () {
    $result = phasync::run(function () {
        return phasync::await(phasync::go(function () {
            Fiber::getCurrent()->suspend();
            Fiber::suspend();

            return 'done';
        }));
    });
    expect($result)->toBe('done');
});

test('a coroutine suspended by its own Fiber::suspend() after a wait goes on', function () {
    $result = phasync::run(function () {
        phasync::sleep(0.01);
        Fiber::suspend();
        phasync::sleep(0.01);

        return 'done';
    });
    expect($result)->toBe('done');
});
