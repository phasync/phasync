<?php

/*
 * The event loop's own API: the state it reports, what it refuses, and the bookkeeping of waits
 * that the phasync:: functions never show a user. The loop is @internal, but its methods are
 * public because phasync.php, the pollers and adapters drive it; these tests call them the way
 * those callers do.
 */

use phasync\CancelledException;
use phasync\DeadlockException;
use phasync\TimeoutException;

test('getFullState() counts what the loop holds, and count() is the number of waiting coroutines', function () {
    phasync::run(function () {
        $loop = phasync::getLoop();
        $base = $loop->getFullState();
        expect(\array_keys($base))->toBe(['queue', 'frames', 'pending', 'parentFibers', 'fiberExceptionHolders', 'fiberExceptions', 'scheduler', 'flaggedFibers', 'flagGraph', 'parked']);

        $sleeper = phasync::go(fn () => phasync::sleep(5));
        $state   = $loop->getFullState();
        expect($state['frames'])->toBe($base['frames'] + 1);
        expect($state['pending'])->toBe($base['pending'] + 1);
        expect($state['scheduler'])->toBe($base['scheduler'] + 1);
        expect($loop->count())->toBe($state['pending']);

        phasync::cancel($sleeper);
        expect($loop->getFullState()['scheduler'])->toBe($base['scheduler']);
    });
});

test('enqueue() and enqueueWithException() refuse a coroutine that has ended', function () {
    phasync::run(function () {
        $loop = phasync::getLoop();
        $done = phasync::go(fn () => 1);
        expect($done->isTerminated())->toBeTrue();
        expect(fn () => $loop->enqueue($done))->toThrow(LogicException::class, "Can't enqueue a terminated fiber");
        expect(fn () => $loop->enqueueWithException($done, new RuntimeException('late')))->toThrow(LogicException::class, "Can't enqueue a terminated fiber");
    });
});

test('a coroutine that already waits cannot be given a second wait', function () {
    phasync::run(function () {
        $loop    = phasync::getLoop();
        $flag    = new stdClass();
        $waiting = phasync::go(fn () => phasync::awaitFlag($flag));
        expect(fn () => $loop->afterNext($waiting))->toThrow(LogicException::class, 'already pending when scheduling with afterNext');
        expect(fn () => $loop->whenFlaggedFast(new stdClass(), $waiting))->toThrow(LogicException::class, 'already pending when enqueueing for flag');
        expect(fn () => $loop->whenFlagged(new stdClass(), 1.0, $waiting))->toThrow(LogicException::class, 'already pending when enqueueing for flag');
        expect(fn () => $loop->whenTimeElapsed(1.0, $waiting))->toThrow(LogicException::class, 'already pending in whenTimeElapsed');

        phasync::raiseFlag($flag);
        phasync::await($waiting);
    });
});

test('whenTimeElapsed() with no time to wait resumes the coroutine on the next tick', function () {
    $log = phasync::run(function () {
        $loop = phasync::getLoop();
        $log  = [];
        $co   = phasync::go(function () use ($loop, &$log) {
            $log[] = 'before';
            $loop->whenTimeElapsed(0.0, Fiber::getCurrent());
            Fiber::suspend();
            $log[] = 'after';
        });
        $log[] = 'main';
        phasync::await($co);

        return $log;
    });
    expect($log)->toBe(['before', 'main', 'after']);
});

test('cancel() of a coroutine that has ended is refused by the internal driver, but phasync::cancel() is a no-op (#146, CAN-3)', function () {
    phasync::run(function () {
        $done = phasync::go(fn () => 1);
        expect(fn () => phasync::getLoop()->cancel($done))->toThrow(LogicException::class, "Can't cancel a terminated fiber");
        phasync::cancel($done); // no-op: nothing is left to cancel
    });
});

test('getException() is null for a coroutine that succeeded, and refused for one that still runs', function () {
    phasync::run(function () {
        $loop = phasync::getLoop();
        $done = phasync::go(fn () => 1);
        expect($loop->getException($done))->toBeNull();

        $co = phasync::go(function () use ($loop) {
            expect(fn () => $loop->getException(Fiber::getCurrent()))->toThrow(LogicException::class, "Can't get exception from a running fiber");
        });
        phasync::await($co);
    });
});

test('discard() reports false for a Fiber phasync did not start, and for a coroutine that is not waiting', function () {
    phasync::run(function () {
        $loop = phasync::getLoop();
        expect($loop->discard(new Fiber(fn () => 1)))->toBeFalse();
        expect($loop->discard(Fiber::getCurrent()))->toBeFalse(); // running, so not waiting for anything
    });
});

test('discard() takes a ready coroutine out of the queue and leaves the others queued', function () {
    $log = phasync::run(function () {
        $loop = phasync::getLoop();
        $log  = [];
        $make = function (string $name) use (&$log) {
            return phasync::go(function () use ($name, &$log) {
                phasync::yield();
                $log[] = $name;
            });
        };
        $a = $make('a');
        $b = $make('b');
        $c = $make('c');
        // all three are ready, in the queue
        expect($loop->discard($b))->toBeTrue();
        phasync::await($a);
        phasync::await($c);
        $loop->enqueue($b); // b is alive but forgotten: put it back
        phasync::await($b);

        return $log;
    });
    expect($log)->toBe(['a', 'c', 'b']);
});

test('isCancellation() recognises only what a cancellation issued', function () {
    phasync::run(function () {
        $loop   = phasync::getLoop();
        $caught = null;
        $co     = phasync::go(function () use (&$caught) {
            try {
                phasync::sleep(5);
            } catch (CancelledException $e) {
                $caught = $e;
            }
        });
        phasync::cancel($co);
        phasync::await($co);
        expect($loop->isCancellation($caught))->toBeTrue();
        expect($loop->isCancellation(new RuntimeException('not one', 0, $caught)))->toBeTrue(); // caused by one
        expect($loop->isCancellation(new RuntimeException('not one')))->toBeFalse();
    });
});

test('a park() with a deadline that is already past throws TimeoutException', function () {
    phasync::run(function () {
        $loop = phasync::getLoop();
        expect(fn () => $loop->park($loop->getSlot(), 0.0))->toThrow(TimeoutException::class, 'timeout had run out');
        expect(fn () => $loop->park($loop->getSlot(), -1.0))->toThrow(TimeoutException::class);
    });
});

test('park() in a cancelled coroutine throws the cancellation instead of waiting', function () {
    $result = phasync::run(function () {
        $loop = phasync::getLoop();
        $slot = $loop->getSlot();
        $co   = phasync::go(function () use ($loop, $slot) {
            try {
                phasync::awaitFlag(new stdClass(), 5.0); // waits; cancelled below
            } catch (CancelledException) {
                // the cancellation is sticky: the next wait throws it again
                $loop->park($slot);
            }
        });
        phasync::cancel($co);
        try {
            phasync::await($co);
        } catch (CancelledException $e) {
            return 'cancelled';
        }

        return 'not cancelled';
    });
    expect($result)->toBe('cancelled');
});

// Deadlocks: a stall is exact (nothing can wake a waiting coroutine), and run() throws DeadlockException

/** A flag only a cycle keeps alive: nothing else references it once the waiter has let go. */
function eventLoopCyclicFlag(): stdClass
{
    $flag       = new stdClass();
    $flag->self = $flag;

    return $flag;
}

test('a flag that is only garbage in a cycle wakes its waiter, and that is no deadlock', function () {
    $woken = phasync::run(function () {
        $co = phasync::go(function () {
            try {
                phasync::awaitFlag(eventLoopCyclicFlag());
            } catch (CancelledException $e) {
                return $e->getMessage();
            }
        });

        return phasync::await($co);
    });
    expect($woken)->toBe('The flag no longer exists');
});

test('a deadlock names the coroutine each waiting coroutine waits for', function () {
    $ids = [];
    try {
        phasync::run(function () use (&$ids) {
            $stuck   = phasync::go(fn () => heldFlagWait());
            $waiter  = phasync::go(fn () => phasync::await($stuck));
            $ids     = [\spl_object_id($stuck), \spl_object_id($waiter)];
            phasync::await($waiter);
        });
        $message = 'run() returned';
    } catch (DeadlockException $e) {
        $message = $e->getMessage();
    }
    expect($message)->toStartWith('3 coroutines wait and nothing can wake them:');
    expect($message)->toContain('coroutine #' . $ids[1] . ' ');
    expect($message)->toMatch('/coroutine #' . $ids[1] . '[^\n]*, for coroutine #' . $ids[0] . '(\n|$)/');
});

test('a coroutine that waits again while the deadlock unwinds it fails the run a second time, and run() still throws the deadlock', function () {
    $cleanedUp = false;
    try {
        phasync::run(function () use (&$cleanedUp) {
            $co = phasync::go(function () use (&$cleanedUp) {
                try {
                    heldFlagWait();
                } finally {
                    phasync::shielded(fn () => heldFlagWait()); // cancellation does not reach it: stalls again
                    $cleanedUp = true;
                }
            });
            phasync::await($co);
        });
        $thrown = null;
    } catch (DeadlockException $e) {
        $thrown = $e;
    }
    expect($thrown)->toBeInstanceOf(DeadlockException::class);
    expect($cleanedUp)->toBeFalse(); // the coroutine was dropped in its shielded wait
});

test('a destructor that throws while the deadlock drops the waiting coroutines does not hide the deadlock', function () {
    try {
        phasync::run(function () {
            $co = phasync::go(function () {
                $bomb = new class {
                    public function __destruct()
                    {
                        throw new RuntimeException('destructor failed');
                    }
                };
                phasync::shielded(fn () => heldFlagWait()); // cancellation does not reach it: the coroutine is dropped here
                unset($bomb);
            });
            phasync::await($co);
        });
        $thrown = null;
    } catch (Throwable $e) {
        $thrown = $e;
    }
    expect($thrown)->toBeInstanceOf(DeadlockException::class);
});

// The end of a run()

test('a phasync::finally() callback that throws in a nested run() makes that run() throw', function () {
    $log = [];
    $res = phasync::run(function () use (&$log) {
        try {
            phasync::run(function () {
                phasync::finally(fn () => throw new RuntimeException('cleanup failed'));

                return 'body';
            });
        } catch (RuntimeException $e) {
            $log[] = $e->getMessage();
        }

        return 'outer goes on';
    });
    expect($log)->toBe(['cleanup failed']);
    expect($res)->toBe('outer goes on');
});

test('a service that fails is thrown by the run() it was started in', function () {
    expect(fn () => phasync::run(function () {
        phasync::service(function () {
            phasync::sleep(0.001);

            throw new RuntimeException('service failed');
        });
        phasync::sleep(0.01);
    }))->toThrow(RuntimeException::class, 'service failed');
});

test('a main coroutine that suspends its Fiber behind the loop\'s back is reported when run() ends', function () {
    expect(fn () => phasync::run(function () {
        Fiber::suspend();
    }))->toThrow(LogicException::class, 'The main coroutine of run() did not end');
});

test('a failure whose coroutine is still referenced when run() ends goes to the exception handler of its frame', function () {
    $log  = [];
    $keep = null;
    $res  = phasync::run(function () use (&$log, &$keep) {
        phasync::frame()->setExceptionHandler(function (Throwable $e) use (&$log) {
            $log[] = $e->getMessage();
        });
        $keep = phasync::go(fn () => throw new RuntimeException('lost'));

        return 'ok';
    });
    expect($res)->toBe('ok');
    expect($log)->toBe(['lost']);
    unset($keep);
});

test('a CancelledException that nobody awaited is no failure while PHP shuts down', function () {
    $exiting = new ReflectionProperty(phasync\EventLoop::class, 'exiting');
    try {
        $exiting->setValue(null, true);
        $res = phasync::run(function () {
            phasync::go(fn () => throw new CancelledException('destroyed at shutdown'));

            return 'ok';
        });
    } finally {
        $exiting->setValue(null, false);
    }
    expect($res)->toBe('ok');
});

// Timeouts

test('timeouts that fell due during a long pause of the loop are all delivered', function () {
    $res = phasync::run(function () {
        $loop  = phasync::getLoop();
        $short = phasync::go(function () {
            try {
                heldFlagWait(0.02);
            } catch (TimeoutException) {
                return 'timed out';
            }
        });
        $long = phasync::go(function () {
            try {
                heldFlagWait(5.0);
            } catch (TimeoutException) {
                return 'timed out';
            } catch (CancelledException) {
                return 'cancelled';
            }
        });
        // The loop is away for a long while: its last look at the timeouts is 2 s back
        \usleep(30000);
        $last = new ReflectionProperty($loop, 'lastTimeoutSlot');
        $last->setValue($loop, $last->getValue($loop) - 200);
        $res = ['short' => phasync::await($short)];
        phasync::cancel($long);
        $res['long'] = phasync::await($long);

        return $res;
    });
    expect($res)->toBe(['short' => 'timed out', 'long' => 'cancelled']);
});

// onScopeChange() hooks

test('a hook that throws when a waiting coroutine of another scope resumes throws from run()', function () {
    phasyncResetProcessState();
    $scope = new Scope();
    $enter = 0;
    phasync::onScopeChange(function (Scope $new) use ($scope, &$enter) {
        if ($new === $scope && 2 === ++$enter) { // 1: the coroutine starts, 2: it resumes
            throw new RuntimeException('hook refused the scope');
        }
    });
    try {
        expect(fn () => phasync::run(function () use ($scope) {
            $scope->run(fn () => phasync::go(fn () => phasync::sleep(0.02)));
            phasync::sleep(0.001); // the root's coroutine runs in between: the scope is entered again
            phasync::sleep(0.05);
        }))->toThrow(RuntimeException::class, 'hook refused the scope');
    } finally {
        phasyncResetProcessState();
    }
});

test('park() in an onScopeChange() hook is refused like any other wait', function () {
    phasyncResetProcessState();
    $scope = new Scope();
    phasync::onScopeChange(function (Scope $new) use ($scope) {
        if ($new === $scope) {
            $loop = phasync::getLoop();
            $loop->park($loop->getSlot());
        }
    });
    try {
        expect(fn () => phasync::run(function () use ($scope) {
            $scope->run(fn () => phasync::go(fn () => phasync::sleep(0.001)));
            phasync::sleep(0.01);
        }))->toThrow(LogicException::class, 'hook must not wait');
    } finally {
        phasyncResetProcessState();
    }
});

test('a coroutine that throws when resumed does not stop the rest of that tick\'s queue from running (phasync-ext-src#47)', function () {
    // Both $a and $b are queued for the same tick (raiseFlag() enqueues both at once), and
    // $a's resume() throws straight out of Fiber::resume() inside tick()'s loop -- the exact
    // path a tracing JIT mis-corrupted $fiberCount across (see EventLoop::tick()'s comment
    // at its "Counts down" loop). $b must still run in that same pass.
    $log    = [];
    $thrown = null;
    try {
        phasync::run(function () use (&$log) {
            $flag = new stdClass();
            phasync::go(function () use ($flag, &$log) {
                phasync::awaitFlag($flag);
                $log[] = 'a resumed';
                throw new RuntimeException('a failed');
            });
            $b = phasync::go(function () use ($flag, &$log) {
                phasync::awaitFlag($flag);
                $log[] = 'b resumed';
            });
            phasync::raiseFlag($flag);
            phasync::await($b);
        });
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($log)->toBe(['a resumed', 'b resumed']);
    expect($thrown)->toBeInstanceOf(RuntimeException::class);
    expect($thrown->getMessage())->toBe('a failed');
});

test('a long ready queue does not make a due timer late: the tick stops resuming when the next timer is due', function () {
    $late = phasync::run(function () {
        $late  = null;
        $timer = phasync::go(function () use (&$late) {
            $due = microtime(true) + 0.02;
            phasync::sleep(0.02);
            $late = microtime(true) - $due;
        });
        for ($i = 0; $i < 100; ++$i) {
            phasync::go(function () {
                for ($n = 0; $n < 3; ++$n) {
                    phasync::sleep(0); // queued: the work happens in the loop's ticks
                    for ($end = hrtime(true) + 1_000_000; hrtime(true) < $end;) {
                        // 1 ms of work between waits
                    }
                }
            });
        }
        phasync::await($timer);

        return $late;
    });
    expect($late)->toBeLessThan(0.008); // one resumption late at most, not the 100 queued before it
});

test('the drain is budgeted, not cut short: with a timer due at once, the ready coroutines still each get their turn, in order', function () {
    $order = phasync::run(function () {
        $order = [];
        phasync::go(function () use (&$order) {
            for ($i = 0; $i < 50; ++$i) {
                phasync::sleep(0.0001); // always a timer due: the minimum slice still drains
            }
            $order[] = 'timer';
        });
        $all = [];
        for ($i = 0; $i < 20; ++$i) {
            $all[] = phasync::go(function () use (&$order, $i) {
                for ($n = 0; $n < 3; ++$n) {
                    phasync::sleep(0);
                }
                $order[] = $i;
            });
        }
        foreach ($all as $coroutine) {
            phasync::await($coroutine);
        }

        return $order;
    });
    expect(array_values(array_filter($order, 'is_int')))->toBe(range(0, 19)); // FIFO
});
