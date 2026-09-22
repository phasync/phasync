<?php

/*
 * Characterization tests for docs/SEMANTICS.md section 6 (TMO-1 .. TMO-3).
 * They pin how timeouts behave TODAY. Read tests/Characterization/README.md
 * before changing anything here.
 *
 * Timing facts these tests rely on (both are deliberate design decisions):
 *  - deadlines are checked at most every 0.1 s, and
 *  - an otherwise idle loop sleeps 0.5 s at a time (decision D11 is about the latter).
 * Lower bounds ("never early") are strict; upper bounds are generous so that a busy
 * machine does not make the tests flaky.
 */

use phasync\TimeoutException;

uses()->group('characterization');

if (!\function_exists('tmoPair')) {
    /** A connected socket pair. Keep both ends referenced (a closed peer makes the other end readable). */
    function tmoPair(): array
    {
        return \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
    }

    /**
     * Run $fn and return [outcome, elapsed seconds]. The outcome is the class name of the
     * exception it threw, or 'ok'.
     */
    function tmoTimed(Closure $fn): array
    {
        $start = \microtime(true);
        try {
            $fn();
            $outcome = 'ok';
        } catch (Throwable $e) {
            $outcome = $e::class;
        }

        return [$outcome, \microtime(true) - $start];
    }

    /** Set the default timeout for the duration of $fn, restoring it afterwards. */
    function tmoWithDefaultTimeout(float $timeout, Closure $fn): mixed
    {
        $original = phasync::getDefaultTimeout();
        phasync::setDefaultTimeout($timeout);
        try {
            return $fn();
        } finally {
            phasync::setDefaultTimeout($original);
        }
    }
}

// ---------------------------------------------------------------------------
// TMO-1: implicit timeouts
// ---------------------------------------------------------------------------

test('TMO-1: the default timeout is 30 seconds', function () {
    expect(phasync::DEFAULT_TIMEOUT)->toBe(30.0);
    // Other test files change the ambient default while loading, so read the pristine value
    // in a fresh process.
    $autoload = \dirname(__DIR__, 2) . '/vendor/autoload.php';
    $initial  = \trim((string) \shell_exec(
        \escapeshellarg(\PHP_BINARY) . ' -r ' . \escapeshellarg('require ' . \var_export($autoload, true) . '; echo phasync::getDefaultTimeout();')
    ));
    expect((float) $initial)->toBe(30.0);
});

test('TMO-1: awaitFlag, await, channel read and sleep ignore the default timeout', function () {
    $results = tmoWithDefaultTimeout(0.2, static function () {
        return phasync::run(static function () {
            $flag = new stdClass();
            phasync::channel($r, $w);
            $sleeper = phasync::go(static fn () => phasync::sleep(0.6));

            $waiters = [
                'awaitFlag'    => phasync::go(static function () use ($flag) {
                    phasync::awaitFlag($flag);

                    return 'ok';
                }),
                'await'        => phasync::go(static function () use ($sleeper) {
                    phasync::await($sleeper);

                    return 'ok';
                }),
                'channel read' => phasync::go(static fn () => $r->read()),
                'sleep'        => phasync::go(static function () {
                    phasync::sleep(0.6);

                    return 'ok';
                }),
            ];
            phasync::go(static function () use ($flag, $w) {
                phasync::sleep(0.6);
                phasync::raiseFlag($flag);
                $w->write('late');
            });

            $results = [];
            foreach ($waiters as $name => $fiber) {
                try {
                    $results[$name] = phasync::await($fiber);
                } catch (Throwable $e) {
                    $results[$name] = $e::class;
                }
            }

            return $results;
        });
    });

    expect($results)->toBe([
        'awaitFlag'    => 'ok',
        'await'        => 'ok',
        'channel read' => 'late',
        'sleep'        => 'ok',
    ]);
});

test('TMO-1: readable() and stream() apply the default timeout when none is given [DIVERGENCE]', function () {
    // The contract (TMO-1) says every operation waits forever unless the caller passes a
    // timeout. Decision D6 is open.
    $results = tmoWithDefaultTimeout(0.2, static function () {
        return phasync::run(static function () {
            [$a, $keepA] = tmoPair();
            [$c, $keepC] = tmoPair();

            return [
                'readable' => tmoTimed(static fn () => phasync::readable($a))[0],
                'stream'   => tmoTimed(static fn () => phasync::stream($c, phasync::READABLE))[0],
            ];
        });
    });

    expect($results)->toBe([
        'readable' => TimeoutException::class,
        'stream'   => TimeoutException::class,
    ]);
})->group('divergence');

// ---------------------------------------------------------------------------
// TMO-2: a timeout throws TimeoutException and leaves things consistent
// ---------------------------------------------------------------------------

test('TMO-2: awaitFlag() with a timeout throws TimeoutException naming the coroutine, and the flag stays usable', function () {
    $result = phasync::run(static function () {
        $flag = new stdClass();
        $log  = [];
        try {
            phasync::awaitFlag($flag, 0.05);
        } catch (TimeoutException $e) {
            $log[] = \str_starts_with($e->getMessage(), 'Operation timed out for Fiber');
        }
        $waiter = phasync::go(static function () use ($flag, &$log) {
            phasync::awaitFlag($flag, 5);
            $log[] = 'woken';
        });
        phasync::sleep(0.01);
        $log[] = phasync::raiseFlag($flag);
        phasync::await($waiter);

        return $log;
    });

    expect($result)->toBe([true, 1, 'woken']);
});

test('TMO-2: await($fiber, $timeout) throws TimeoutException and leaves the awaited coroutine running', function () {
    $result = phasync::run(static function () {
        $target = phasync::go(static function () {
            phasync::sleep(0.7);

            return 'target result';
        });
        [$outcome] = tmoTimed(static fn () => phasync::await($target, 0.1));

        return [$outcome, $target->isTerminated(), phasync::await($target)];
    });

    expect($result)->toBe([TimeoutException::class, false, 'target result']);
});

test('TMO-2: readable() with a timeout throws TimeoutException and leaves the stream open and usable', function () {
    $result = phasync::run(static function () {
        [$a, $b]   = tmoPair();
        [$outcome] = tmoTimed(static fn () => phasync::readable($a, 0.1));
        \fwrite($b, 'z');

        return [$outcome, \is_resource($a), phasync::readable($a, 1) === $a, \fread($a, 1)];
    });

    expect($result)->toBe([TimeoutException::class, true, true, 'z']);
});

test('TMO-2: writable() on a full socket buffer throws TimeoutException', function () {
    $outcome = phasync::run(static function () {
        [$a, $b] = tmoPair();
        \stream_set_blocking($a, false);
        while (\fwrite($a, \str_repeat('x', 65536)) > 0) {
        }

        return tmoTimed(static fn () => phasync::writable($a, 0.1))[0];
    });

    expect($outcome)->toBe(TimeoutException::class);
});

test('TMO-2: a channel read timeout throws TimeoutException and the channel stays usable', function () {
    $result = phasync::run(static function () {
        phasync::channel($r, $w);
        $worker = phasync::go(static function () use ($r, $w) {
            $log = [];
            try {
                $r->read(0.1);
            } catch (TimeoutException $e) {
                $log[] = $e->getMessage();
            }
            $w->write('after');
            $log[] = $r->read();

            return $log;
        });

        return phasync::await($worker);
    });

    expect($result)->toBe(['Channel read operation timed out', 'after']);
});

test('TMO-2: a channel write timeout on a full buffered channel throws TimeoutException and the value is not delivered', function () {
    $result = phasync::run(static function () {
        phasync::channel($r, $w, 1);
        $w->write('first');
        $writer = phasync::go(static function () use ($w) {
            try {
                $w->write('second', 0.1);
            } catch (TimeoutException $e) {
                return $e->getMessage();
            }

            return 'no exception';
        });
        $message = phasync::await($writer);

        return [$message, $r->read(), tmoTimed(static fn () => $r->read(0.05))[0]];
    });

    expect($result)->toBe(['Channel write operation timed out', 'first', TimeoutException::class]);
});

// ---------------------------------------------------------------------------
// TMO-3: never early, may be late
// ---------------------------------------------------------------------------

test('TMO-3: a timeout never fires before its deadline (awaitFlag, await, readable, channel read)', function () {
    $elapsed = phasync::run(static function () {
        [$a, $b] = tmoPair();
        phasync::channel($r, $w);
        $target = phasync::go(static fn () => phasync::sleep(0.8));
        $holder = phasync::go(static function () use ($w) {
            phasync::sleep(0.8);
        });

        // Measured concurrently so that the test takes one idle wake-up, not four.
        $operations = [
            'awaitFlag'    => static fn () => heldFlagWait(0.12),
            'await'        => static fn () => phasync::await($target, 0.12),
            'readable'     => static fn () => phasync::readable($a, 0.12),
            'channel read' => static fn () => $r->read(0.12),
        ];
        $measured = [];
        foreach ($operations as $name => $operation) {
            $measured[$name] = phasync::go(static fn () => tmoTimed($operation));
        }

        return \array_map(static fn ($fiber) => phasync::await($fiber), $measured);
    });

    foreach ($elapsed as $operation => [$outcome, $seconds]) {
        expect($outcome)->toBe(TimeoutException::class, $operation);
        expect($seconds)->toBeGreaterThanOrEqual(0.12);
    }
});

test('TMO-3: on an idle loop a short timeout fires only at the loop\'s next 0.5 s wake-up (decision D11)', function () {
    [$outcome, $seconds] = phasync::run(static fn () => tmoTimed(static fn () => heldFlagWait(0.05)));

    expect($outcome)->toBe(TimeoutException::class);
    expect($seconds)->toBeGreaterThanOrEqual(0.4);
    expect($seconds)->toBeLessThan(1.5);
});

test('TMO-3: a zero or negative timeout does not fire immediately, only at the next loop check', function () {
    $results = phasync::run(static fn () => [
        'zero'     => tmoTimed(static fn () => heldFlagWait(0)),
        'negative' => tmoTimed(static fn () => heldFlagWait(-1)),
    ]);

    foreach ($results as $name => [$outcome, $seconds]) {
        expect($outcome)->toBe(TimeoutException::class, $name);
        expect($seconds)->toBeGreaterThanOrEqual(0.4);
        expect($seconds)->toBeLessThan(1.5);
    }
})->group('surprise');

test('TMO-3: a timer-driven sibling (sleep loop) wakes the loop often enough that a 0.2 s timeout fires within 0.2 s of its deadline', function () {
    [$outcome, $seconds] = phasync::run(static function () {
        $stop = false;
        phasync::go(static function () use (&$stop) {
            while (!$stop) {
                phasync::sleep(0.05);
            }
        });
        $result = tmoTimed(static fn () => heldFlagWait(0.2));
        $stop   = true;

        return $result;
    });

    expect($outcome)->toBe(TimeoutException::class);
    expect($seconds)->toBeGreaterThanOrEqual(0.2);
    expect($seconds)->toBeLessThan(0.45);
});

test('TMO-3: a yield-looping sibling makes a 0.2 s timeout fire at the next 0.1 s check', function () {
    [$outcome, $seconds] = phasync::run(static function () {
        $stop = false;
        phasync::go(static function () use (&$stop) {
            while (!$stop) {
                phasync::yield();
            }
        });
        $result = tmoTimed(static fn () => heldFlagWait(0.2));
        $stop   = true;

        return $result;
    });

    expect($outcome)->toBe(TimeoutException::class);
    expect($seconds)->toBeGreaterThanOrEqual(0.2);
    expect($seconds)->toBeLessThan(0.6);
});

test('TMO-3: a sibling waiting on a quiet stream does not shorten the idle wait', function () {
    [$outcome, $seconds] = phasync::run(static function () {
        [$a, $b]  = tmoPair();
        $sibling  = phasync::go(static function () use ($a) {
            try {
                phasync::readable($a, 2);
            } catch (Throwable) {
            }
        });
        $result = tmoTimed(static fn () => heldFlagWait(0.2));
        phasync::cancel($sibling);
        try {
            phasync::await($sibling);
        } catch (Throwable) {
        }

        return $result;
    });

    expect($outcome)->toBe(TimeoutException::class);
    expect($seconds)->toBeGreaterThanOrEqual(0.4);
    expect($seconds)->toBeLessThan(1.5);
});

test('TMO-3: sleep() is exact where timeouts are not (it is scheduled, not scanned)', function () {
    [$outcome, $seconds] = phasync::run(static fn () => tmoTimed(static fn () => phasync::sleep(0.15)));

    expect($outcome)->toBe('ok');
    expect($seconds)->toBeGreaterThanOrEqual(0.15);
    expect($seconds)->toBeLessThan(0.3);
});

// The next two tests replaced tests that pinned checkTimeouts() skipping about half of the expired
// waiters per check (it cancelled fibers while iterating over the SplObjectStorage). Approved by
// the maintainer: all expired timeouts are now delivered in the same check, in registration order.
test('TMO-6: several simultaneous timeouts are all delivered by the same check', function () {
    // Eight coroutines with the same 0.2 s deadline.
    $times = phasync::run(static function () {
        $stop = false;
        phasync::go(static function () use (&$stop) {
            while (!$stop) {
                phasync::yield();
            }
        });
        $start   = \microtime(true);
        $fired   = [];
        $waiters = [];
        for ($i = 0; $i < 8; ++$i) {
            $waiters[] = phasync::go(static function () use ($i, $start, &$fired) {
                try {
                    heldFlagWait(0.2);
                } catch (TimeoutException) {
                    $fired[$i] = \microtime(true) - $start;
                }
            });
        }
        foreach ($waiters as $waiter) {
            phasync::await($waiter);
        }
        $stop = true;

        return $fired;
    });

    expect($times)->toHaveCount(8);
    expect(\min($times))->toBeGreaterThanOrEqual(0.2);
    expect(\max($times) - \min($times))->toBeLessThan(0.05);
    expect(\max($times))->toBeLessThan(1.0);
});

test('TMO-6: equal timeouts are delivered in registration order', function () {
    $order = phasync::run(static function () {
        $order   = [];
        $waiters = [];
        for ($i = 0; $i < 4; ++$i) {
            $waiters[] = phasync::go(static function () use ($i, &$order) {
                try {
                    heldFlagWait(0.2);
                } catch (TimeoutException) {
                    $order[] = $i;
                }
            });
        }
        foreach ($waiters as $waiter) {
            phasync::await($waiter);
        }

        return $order;
    });

    expect($order)->toBe([0, 1, 2, 3]);
});

test('TMO-3: timeouts of different length fire shortest first when they expire on different checks', function () {
    $order = phasync::run(static function () {
        $order   = [];
        $waiters = [];
        foreach ([0.1, 0.8] as $timeout) {
            $waiters[] = phasync::go(static function () use ($timeout, &$order) {
                try {
                    heldFlagWait($timeout);
                } catch (TimeoutException) {
                    $order[] = $timeout;
                }
            });
        }
        foreach ($waiters as $waiter) {
            phasync::await($waiter);
        }

        return $order;
    });

    expect($order)->toBe([0.1, 0.8]);
});

// ---------------------------------------------------------------------------
// Parity: the same calls outside a coroutine
// ---------------------------------------------------------------------------

test('TMO-1: sleep() outside a coroutine blocks the process for the full duration', function () {
    [$outcome, $seconds] = tmoTimed(static fn () => phasync::sleep(0.1));

    expect($outcome)->toBe('ok');
    expect($seconds)->toBeGreaterThanOrEqual(0.1);
    expect($seconds)->toBeLessThan(0.5);
});

test('TMO-1: yield(), idle(), preempt() and sleep(0) outside a coroutine return immediately', function () {
    $start = \microtime(true);
    phasync::yield();
    phasync::idle();
    phasync::idle(1);
    phasync::preempt();
    phasync::sleep(0);

    expect(\microtime(true) - $start)->toBeLessThan(0.1);
});

test('TMO-1: awaitFlag() outside a coroutine throws LogicException', function () {
    expect(static fn () => heldFlagWait(0.1))
        ->toThrow(LogicException::class, 'Can only await flags from within a coroutine');
});

test('TMO-1: readable() and writable() outside a coroutine return the resource at once, even with no data [SURPRISE]', function () {
    [$a, $b] = tmoPair();
    $start   = \microtime(true);

    expect(phasync::readable($a, 0.5))->toBe($a);
    expect(phasync::writable($a, 0.5))->toBe($a);
    expect(\microtime(true) - $start)->toBeLessThan(0.3);
})->group('surprise');

test('TMO-1: idle() inside a coroutine resumes normally on timeout without throwing', function () {
    [$outcome, $seconds] = phasync::run(static fn () => tmoTimed(static fn () => phasync::idle(0.05)));

    expect($outcome)->toBe('ok');
    expect($seconds)->toBeLessThan(1.5);
});
