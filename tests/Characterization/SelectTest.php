<?php

/*
 * Characterization tests for phasync::select() (docs/SEMANTICS.md section 8, SEL-1 .. SEL-8).
 *
 * These pin how select() behaves TODAY. A failing test means "stop and tell the
 * maintainer" (see tests/Characterization/README.md).
 */

use phasync\Internal\ClosureSelector;
use phasync\SelectableInterface;
use phasync\TimeoutException;
use phasync\Util\StringBuffer;

uses()->group('characterization');

function selchar_state(): array
{
    return (new ReflectionMethod('phasync', 'getDriver'))->invoke(null)->getFullState();
}

/**
 * @return array{0: resource, 1: resource}
 */
function selchar_pair(): array
{
    return \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
}

/**
 * The driver counts of things a blocked select() would leave behind if it did not clean up.
 */
function selchar_waiters(): array
{
    $state = selchar_state();

    return [
        'pending'       => $state['pending'],
        'flaggedFibers' => $state['flaggedFibers'],
        'streams'       => $state['streams'],
        'scheduler'     => $state['scheduler'],
    ];
}

/**
 * The driver runs the cycle collector on its own schedule (after a fiber terminates,
 * at most every 0.5 s). Tests that count lingering fibers switch that off so that the
 * counts do not depend on timing. Returns a closure that restores the driver.
 */
function selchar_freeze_driver_gc(): Closure
{
    $driver   = (new ReflectionMethod('phasync', 'getDriver'))->invoke(null);
    $property = new ReflectionProperty($driver, 'lastGarbageCollect');
    $previous = $property->getValue($driver);
    $property->setValue($driver, \PHP_FLOAT_MAX);

    return static fn () => $property->setValue($driver, $previous);
}

/* ------------------------------------------------------------------ SEL-1 */

test('SEL-1: select() returns the ready selectable, by identity, for every kind of selectable', function () {
    phasync::run(function () {
        $fiber = phasync::go(fn () => 1);
        expect(phasync::select([$fiber]))->toBe($fiber);

        phasync::channel($read, $write, 1);
        $write->write('x');
        expect(phasync::select([$read]))->toBe($read);

        $buffer = new StringBuffer();
        $buffer->write('x');
        expect(phasync::select([$buffer]))->toBe($buffer);

        $closure = function () use ($read) {
            return $read;
        };
        expect(phasync::select([$closure]))->toBe($closure);

        [$a, $b] = selchar_pair();
        \fwrite($b, 'x');
        expect(phasync::select([], 1.0, [$a]))->toBe($a);
    });
});

test('SEL-1: select() returns null when nothing is ready before the timeout', function () {
    phasync::run(function () {
        phasync::channel($read, $write, 1);
        $start = \microtime(true);
        expect(phasync::select([$read], 0.1))->toBeNull();
        expect(\microtime(true) - $start)->toBeGreaterThanOrEqual(0.1);
    });
});

test('SEL-1: select() returns a selectable that becomes ready while waiting', function () {
    phasync::run(function () {
        phasync::channel($read, $write, 1);
        $slow = phasync::go(function () {
            phasync::sleep(0.3);
        });
        phasync::go(function () use ($write) {
            phasync::sleep(0.05);
            $write->write('later');
        });
        $start = \microtime(true);
        expect(phasync::select([$slow, $read]))->toBe($read);
        expect(\microtime(true) - $start)->toBeLessThan(0.25);
    });
});

test('SEL-1: an idle loop delivers a 10 ms select() timeout only at the 0.5 s idle boundary', function () {
    // Same cause as TMO-3: with nothing runnable the driver sleeps 0.5 s before it checks timeouts.
    phasync::run(function () {
        phasync::channel($read, $write, 1);
        $start = \microtime(true);
        expect(phasync::select([$read], 0.01))->toBeNull();
        expect(\microtime(true) - $start)->toBeGreaterThan(0.3)->toBeLessThan(1.2);
    });
})->group('surprise');

test('SEL-1: select() with timeout 0 returns null when nothing is ready, but not necessarily at once', function () {
    // Today the latency depends on when the driver last checked timeouts (0 to about 0.5 s).
    phasync::run(function () {
        phasync::channel($read, $write, 1);
        $start = \microtime(true);
        expect(phasync::select([$read], 0))->toBeNull();
        expect(\microtime(true) - $start)->toBeLessThan(1.0);
    });
})->group('surprise');

test('SEL-1: select() with timeout 0 returns an already ready selectable', function () {
    phasync::run(function () {
        phasync::channel($read, $write, 1);
        $write->write('x');
        expect(phasync::select([$read], 0))->toBe($read);
    });
});

test('SEL-1: select() outside phasync::run() throws LogicException', function () {
    expect(fn () => phasync::select([]))->toThrow(LogicException::class, 'phasync::run()');
});

test('SEL-1: select() rejects things that are not selectable with InvalidArgumentException', function () {
    phasync::run(function () {
        expect(fn () => phasync::select([new stdClass()]))
            ->toThrow(InvalidArgumentException::class, 'Unsupported selectable stdClass');
        expect(fn () => phasync::select(['a string']))
            ->toThrow(InvalidArgumentException::class, 'Unsupported selectable string');
    });
});

test('SEL-1: a closure is selectable only when its first captured variable is selectable', function () {
    phasync::run(function () {
        expect(fn () => phasync::select([function () {
        }]))->toThrow(InvalidArgumentException::class, 'Closures can only be used for select');

        $number = 5;
        expect(fn () => phasync::select([function () use ($number) {
        }]))->toThrow(InvalidArgumentException::class, 'must be a selectable');
    });
});

test('SEL-6: a pooled ClosureSelector that last wrapped a fiber turns the InvalidArgumentException for an unusable closure into an Error [SURPRISE]', function () {
    // returnToPool() does not reset returnOtherSelectableToPool, so when create() rejects the
    // next closure, its cleanup calls returnToPool() on the null otherSelectable. This is why
    // the neighbouring SEL-1 closure tests only see InvalidArgumentException from a clean pool
    // (tests/Pest.php empties the selector pools before every characterization test).
    $message = phasync::run(function () {
        $fiber = phasync::go(function () {
            phasync::sleep(0.05);
        });
        ClosureSelector::create(function () use ($fiber) {
            return $fiber;
        })->returnToPool();

        try {
            phasync::select([function () {
            }]);
        } catch (Throwable $e) {
            $message = \get_class($e) . ': ' . $e->getMessage();
        }
        phasync::await($fiber);

        return $message ?? 'no exception';
    });

    expect($message)->toBe('Error: Call to a member function returnToPool() on null');
})->group('surprise');

test('SEL-1: a closure that captures a terminated fiber is rejected, unlike the bare terminated fiber', function () {
    phasync::run(function () {
        $done = phasync::go(fn () => 1);
        expect(phasync::select([$done]))->toBe($done);
        expect(fn () => phasync::select([function () use ($done) {
            return $done;
        }]))->toThrow(InvalidArgumentException::class, 'Fiber is already terminated');
    });
})->group('surprise');

test('SEL-1: a closure that captures a running fiber is selected as the closure', function () {
    phasync::run(function () {
        $slow = phasync::go(function () {
            phasync::sleep(0.2);
        });
        $fast = phasync::go(function () {
            phasync::sleep(0.02);
        });
        $slowClosure = function () use ($slow) {
            return $slow;
        };
        $fastClosure = function () use ($fast) {
            return $fast;
        };
        expect(phasync::select([$slowClosure, $fastClosure]))->toBe($fastClosure);
    });
});

test('SEL-1: any SelectableInterface implementation can be selected', function () {
    phasync::run(function () {
        $custom = new class implements SelectableInterface {
            public bool $ready = false;

            public function isReady(): bool
            {
                return $this->ready;
            }

            public function await(float $timeout = \PHP_FLOAT_MAX): void
            {
                phasync::awaitFlag($this, $timeout);
            }
        };
        phasync::go(function () use ($custom) {
            phasync::sleep(0.02);
            $custom->ready = true;
            phasync::raiseFlag($custom);
        });
        expect(phasync::select([$custom]))->toBe($custom);
    });
});

test('SEL-1: a StringBuffer is selectable when it has data, and when it has ended empty', function () {
    phasync::run(function () {
        $buffer = new StringBuffer();
        phasync::go(function () use ($buffer) {
            phasync::sleep(0.03);
            $buffer->write('abc');
        });
        expect(phasync::select([$buffer]))->toBe($buffer);
        expect($buffer->isReady())->toBeTrue();

        $ended = new StringBuffer();
        $ended->end();
        expect(phasync::select([$ended], 0))->toBe($ended);
    });
});

test('SEL-1: select() can be nested in a coroutine that another select() waits for', function () {
    phasync::run(function () {
        $inner = phasync::go(function () {
            $work = phasync::go(function () {
                phasync::sleep(0.03);

                return 'work';
            });

            return phasync::select([$work]) === $work;
        });
        expect(phasync::select([$inner]))->toBe($inner);
        expect(phasync::await($inner))->toBeTrue();
    });
});

/* ------------------------------------------------------------------ SEL-2 */

test('SEL-2: a selectable nobody else consumes is still ready when select() returns', function () {
    phasync::run(function () {
        phasync::channel($read, $write, 1);
        phasync::go(function () use ($write) {
            phasync::sleep(0.02);
            $write->write('x');
        });
        expect(phasync::select([$read]))->toBe($read);
        expect($read->isReady())->toBeTrue();
        expect($read->read(0.1))->toBe('x');
    });
});

test('SEL-2: two coroutines selecting on one channel both return it, but only one can read the value [DIVERGENCE]', function () {
    // The contract (SEL-2) says a selectable returned by select() is ready when select() returns
    // and a read cannot block. Today both selectors are woken by the same write, both see
    // isReady() === true, and the second read finds the value gone and times out.
    phasync::run(function () {
        phasync::channel($read, $write, 1);
        $log      = [];
        $selector = function (string $name) use ($read, &$log) {
            return phasync::go(function () use ($name, $read, &$log) {
                $selected                    = phasync::select([$read]);
                $log[$name]['selected']      = $selected === $read;
                $log[$name]['readyAtReturn'] = $read->isReady();
                try {
                    $log[$name]['read'] = $read->read(0.2);
                } catch (Throwable $e) {
                    $log[$name]['read'] = \get_class($e);
                }
            });
        };
        $a = $selector('A');
        $b = $selector('B');
        phasync::sleep(0.05);
        $write->write('one');
        phasync::await($a);
        phasync::await($b);

        expect($log['A'])->toBe(['selected' => true, 'readyAtReturn' => true, 'read' => 'one']);
        expect($log['B'])->toBe(['selected' => true, 'readyAtReturn' => true, 'read' => TimeoutException::class]);
    });
})->group('divergence');

/* ------------------------------------------------------------------ SEL-3 */

test('SEL-3: when several selectables are already ready, the first in argument order is returned', function () {
    phasync::run(function () {
        $a = phasync::go(fn () => 1);
        $b = phasync::go(fn () => 2);
        expect(phasync::select([$a, $b]))->toBe($a);
        expect(phasync::select([$b, $a]))->toBe($b);

        phasync::channel($read1, $write1, 1);
        phasync::channel($read2, $write2, 1);
        $write1->write('x');
        $write2->write('y');
        expect(phasync::select([$read1, $read2]))->toBe($read1);
        expect(phasync::select([$read2, $read1]))->toBe($read2);

        $buffer1 = new StringBuffer();
        $buffer2 = new StringBuffer();
        $buffer1->write('x');
        $buffer2->write('y');
        expect(phasync::select([$buffer2, $buffer1]))->toBe($buffer2);
    });
});

test('SEL-3: a selectable that becomes ready first wins over an earlier argument that is still waiting', function () {
    phasync::run(function () {
        $slow = phasync::go(function () {
            phasync::sleep(0.2);
        });
        $fast = phasync::go(function () {
            phasync::sleep(0.02);
        });
        expect(phasync::select([$slow, $fast]))->toBe($fast);
    });
});

test('SEL-3: listing the same selectable twice returns it', function () {
    phasync::run(function () {
        $fiber = phasync::go(function () {
            phasync::sleep(0.02);
        });
        expect(phasync::select([$fiber, $fiber]))->toBe($fiber);
    });
});

/* ------------------------------------------------------------------ SEL-4 */

test('SEL-4: a fiber that failed is selected, select() does not throw, and await() rethrows', function () {
    phasync::run(function () {
        $failing = phasync::go(function () {
            phasync::sleep(0.01);
            throw new RuntimeException('boom');
        });
        $selected = phasync::select([$failing]);
        expect($selected)->toBe($failing);
        expect(fn () => phasync::await($selected))->toThrow(RuntimeException::class, 'boom');
    });
});

/* ------------------------------------------------------------------ SEL-5 */

test('SEL-5: $read resources are selected when readable, and null is returned on timeout', function () {
    phasync::run(function () {
        [$a, $b] = selchar_pair();
        expect(phasync::select([], 0.1, [$a]))->toBeNull();
        \fwrite($b, 'hi');
        expect(phasync::select([], 1.0, [$a]))->toBe($a);
    });
});

test('SEL-5: a resource and a selectable can be selected in one call', function () {
    phasync::run(function () {
        [$a, $b] = selchar_pair();
        $slow    = phasync::go(function () {
            phasync::sleep(0.15);
        });
        \fwrite($b, 'x');
        expect(phasync::select([$slow], 1.0, [$a]))->toBe($a);
    });
});

test('SEL-5: with both $read and $write given, the readable resource is returned', function () {
    phasync::run(function () {
        [$readable, $peer]  = selchar_pair();
        [$writable, $peer2] = selchar_pair();
        \fwrite($peer, 'x');
        expect(phasync::select([], 0.3, [$readable], [$writable]))->toBe($readable);
    });
});

test('SEL-5: $write on a socket that is writable but not readable returns null after the full timeout [DIVERGENCE]', function () {
    // The contract says an already writable resource is selected at once. Today the $write branch
    // waits for readability (phasync.php calls readable() instead of writable()).
    phasync::run(function () {
        [$socket, $peer] = selchar_pair();
        $start           = \microtime(true);
        expect(phasync::select([], 0.2, null, [$socket]))->toBeNull();
        expect(\microtime(true) - $start)->toBeGreaterThanOrEqual(0.2);
    });
})->group('divergence');

test('SEL-5: $write on a socket that is both writable and readable returns it at once (it waits for readability) [DIVERGENCE]', function () {
    phasync::run(function () {
        [$socket, $peer] = selchar_pair();
        \fwrite($peer, 'x');
        $start = \microtime(true);
        expect(phasync::select([], 1.0, null, [$socket]))->toBe($socket);
        expect(\microtime(true) - $start)->toBeLessThan(0.4);
    });
})->group('divergence');

/* ------------------------------------------------------------------ SEL-6 */

test('SEL-6: select() on ready selectables leaves nothing in the driver', function () {
    phasync::run(function () {
        phasync::channel($read, $write, 1);
        $write->write('x');
        $before = selchar_state();
        for ($i = 0; $i < 10; ++$i) {
            expect(phasync::select([$read]))->toBe($read);
        }
        $after = selchar_state();
        expect($after['contexts'])->toBe($before['contexts']);
        expect(selchar_waiters())->toBe([
            'pending'       => $before['pending'],
            'flaggedFibers' => $before['flaggedFibers'],
            'streams'       => $before['streams'],
            'scheduler'     => $before['scheduler'],
        ]);
    });
});

test('SEL-6: helper coroutines of a select() that timed out stay in the driver until the cycle collector runs [DIVERGENCE]', function () {
    // The contract says no helper outlives the call. Today the discarded helpers are removed
    // from every wait queue, but the suspended fibers remain registered until they are garbage collected.
    $restore = selchar_freeze_driver_gc();
    try {
        phasync::run(function () {
            phasync::channel($read, $write, 1);
            \gc_collect_cycles();
            $before = selchar_state();
            for ($i = 0; $i < 2; ++$i) {
                expect(phasync::select([$read], 0))->toBeNull();
            }
            $during = selchar_state();
            expect($during['contexts'])->toBeGreaterThan($before['contexts']);
            expect($during['pending'])->toBe($before['pending']);
            expect($during['flaggedFibers'])->toBe($before['flaggedFibers']);
            expect($during['streams'])->toBe($before['streams']);

            \gc_collect_cycles();
            expect(selchar_state()['contexts'])->toBe($before['contexts']);
        });
    } finally {
        $restore();
    }
})->group('divergence');

test('SEL-6: the losing helpers of a select() that had a winner also stay until the cycle collector runs [DIVERGENCE]', function () {
    $restore = selchar_freeze_driver_gc();
    try {
        phasync::run(function () {
            phasync::channel($ready, $readyWrite, 1);
            phasync::channel($never, $neverWrite, 1);
            $readyWrite->write('x');
            \gc_collect_cycles();
            $before = selchar_state();
            for ($i = 0; $i < 3; ++$i) {
                expect(phasync::select([$ready, $never]))->toBe($ready);
            }
            expect(selchar_state()['contexts'])->toBeGreaterThan($before['contexts']);
            \gc_collect_cycles();
            expect(selchar_state()['contexts'])->toBe($before['contexts']);
        });
    } finally {
        $restore();
    }
})->group('divergence');

test('SEL-6: a select() that throws for an unsupported selectable removes the waiters it already started', function () {
    phasync::run(function () {
        phasync::channel($read, $write, 1);
        $before = selchar_waiters();
        expect(fn () => phasync::select([$read, new stdClass()]))->toThrow(InvalidArgumentException::class);
        expect(selchar_waiters())->toBe($before);
    });
});

test('SEL-6: of two ready closures only the winning ClosureSelector goes back to the pool [DIVERGENCE]', function () {
    // Two selectors are taken from the pool and one is returned, so the pool shrinks by one.
    // The loser is never returned and is left to the garbage collector.
    $pool = new ReflectionProperty(ClosureSelector::class, 'instanceCount');
    phasync::run(function () use ($pool) {
        $first  = new StringBuffer();
        $second = new StringBuffer();
        $first->write('x');
        $second->write('y');

        $primed = [];
        for ($i = 0; $i < 4; ++$i) {
            $primed[] = ClosureSelector::create(function () use ($first) {
                return $first;
            });
        }
        foreach ($primed as $selector) {
            $selector->returnToPool();
        }

        $firstClosure = function () use ($first) {
            return $first;
        };
        $secondClosure = function () use ($second) {
            return $second;
        };
        $before = $pool->getValue();
        expect(phasync::select([$firstClosure, $secondClosure]))->toBe($firstClosure);
        expect($pool->getValue() - $before)->toBe(-1);
    });
})->group('divergence');

/* ------------------------------------------------------------------ SEL-7 */

test('SEL-7: cancelling a coroutine blocked in select() throws CancelledException from select() and leaves no waiters', function () {
    phasync::run(function () {
        phasync::channel($read, $write, 1);
        $before    = selchar_waiters();
        $caught    = null;
        $coroutine = phasync::go(function () use ($read, &$caught) {
            try {
                phasync::select([$read]);
            } catch (Throwable $e) {
                $caught = \get_class($e);
            }
        });
        phasync::sleep(0.02);
        expect(selchar_state()['pending'])->toBeGreaterThan($before['pending']);
        phasync::cancel($coroutine);
        phasync::await($coroutine);
        expect($caught)->toBe(phasync\CancelledException::class);
        expect(selchar_waiters())->toBe($before);
    });
});

test('SEL-7: cancelling a coroutine that selects on a resource removes the stream waiter', function () {
    phasync::run(function () {
        [$socket, $peer] = selchar_pair();
        $before          = selchar_waiters();
        $caught          = null;
        $coroutine       = phasync::go(function () use ($socket, &$caught) {
            try {
                phasync::select([], 5.0, [$socket]);
            } catch (Throwable $e) {
                $caught = \get_class($e);
            }
        });
        phasync::sleep(0.02);
        expect(selchar_state()['streams'])->toBe($before['streams'] + 1);
        phasync::cancel($coroutine);
        phasync::await($coroutine);
        expect($caught)->toBe(phasync\CancelledException::class);
        expect(selchar_waiters())->toBe($before);
    });
});

/* ------------------------------------------------------------------ SEL-8 */

test('SEL-8: an empty selection returns null immediately, with or without a timeout', function () {
    phasync::run(function () {
        $start = \microtime(true);
        expect(phasync::select([]))->toBeNull();
        expect(phasync::select([], 5.0))->toBeNull();
        expect(phasync::select([], 5.0, [], []))->toBeNull();
        expect(\microtime(true) - $start)->toBeLessThan(0.2);
    });
});
