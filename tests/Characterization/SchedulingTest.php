<?php

/*
 * Characterization tests for docs/SEMANTICS.md section 2 (SCH-1..5) and for
 * inside/outside-coroutine parity of the core API. These pin how phasync behaves
 * TODAY. See tests/Characterization/README.md before changing any of them.
 */

use phasync\TimeoutException;

uses()->group('characterization');

/*
 * go() calls preempt(), which suspends the caller once the preempt interval has elapsed.
 * That makes the order of anything involving go() depend on wall-clock time, so every
 * test in this file starts with preemption effectively disabled (one hour).
 */
beforeEach(function () {
    phasync::setPreemptInterval(3_600_000_000);
});
afterEach(function () {
    phasync::setPreemptInterval(50_000); // the library default (50 ms)
});

/**
 * Runs coroutine A (a1, $op, a2) and coroutine B (b) side by side and returns the
 * log. `[a1, b, a2]` means $op suspended A so B could run; `[a1, a2, b]` means it did not.
 */
function schOrder(Closure $op): array
{
    $log = [];
    phasync::run(function () use (&$log, $op) {
        phasync::go(function () use (&$log, $op) {
            $log[] = 'a1';
            $op();
            $log[] = 'a2';
        });
        phasync::go(function () use (&$log) {
            $log[] = 'b';
        });
    });

    return $log;
}

/**
 * Catches the TimeoutException that ends a timed wait.
 */
function schTimedOut(Closure $wait): void
{
    try {
        $wait();
    } catch (TimeoutException) {
    }
}

// ---------------------------------------------------------------------------
// SCH-1  Cooperative: a coroutine runs until it reaches a suspension point
// ---------------------------------------------------------------------------

dataset('sch suspension points', [
    'sleep(0.01)'                     => [fn () => phasync::sleep(0.01)],
    'sleep(0)'                        => [fn () => phasync::sleep(0)],
    'yield()'                         => [fn () => phasync::yield()],
    'idle(0.01)'                      => [fn () => phasync::idle(0.01)],
    'awaitFlag() with a timeout'      => [fn () => schTimedOut(fn () => heldFlagWait(0.01))],
    'await() of a sleeping child'     => [function () {
        phasync::await(phasync::go(fn () => phasync::sleep(0.01)));
    }],
    'readable() on a quiet stream'    => [function () {
        $pair = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        schTimedOut(fn () => phasync::readable($pair[0], 0.01));
    }],
    'writable() on a writable stream' => [function () {
        // Even a stream that is writable right now goes through the event loop.
        $pair = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        phasync::writable($pair[0], 0.5);
    }],
    'nested run() that sleeps'        => [fn () => phasync::run(fn () => phasync::sleep(0.01))],
]);

dataset('sch non-suspension points', [
    'plain code'                             => [fn () => null],
    'await() of a finished child'            => [function () {
        phasync::await(phasync::go(fn () => 1));
    }],
    'nested run() that never blocks'         => [fn () => phasync::run(fn () => 1)],
    'preempt() with the default interval'    => [fn () => phasync::preempt()],
]);

// phasync-ext turns usleep() inside a coroutine into phasync::sleep().
test('SCH-1: usleep() is a suspension point only with the phasync extension', function () {
    $expected = \extension_loaded('phasync') ? ['a1', 'b', 'a2'] : ['a1', 'a2', 'b'];
    expect(schOrder(fn () => \usleep(1000)))->toBe($expected);
});

test('SCH-1: suspension point lets another coroutine run', function (Closure $op) {
    expect(schOrder($op))->toBe(['a1', 'b', 'a2']);
})->with('sch suspension points');

test('SCH-1: not a suspension point, so the coroutine keeps running', function (Closure $op) {
    expect(schOrder($op))->toBe(['a1', 'a2', 'b']);
})->with('sch non-suspension points');

test('SCH-1: reading an empty channel is a suspension point', function () {
    $log = [];
    phasync::run(function () use (&$log) {
        phasync::channel($read, $write);
        phasync::go(function () use (&$log, $read) {
            $log[] = 'a1';
            $log[] = 'read ' . $read->read();
            $log[] = 'a2';
        });
        phasync::go(function () use (&$log, $write) {
            $log[] = 'b';
            $write->write('x');
            $write->close();
        });
    });
    expect($log)->toBe(['a1', 'b', 'read x', 'a2']);
});

test('SCH-1: preempt() suspends only once the preempt interval has elapsed', function () {
    // Interval 0: every preempt() call is a suspension point.
    $log = [];
    phasync::run(function () use (&$log) {
        phasync::preempt(); // records the reference time
        phasync::go(function () use (&$log) {
            phasync::setPreemptInterval(0);
            $log[] = 'a1';
            phasync::preempt();
            phasync::setPreemptInterval(3_600_000_000);
            $log[] = 'a2';
        });
        phasync::go(function () use (&$log) {
            $log[] = 'b';
        });
    });
    // a1 runs immediately, preempt() suspends A, go() itself preempts the parent too,
    // then A resumes before B is even created.
    expect($log)->toBe(['a1', 'a2', 'b']);
});

test('SCH-1: a coroutine that never suspends blocks every other coroutine', function () {
    $log = [];
    phasync::run(function () use (&$log) {
        phasync::go(function () use (&$log) {
            for ($i = 0; $i < 3; ++$i) {
                $log[] = "a$i";
            }
        });
        phasync::go(function () use (&$log) {
            $log[] = 'b';
        });
    });
    expect($log)->toBe(['a0', 'a1', 'a2', 'b']);
});

// ---------------------------------------------------------------------------
// SCH-2  go() runs the child immediately, up to its first suspension
// ---------------------------------------------------------------------------

test('SCH-2: go() runs the child up to its first suspension before returning', function () {
    $log = [];
    phasync::run(function () use (&$log) {
        $log[] = 'p1';
        phasync::go(function () use (&$log) {
            $log[] = 'c1';
            phasync::sleep(0);
            $log[] = 'c2';
        });
        $log[] = 'p2';
    });
    expect($log)->toBe(['p1', 'c1', 'p2', 'c2']);
});

test('SCH-2: a child that never suspends is already terminated when go() returns', function () {
    phasync::run(function () {
        $ran   = false;
        $fiber = phasync::go(function () use (&$ran) {
            $ran = true;

            return 'value';
        });
        expect($ran)->toBeTrue();
        expect($fiber)->toBeInstanceOf(Fiber::class);
        expect($fiber->isTerminated())->toBeTrue();
        expect($fiber->getReturn())->toBe('value');
    });
});

test('SCH-2: a child that suspends is not terminated when go() returns', function () {
    phasync::run(function () {
        $fiber = phasync::go(fn () => phasync::sleep(0.01));
        expect($fiber->isTerminated())->toBeFalse();
        expect($fiber->isSuspended())->toBeTrue();
    });
});

test('SCH-2: a child started by go() receives its arguments', function () {
    $result = phasync::run(function () {
        return phasync::await(phasync::go(fn (int $a, int $b) => $a + $b, [2, 3]));
    });
    expect($result)->toBe(5);
});

test('SCH-2: go() with $concurrent > 1 returns one fiber that resolves to an array, exceptions included as values', function () {
    $results = phasync::run(function () {
        $n     = 0;
        $fiber = phasync::go(function () use (&$n) {
            $i = $n++;
            if (1 === $i) {
                throw new RuntimeException('second');
            }

            return $i;
        }, concurrent: 3);
        expect($fiber)->toBeInstanceOf(Fiber::class);

        return phasync::await($fiber);
    });
    expect($results)->toHaveCount(3);
    expect($results[0])->toBe(0);
    expect($results[1])->toBeInstanceOf(RuntimeException::class);
    expect($results[1]->getMessage())->toBe('second');
    expect($results[2])->toBe(2);
});

// ---------------------------------------------------------------------------
// SCH-3  No spurious wake-ups
// ---------------------------------------------------------------------------

test('SCH-3: sleep() never returns before its time, even while siblings keep the loop busy', function () {
    $elapsed = [];
    phasync::run(function () use (&$elapsed) {
        $stop = false;
        phasync::go(function () use (&$stop) {
            while (!$stop) {
                phasync::yield();
            }
        });
        for ($i = 0; $i < 5; ++$i) {
            $t = \microtime(true);
            phasync::sleep(0.01);
            $elapsed[] = \microtime(true) - $t;
        }
        $stop = true;
    });
    foreach ($elapsed as $seconds) {
        // 1 ms of slack for clock read order; the scheduler itself never fires early.
        expect($seconds)->toBeGreaterThanOrEqual(0.009);
    }
});

test('SCH-3: awaitFlag() is not resumed by other flags or by sibling activity', function () {
    $log = [];
    phasync::run(function () use (&$log) {
        $mine  = new stdClass();
        $other = new stdClass();
        phasync::go(function () use (&$log, $mine) {
            phasync::awaitFlag($mine);
            $log[] = 'resumed';
        });
        for ($i = 0; $i < 5; ++$i) {
            phasync::raiseFlag($other);
            phasync::sleep(0);
            phasync::yield();
        }
        $log[] = 'raising mine';
        phasync::raiseFlag($mine);
    });
    expect($log)->toBe(['raising mine', 'resumed']);
});

test('SCH-3: awaitFlag() with a timeout never returns normally when nobody raises the flag', function () {
    $outcome = null;
    phasync::run(function () use (&$outcome) {
        try {
            heldFlagWait(0.02);
            $outcome = 'returned';
        } catch (Throwable $e) {
            $outcome = \get_class($e);
        }
    });
    expect($outcome)->toBe(TimeoutException::class);
});

test('SCH-3: await() returns only after the child has terminated', function () {
    $seen = null;
    phasync::run(function () use (&$seen) {
        $done  = false;
        $child = phasync::go(function () use (&$done) {
            for ($i = 0; $i < 3; ++$i) {
                phasync::sleep(0.005);
            }
            $done = true;
        });
        // A busy sibling makes plenty of opportunities for a spurious resume.
        phasync::go(function () use (&$done) {
            while (!$done) {
                phasync::sleep(0);
            }
        });
        phasync::await($child);
        $seen = $done;
    });
    expect($seen)->toBeTrue();
});

test('SCH-3: readable() resumes only when data has arrived, and returns the resource', function () {
    $log = [];
    phasync::run(function () use (&$log) {
        [$a, $b] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        $reader  = phasync::go(function () use (&$log, $a) {
            $r     = phasync::readable($a, 5);
            $log[] = $r === $a ? 'readable returned the resource' : 'readable returned ' . \var_export($r, true);
            $log[] = 'data=' . \fread($a, 10);
        });
        for ($i = 0; $i < 3; ++$i) {
            phasync::sleep(0.005);
        }
        $log[] = 'writing';
        \fwrite($b, 'hi');
        phasync::await($reader);
    });
    expect($log)->toBe(['writing', 'readable returned the resource', 'data=hi']);
});

test('SCH-3: readable() on a quiet stream ends with TimeoutException, not a normal return', function () {
    $outcome = null;
    phasync::run(function () use (&$outcome) {
        // Keep both ends alive: a closed peer would make $a readable (EOF).
        [$a, $b] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        try {
            phasync::readable($a, 0.02);
            $outcome = 'returned';
        } catch (Throwable $e) {
            $outcome = \get_class($e);
        }
    });
    expect($outcome)->toBe(TimeoutException::class);
});

test('SCH-3: idle() is the exception to "timeouts throw": it resumes normally, after the loop has slept (about 0.5 s)', function () {
    $result  = 'unset';
    $elapsed = null;
    phasync::run(function () use (&$result, &$elapsed) {
        $t       = \microtime(true);
        $result  = phasync::idle(0.05);
        $elapsed = \microtime(true) - $t;
    });
    expect($result)->toBeNull();
    // The idle flag is raised while the loop is about to sleep, but the coroutine only
    // runs after the fixed idle sleep of tick() (see SEMANTICS.md D11).
    expect($elapsed)->toBeGreaterThan(0.3);
    expect($elapsed)->toBeLessThan(2.0);
})->group('surprise');

test('SCH-3: idle() resumes when the loop is about to sleep for a timer, before that timer fires', function () {
    $log = [];
    phasync::run(function () use (&$log) {
        phasync::go(function () use (&$log) {
            phasync::sleep(0.05);
            $log[] = 'timer';
        });
        phasync::idle(0.5);
        $log[] = 'idle returned';
    });
    expect($log)->toBe(['idle returned', 'timer']);
});

// ---------------------------------------------------------------------------
// SCH-4  Order in which coroutines resume (what the order really is today)
// ---------------------------------------------------------------------------

test('SCH-4: coroutines that sleep(0) repeatedly are resumed round-robin in creation order', function () {
    $log = [];
    phasync::run(function () use (&$log) {
        foreach (['a', 'b', 'c'] as $name) {
            phasync::go(function () use (&$log, $name) {
                for ($i = 0; $i < 3; ++$i) {
                    $log[] = "$name$i";
                    phasync::sleep(0);
                }
            });
        }
    });
    expect($log)->toBe(['a0', 'b0', 'c0', 'a1', 'b1', 'c1', 'a2', 'b2', 'c2']);
});

test('SCH-4: coroutines that yield() repeatedly are resumed round-robin in creation order', function () {
    $log = [];
    phasync::run(function () use (&$log) {
        foreach (['a', 'b', 'c'] as $name) {
            phasync::go(function () use (&$log, $name) {
                for ($i = 0; $i < 3; ++$i) {
                    $log[] = "$name$i";
                    phasync::yield();
                }
            });
        }
    });
    expect($log)->toBe(['a0', 'b0', 'c0', 'a1', 'b1', 'c1', 'a2', 'b2', 'c2']);
});

test('SCH-4: yield() resumes after the sleep(0) cohort, and timers come last', function () {
    $log = [];
    phasync::run(function () use (&$log) {
        phasync::go(function () use (&$log) {
            $log[] = 'y1';
            phasync::yield();
            $log[] = 'y2';
        });
        phasync::go(function () use (&$log) {
            $log[] = 's1';
            phasync::sleep(0);
            $log[] = 's2';
        });
        phasync::go(function () use (&$log) {
            $log[] = 't1';
            phasync::sleep(0.02);
            $log[] = 't2';
        });
    });
    expect($log)->toBe(['y1', 's1', 't1', 's2', 'y2', 't2']);
});

test('SCH-4: waiters on one flag resume in the order they started waiting; raiseFlag() returns their number', function () {
    $log = [];
    phasync::run(function () use (&$log) {
        $flag = new stdClass();
        foreach (['a', 'b', 'c'] as $name) {
            phasync::go(function () use (&$log, $name, $flag) {
                phasync::awaitFlag($flag);
                $log[] = $name;
            });
        }
        $log[] = 'raised ' . phasync::raiseFlag($flag);
    });
    // The raiser keeps running until it finishes; the waiters resume afterwards.
    expect($log)->toBe(['raised 3', 'a', 'b', 'c']);
});

test('SCH-4: waiters on one coroutine resume in the order they started waiting', function () {
    $log = [];
    phasync::run(function () use (&$log) {
        $target = phasync::go(function () {
            phasync::sleep(0.01);

            return 1;
        });
        foreach (['a', 'b', 'c'] as $name) {
            phasync::go(function () use (&$log, $name, $target) {
                phasync::await($target);
                $log[] = $name;
            });
        }
    });
    expect($log)->toBe(['a', 'b', 'c']);
});

test('SCH-4: sleepers with equal durations wake in creation order; different durations wake by deadline', function () {
    $equal = [];
    $mixed = [];
    phasync::run(function () use (&$equal, &$mixed) {
        foreach (['a', 'b', 'c'] as $name) {
            phasync::go(function () use (&$equal, $name) {
                phasync::sleep(0.02);
                $equal[] = $name;
            });
        }
        foreach ([['a', 0.09], ['b', 0.03], ['c', 0.06]] as [$name, $seconds]) {
            phasync::go(function () use (&$mixed, $name, $seconds) {
                phasync::sleep($seconds);
                $mixed[] = $name;
            });
        }
    });
    expect($equal)->toBe(['a', 'b', 'c']);
    expect($mixed)->toBe(['b', 'c', 'a']);
});

// ---------------------------------------------------------------------------
// SCH-5  Operations are atomic between suspension points
// ---------------------------------------------------------------------------

test('SCH-5: a read-modify-write that does not suspend is never interleaved', function () {
    $seen = [];
    phasync::run(function () use (&$seen) {
        $shared = 0;
        foreach ([1, 2, 3] as $unused) {
            phasync::go(function () use (&$shared, &$seen) {
                $v      = $shared;
                $shared = $v + 1;
                $seen[] = $shared;
                phasync::sleep(0);
                $v      = $shared;
                $shared = $v + 1;
                $seen[] = $shared;
            });
        }
    });
    expect($seen)->toBe([1, 2, 3, 4, 5, 6]);
});

test('SCH-5: go() suspends its caller once the preempt interval has elapsed, so code around go() can interleave [DIVERGENCE]', function () {
    // Contract: statements without a suspension point are atomic. Today go() calls
    // preempt(), which is a hidden suspension point in the CALLER.
    phasync::setPreemptInterval(0);
    $log = [];
    phasync::run(function () use (&$log) {
        phasync::preempt(); // reference time
        $log[] = 'p1';
        phasync::go(function () use (&$log) {
            $log[] = 'c1';
            phasync::sleep(0);
            $log[] = 'c2';
        });
        $log[] = 'p2';
    });
    // Without the hidden suspension this would be [p1, c1, p2, c2].
    expect($log)->toBe(['p1', 'c1', 'c2', 'p2']);
})->group('divergence');

// ---------------------------------------------------------------------------
// Inside / outside coroutine parity
// ---------------------------------------------------------------------------

test('PARITY: sleep(seconds) outside a coroutine blocks the process for that long', function () {
    $t = \microtime(true);
    phasync::sleep(0.05);
    $elapsed = \microtime(true) - $t;
    expect($elapsed)->toBeGreaterThanOrEqual(0.049);
    expect($elapsed)->toBeLessThan(0.5);
});

test('PARITY: sleep(0), yield(), idle() and preempt() outside a coroutine return immediately', function () {
    $t = \microtime(true);
    phasync::sleep(0);
    phasync::yield();
    $idle = phasync::idle(5);
    phasync::preempt();
    expect($idle)->toBeNull();
    expect(\microtime(true) - $t)->toBeLessThan(0.05);
});

test('PARITY: isRunning() is false outside and true inside a coroutine', function () {
    expect(phasync::isRunning())->toBeFalse();
    expect(phasync::run(fn () => phasync::isRunning()))->toBeTrue();
});

test('PARITY: getFiber() and getContext() outside a coroutine throw LogicException', function () {
    expect(fn () => phasync::getFiber())->toThrow(LogicException::class);
    expect(fn () => phasync::getContext())->toThrow(LogicException::class);
});

test('PARITY: go() outside a coroutine throws LogicException', function () {
    expect(fn () => phasync::go(fn () => 1))->toThrow(LogicException::class);
});

test('PARITY: go($run: true) outside a coroutine runs the child to completion inside run(), but returns a Fiber that was never started [SURPRISE]', function () {
    $ran   = false;
    $fiber = phasync::go(function () use (&$ran) {
        phasync::sleep(0.01);
        $ran = true;

        return 7;
    }, run: true);
    expect($ran)->toBeTrue();
    expect($fiber)->toBeInstanceOf(Fiber::class);
    // The returned Fiber wraps the finished result but is not a started fiber:
    expect($fiber->isTerminated())->toBeFalse();
    expect(fn () => $fiber->getReturn())->toThrow(FiberError::class);
    // ... and phasync itself rejects it.
    expect(fn () => phasync::await($fiber))->toThrow(LogicException::class);
})->group('surprise');

test('PARITY: go($run: true) outside a coroutine throws the child\'s exception from go()', function () {
    expect(fn () => phasync::go(function () {
        throw new RuntimeException('from child');
    }, run: true))->toThrow(RuntimeException::class, 'from child');
});

test('PARITY: await() outside a coroutine returns the result of a terminated phasync coroutine, again and again', function () {
    $fiber = phasync::run(fn () => phasync::go(function () {
        phasync::sleep(0.005);

        return 'done';
    }));
    // run() drained the child, so it is terminated by now.
    expect($fiber->isTerminated())->toBeTrue();
    expect(phasync::await($fiber))->toBe('done');
    expect(phasync::await($fiber))->toBe('done');
});

test('PARITY: await() outside a coroutine rethrows the failure of a terminated phasync coroutine', function () {
    $fiber = null;
    phasync::run(function () use (&$fiber) {
        $fiber = phasync::go(function () {
            throw new RuntimeException('failed');
        });
        try {
            phasync::await($fiber);
        } catch (RuntimeException) {
        }
    });
    expect(fn () => phasync::await($fiber))->toThrow(RuntimeException::class, 'failed');
});

test('PARITY: await() outside a coroutine rejects fibers that phasync did not create, and non-fiber objects', function () {
    $foreign = new Fiber(function () {
        Fiber::suspend();
    });
    $foreign->start();
    expect(fn () => phasync::await($foreign))->toThrow(LogicException::class);
    // A non-fiber object is treated as a promise, which needs a coroutine to wait in.
    expect(fn () => phasync::await(new stdClass()))->toThrow(LogicException::class);
});

test('PARITY: stream() on a blocking stream outside a coroutine returns at once with the requested mode', function () {
    [$a, $b] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
    $t       = \microtime(true);
    expect(phasync::stream($a, phasync::READABLE, 5))->toBe(phasync::READABLE);
    expect(\microtime(true) - $t)->toBeLessThan(0.05);
});

test('PARITY: stream() on a quiet non-blocking stream outside a coroutine throws TimeoutException after about 1 s, whatever the timeout [SURPRISE]', function () {
    // Inside a coroutine the timeout argument is honoured. Outside, the wait loop polls
    // in 1 s steps and its exit condition is inverted (`while ($stopTime < microtime(true))`),
    // so a timeout of 5 s ends after the first 1 s poll. (A timeout shorter than 1 s would
    // loop until data arrives; that case is deliberately not pinned here.)
    [$a, $b] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
    \stream_set_blocking($a, false);
    $t = \microtime(true);
    expect(fn () => phasync::stream($a, phasync::READABLE, 5))->toThrow(TimeoutException::class);
    $elapsed = \microtime(true) - $t;
    expect($elapsed)->toBeGreaterThan(0.9);
    expect($elapsed)->toBeLessThan(2.0);
})->group('surprise');

test('PARITY: a nested run() inside a coroutine keeps sibling coroutines running', function () {
    $log = [];
    phasync::run(function () use (&$log) {
        phasync::go(function () use (&$log) {
            phasync::sleep(0.01);
            $log[] = 'sibling ran during nested run';
        });
        phasync::run(function () use (&$log) {
            phasync::sleep(0.05);
            $log[] = 'nested done';
        });
        $log[] = 'after nested';
    });
    expect($log)->toBe(['sibling ran during nested run', 'nested done', 'after nested']);
});
