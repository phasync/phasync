<?php

/*
 * EventLoop::park() and unpark(): the lightweight wait for trusted code (the poller).
 */

use phasync\CancelledException;
use phasync\EventLoop;
use phasync\TimeoutException;

function park_loop(): EventLoop
{
    return (new ReflectionMethod('phasync', 'getDriver'))->invoke(null);
}

test('unpark() resumes the coroutine parked in the slot', function () {
    expect(phasync::run(function () {
        $loop   = park_loop();
        $slot   = $loop->getSlot();
        $parked = phasync::go(function () use ($loop, $slot) {
            $loop->park($slot);

            return 'resumed';
        });
        expect($loop->unpark($slot))->toBeTrue();

        return phasync::await($parked);
    }))->toBe('resumed');
});

test('getSlot() never hands out the same slot twice', function () {
    $loop = park_loop();
    expect($loop->getSlot())->not->toBe($loop->getSlot());
});

test('park() in a slot that is taken throws LogicException', function () {
    phasync::run(function () {
        $loop   = park_loop();
        $slot   = $loop->getSlot();
        $parked = phasync::go(fn () => $loop->park($slot));
        expect(fn () => $loop->park($slot))->toThrow(LogicException::class);
        $loop->unpark($slot);
        phasync::await($parked);
    });
});

test('unpark() of a vacant slot returns false, also when it was unparked already', function () {
    phasync::run(function () {
        $loop = park_loop();
        $slot = $loop->getSlot();
        expect($loop->unpark($slot))->toBeFalse();
        $parked = phasync::go(fn () => $loop->park($slot));
        expect($loop->unpark($slot))->toBeTrue();
        expect($loop->unpark($slot))->toBeFalse();
        phasync::await($parked);
    });
});

test('a parked coroutine times out, and its slot is vacated', function () {
    phasync::run(function () {
        $loop = park_loop();
        $slot = $loop->getSlot();
        expect(fn () => $loop->park($slot, 0.05))->toThrow(TimeoutException::class);
        expect($loop->unpark($slot))->toBeFalse();
    });
});

test('cancelling a parked coroutine vacates its slot at once', function () {
    phasync::run(function () {
        $loop   = park_loop();
        $slot   = $loop->getSlot();
        $parked = phasync::go(fn () => $loop->park($slot));
        phasync::cancel($parked);
        expect($loop->unpark($slot))->toBeFalse();
        expect(fn () => phasync::await($parked))->toThrow(CancelledException::class);
    });
});

test('a stream becoming ready in the tick its waiter timed out: the waiter gets TimeoutException, the loop goes on', function () {
    expect(phasync::run(function () {
        [$a, $b] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        $waiter  = phasync::go(function () use ($a) {
            try {
                phasync::readable($a, 0.05);

                return 'readable';
            } catch (TimeoutException) {
                return 'timed out';
            }
        });
        // Block the whole process past the timeout, then make the stream ready: the next tick
        // both times the waiter out and finds its stream readable
        \usleep(200000);
        \fwrite($b, 'x');

        return phasync::await($waiter);
    }))->toBe('timed out');
});
