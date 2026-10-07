<?php

use phasync\TimeoutException;

/*
 * phasync::signal(): coroutines wait for POSIX signals. The handler only records a signal; the
 * event loop wakes the waiters between coroutines, never inside one, so they may do anything.
 */

foreach ([false, true] as $async) {
    $mode = $async ? 'async signals' : 'dispatched by the loop';

    test("a coroutine waiting for a signal gets it ($mode)", function () use ($async) {
        pcntl_async_signals($async);
        $got = phasync::run(function () {
            $waiter = phasync::go(fn () => phasync::signal(SIGUSR1, 2.0));
            phasync::sleep(0.01);
            posix_kill(getmypid(), SIGUSR1);

            return phasync::await($waiter);
        });
        expect($got)->toBe(SIGUSR1);
    });

    test("every waiter for a signal is woken, and one waiting for several gets the one that came ($mode)", function () use ($async) {
        pcntl_async_signals($async);
        $got = phasync::run(function () {
            $a = phasync::go(fn () => phasync::signal(SIGUSR2, 2.0));
            $b = phasync::go(fn () => phasync::signal([SIGUSR1, SIGUSR2], 2.0));
            phasync::sleep(0.01);
            posix_kill(getmypid(), SIGUSR2);

            return [phasync::await($a), phasync::await($b)];
        });
        expect($got)->toBe([SIGUSR2, SIGUSR2]);
    });
}

test('a signal that came before the wait began does not end it', function () {
    pcntl_async_signals(false);
    expect(fn () => phasync::run(function () {
        posix_kill(getmypid(), SIGUSR1);
        phasync::sleep(0.01);   // delivered and counted here, before anyone waits

        return phasync::signal(SIGUSR1, 0.05);
    }))->toThrow(TimeoutException::class);
});

test('a waiter may await and do I/O after the signal, as any coroutine does', function () {
    pcntl_async_signals(true);
    $got = phasync::run(function () {
        $waiter = phasync::go(function () {
            $signo = phasync::signal(SIGUSR1, 2.0);
            phasync::sleep(0.01);

            return "cleaned up after $signo";
        });
        phasync::sleep(0.01);
        posix_kill(getmypid(), SIGUSR1);

        return phasync::await($waiter);
    });
    expect($got)->toBe('cleaned up after ' . SIGUSR1);
});

test('onSignal() runs at once, even inside a coroutine that never yields, and waiters are woken as well', function () {
    pcntl_async_signals(true);
    $log = [];
    phasync::onSignal(SIGUSR1, function (int $signo) use (&$log) { $log[] = "handler $signo"; });
    $got = phasync::run(function () use (&$log) {
        $waiter = phasync::go(fn () => phasync::signal(SIGUSR1, 2.0));
        phasync::sleep(0.01);
        posix_kill(getmypid(), SIGUSR1);
        $log[] = 'busy loop goes on';     // the handler ran before this line: it interrupts code that never yields

        return phasync::await($waiter);
    });
    expect($got)->toBe(SIGUSR1);
    expect($log)->toBe(['handler ' . SIGUSR1, 'busy loop goes on']);
});
