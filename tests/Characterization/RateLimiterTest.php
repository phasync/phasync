<?php

/*
 * Characterization tests for phasync\Util\RateLimiter (RL-1).
 * They pin today's behaviour. See tests/Characterization/README.md before editing.
 * A failing test here means: stop and tell the maintainer.
 *
 * Timing assertions use generous absolute bounds. Waits are measured in milliseconds
 * from the moment the limiter was created (or, where noted, from just after).
 */

use phasync\ChannelException;
use phasync\SelectableInterface;
use phasync\Util\RateLimiter;

uses()->group('characterization');

/*
 * The RateLimiter constructor starts its token generator with go(). Whether the generator
 * gets to place its first token before the constructor returns depends on whether go()'s
 * preempt() suspends the caller, which it only does when the preempt interval has elapsed
 * since the last preempt() call (SCH-5, wall-clock dependent). The tests that observe that
 * moment say explicitly which of the two situations they are in.
 */
function rlPreemptIsDue(bool $due): void
{
    (new ReflectionProperty(phasync::class, 'lastPreemptTime'))
        ->setValue(null, $due ? \hrtime(true) - 2 * phasync::DEFAULT_PREEMPT_INTERVAL : \hrtime(true));
}

test('RL-1: a rate of zero or less throws InvalidArgumentException', function () {
    foreach ([0, -1, 0.0] as $rate) {
        $out = null;
        try {
            new RateLimiter($rate);
        } catch (Throwable $e) {
            $out = [\get_class($e), $e->getMessage()];
        }
        expect($out)->toBe([InvalidArgumentException::class, 'Events per second must be greater than 0']);
    }
});

test('RL-1: a RateLimiter cannot be created outside a coroutine', function () {
    $out = null;
    try {
        new RateLimiter(10);
    } catch (Throwable $e) {
        $out = [\get_class($e), $e->getMessage()];
    }

    expect($out)->toBe([LogicException::class, 'This function can not be used outside of a coroutine']);
});

test('RL-1: at 10 events per second the first wait is immediate and each following wait comes about 100 ms later', function () {
    $ts = phasync::run(function () {
        $rl = new RateLimiter(10);
        $t0 = \microtime(true);
        $ts = [];
        for ($i = 0; $i < 5; ++$i) {
            $rl->wait();
            $ts[] = (\microtime(true) - $t0) * 1000;
        }

        return $ts;
    });

    expect($ts[0])->toBeLessThan(50);
    foreach ([1, 2, 3, 4] as $k) {
        // Never earlier than the interval allows (small slack for the limiter starting just before t0)
        expect($ts[$k])->toBeGreaterThanOrEqual($k * 100 - 20)->toBeLessThan($k * 100 + 250);
    }
});

test('RL-1: with a burst of 3, four waits are immediate after an idle period and the fifth waits for the rate', function () {
    // Four, not three: the token the generator is blocked on is delivered as well.
    $ts = phasync::run(function () {
        $rl = new RateLimiter(10, 3);
        phasync::sleep(0.35);
        $t0 = \microtime(true);
        $ts = [];
        for ($i = 0; $i < 5; ++$i) {
            $rl->wait();
            $ts[] = (\microtime(true) - $t0) * 1000;
        }

        return $ts;
    });

    foreach ([0, 1, 2, 3] as $k) {
        expect($ts[$k])->toBeLessThan(60);
    }
    expect($ts[4])->toBeGreaterThanOrEqual(60)->toBeLessThan(400);
});

test('RL-1: two coroutines share one rate', function () {
    $ts = phasync::run(function () {
        $rl = new RateLimiter(20);
        $t0 = \microtime(true);
        $ts = [];
        $cs = [];
        foreach ([1, 2] as $n) {
            $cs[] = phasync::go(function () use ($rl, &$ts, $t0) {
                for ($i = 0; $i < 3; ++$i) {
                    $rl->wait();
                    $ts[] = (\microtime(true) - $t0) * 1000;
                }
            });
        }
        foreach ($cs as $c) {
            phasync::await($c);
        }
        \sort($ts);

        return $ts;
    });

    expect($ts)->toHaveCount(6);
    // Six tokens at 20/s: the sixth cannot arrive before 250 ms
    expect(\end($ts))->toBeGreaterThanOrEqual(230)->toBeLessThan(700);
});

test('RL-1: isReady() is true while a token is available and false right after it was taken', function () {
    rlPreemptIsDue(true); // go() in the constructor suspends the caller, so the first token is offered
    $out = phasync::run(function () {
        $rl    = new RateLimiter(5);
        $out   = [$rl->isReady()];
        phasync::sleep(0.01);
        $out[] = $rl->isReady();
        $rl->wait();
        $out[] = $rl->isReady();
        phasync::sleep(0.25);
        $out[] = $rl->isReady();

        return $out;
    });

    expect($out)->toBe([true, true, false, true]);
});

test('RL-1: await() ignores its timeout argument and waits for a token without throwing [DIVERGENCE]', function () {
    // SEMANTICS SEL/TMO expect a timeout parameter to be honoured.
    [$elapsed, $threw] = phasync::run(function () {
        $rl = new RateLimiter(2);
        $rl->wait();
        $t     = \microtime(true);
        $threw = false;
        try {
            $rl->await(0.01);
        } catch (Throwable) {
            $threw = true;
        }

        return [\microtime(true) - $t, $threw];
    });

    expect($threw)->toBeFalse();
    expect($elapsed)->toBeGreaterThanOrEqual(0.4);
})->group('divergence');

test('RL-1: wait() behaves like await() and a RateLimiter is a SelectableInterface', function () {
    $out = phasync::run(function () {
        $rl = new RateLimiter(50);
        $rl->wait();
        $rl->await();

        return $rl instanceof SelectableInterface;
    });

    expect($out)->toBeTrue();
});

test('RL-1: run() finishes normally when the limiter is dropped after its last token was taken', function () {
    $t   = \microtime(true);
    $out = phasync::run(function () {
        $rl = new RateLimiter(50);
        $rl->wait();

        return 'done';
    });

    expect($out)->toBe('done');
    expect(\microtime(true) - $t)->toBeLessThan(3.0);
});

test('RL-1: dropping a limiter that still holds an untaken token lets run() finish normally', function () {
    // Was a [SURPRISE]: an uncaught ChannelException used to escape run() just from
    // dropping an unused RateLimiter. Fixed as a side effect of CHN-1: the token
    // generator's write() now genuinely blocks until a reader consumes it (so it is
    // actually blocked in write(), not mid-cycle, when the read end is dropped); write()
    // woken by the channel closing returns normally rather than throwing (see Channel::
    // write()'s unbuffered branch -- it returns unconditionally once awaitWritable()
    // resolves, whatever the reason), so the generator's do/while loop just sees
    // isClosed() and exits cleanly instead of throwing.
    $out = phasync::run(function () {
        $rl = new RateLimiter(50);
        phasync::sleep(0.2);

        return 'body finished';
    });

    expect($out)->toBe('body finished');
});

test('RL-1: a limiter that was never used can be dropped without error', function () {
    rlPreemptIsDue(true); // go() in the constructor suspends the caller, so the generator is already waiting for a reader
    $out = phasync::run(function () {
        new RateLimiter(50);

        return 'done';
    });

    expect($out)->toBe('done');
});

test('RL-1: when go() does not suspend the constructor, no token is offered yet and dropping an unused limiter makes run() throw ChannelException [SURPRISE]', function () {
    // The other side of the two RL-1 tests above: the generator has only reached the yield at the
    // start of Channel::write() when the limiter goes away, so it wakes to a closed channel.
    rlPreemptIsDue(false);
    $ready = null;
    $out   = null;
    try {
        phasync::run(function () use (&$ready) {
            $rl    = new RateLimiter(50);
            $ready = $rl->isReady();

            return 'done';
        });
    } catch (Throwable $e) {
        $out = [\get_class($e), $e->getMessage()];
    }

    expect($ready)->toBeFalse();
    expect($out)->toBe([ChannelException::class, 'Channel is closed']);
})->group('surprise');
