<?php

/*
 * What the loop does with a preempted coroutine: ext-phasync throws a ready Suspension out of a
 * coroutine that has run a whole interval in a PHP loop. The coroutine goes to the back of the
 * queue and its scope is frozen: the other coroutines of that scope are held until it resumes,
 * those of other scopes keep running. PreemptionTest.php covers the same with long intervals.
 */

use phasync\CancelledException;

beforeEach(function () {
    if (!\class_exists(phasync\ext\Suspension::class, false)) {
        $this->markTestSkipped('needs ext-phasync');
    }
});

/** Busy in a PHP loop until preempted once: the loop has ticked meanwhile. */
function eventLoopPreempt(): void
{
    $loop  = phasync::getLoop();
    $ticks = new ReflectionProperty($loop, 'ticks');
    $t0    = $ticks->getValue($loop);
    phasync\ext\Suspension::setPreemptInterval(0.001);
    try {
        while ($ticks->getValue($loop) === $t0) {
        }
    } finally {
        phasync\ext\Suspension::setPreemptInterval($loop->preemptInterval);
    }
}

/** Busy in a PHP loop for $seconds, under a 1 ms preempt interval. */
function eventLoopBusy(float $seconds): void
{
    phasync\ext\Suspension::setPreemptInterval(0.001);
    try {
        for ($end = \microtime(true) + $seconds; \microtime(true) < $end;) {
        }
    } finally {
        phasync\ext\Suspension::setPreemptInterval(phasync::getLoop()->preemptInterval);
    }
}

/** Start $fn as a coroutine that runs in $scope, as a server's connection coroutine runs a request. */
function eventLoopGoIn(Scope $scope, Closure $fn): Fiber
{
    return phasync::go(static fn () => $scope->run($fn));
}

test('a preempted coroutine goes behind the ready ones, and resumes in its turn', function () {
    $log = phasync::run(function () {
        $log = [];
        $b   = eventLoopGoIn(new Scope(), function () use (&$log) {
            $log[] = 'B1';
            phasync::sleep();
            $log[] = 'B2';
        });
        $a = eventLoopGoIn(new Scope(), function () use (&$log) {
            $log[] = 'A1';
            eventLoopPreempt();
            $log[] = 'A2';
        });
        $log[] = 'main';
        phasync::await($a);
        phasync::await($b);

        return $log;
    });
    expect($log)->toBe(['B1', 'A1', 'main', 'B2', 'A2']);
});

test('a coroutine preempted in the middle of a tick lets the rest of the tick run', function () {
    $log = phasync::run(function () {
        $log = [];
        $a   = eventLoopGoIn(new Scope(), function () use (&$log) {
            phasync::sleep();
            $log[] = 'A1';
            eventLoopPreempt();
            $log[] = 'A2';
        });
        $b = eventLoopGoIn(new Scope(), function () use (&$log) {
            phasync::sleep();
            $log[] = 'B1';
            phasync::sleep();
            $log[] = 'B2';
        });
        phasync::await($a);
        phasync::await($b);

        return $log;
    });
    expect($log)->toBe(['A1', 'B1', 'A2', 'B2']);
});

test('the coroutines of a preempted coroutine\'s scope are held until it resumes, then run right after it', function () {
    $log = phasync::run(function () {
        $log   = [];
        $scope = new Scope();
        $a     = eventLoopGoIn($scope, function () use (&$log) {
            $log[] = 'A1';
            phasync::sleep();
            $log[] = 'A2';
            eventLoopPreempt();
            $log[] = 'A3';
        });
        $h = eventLoopGoIn($scope, function () use (&$log) {
            $log[] = 'H1';
            phasync::sleep();
            $log[] = 'H2';
        });
        $other = eventLoopGoIn(new Scope(), function () use (&$log) {
            $log[] = 'O1';
            phasync::sleep();
            $log[] = 'O2';
        });
        phasync::await($a);
        phasync::await($h);
        phasync::await($other);

        return $log;
    });
    // H was ready behind A, but A's scope froze: H comes after A's whole resumption; O is unaffected
    expect($log)->toBe(['A1', 'H1', 'O1', 'A2', 'O2', 'A3', 'H2']);
});

test('a coroutine preempted again right after it resumed keeps the held ones waiting', function () {
    $log = phasync::run(function () {
        $log   = [];
        $scope = new Scope();
        $a     = eventLoopGoIn($scope, function () use (&$log) {
            phasync::sleep();
            eventLoopPreempt();
            $log[] = 'A resumed';
            eventLoopPreempt();
            $log[] = 'A resumed again';
        });
        $h = eventLoopGoIn($scope, function () use (&$log) {
            phasync::sleep();
            $log[] = 'H';
        });
        phasync::await($a);
        phasync::await($h);

        return $log;
    });
    expect($log)->toBe(['A resumed', 'A resumed again', 'H']);
});

test('a coroutine that is preempted before its first wait holds its creator, which goes on once the scope thaws', function () {
    $log = phasync::run(function () {
        $log   = [];
        $scope = new Scope();
        $other = eventLoopGoIn(new Scope(), function () use (&$log) {
            phasync::sleep();
            $log[] = 'other';
        });
        $parent = eventLoopGoIn($scope, function () use (&$log) {
            $log[] = 'parent starts';
            phasync::go(function () use (&$log) {
                $log[] = 'child starts';
                eventLoopPreempt();
                $log[] = 'child ends';
            });
            $log[] = 'go() returned';
        });
        $log[] = 'main';
        phasync::await($parent);
        phasync::await($other);

        return $log;
    });
    expect($log)->toBe(['parent starts', 'child starts', 'main', 'other', 'child ends', 'go() returned']);
});

test('a coroutine started in a frozen scope is not preempted again, and holds its creator', function () {
    $log = phasync::run(function () {
        $log   = [];
        $scope = new Scope();
        $a     = eventLoopGoIn($scope, function () use (&$log) {
            $log[] = 'A1';
            eventLoopPreempt();
            $log[] = 'A2';
        });
        // another scope starts a coroutine in the frozen one: it runs at once and finds its scope frozen,
        // so it is not preempted; its creator, working in the frozen scope, waits for it to thaw
        $b = eventLoopGoIn(new Scope(), function () use (&$log, $scope) {
            $scope->run(function () use (&$log) {
                phasync::go(function () use (&$log) {
                    eventLoopBusy(0.005);
                    $log[] = 'D ran through';
                });
            });
            $log[] = 'B ends';
        });
        phasync::await($a);
        phasync::await($b);

        return $log;
    });
    expect($log)->toBe(['A1', 'D ran through', 'A2', 'B ends']);
});

test('a preempted coroutine that is cancelled meets the cancellation at its next wait', function () {
    $log = phasync::run(function () {
        $log = [];
        $a   = eventLoopGoIn(new Scope(), function () use (&$log) {
            eventLoopPreempt();
            $log[] = 'A resumed';
            try {
                phasync::sleep(5);
            } catch (CancelledException $e) {
                $log[] = 'A cancelled';
            }
        });
        eventLoopGoIn(new Scope(), function () use ($a) {
            phasync::cancel($a); // A is not waiting, it is preempted: nothing to interrupt
        });
        phasync::await($a);

        return $log;
    });
    expect($log)->toBe(['A resumed', 'A cancelled']);
});

test('a coroutine held for a frozen scope can be cancelled; it throws when its scope thaws', function () {
    $log = phasync::run(function () {
        $log   = [];
        $scope = new Scope();
        $a     = eventLoopGoIn($scope, function () use (&$log) {
            phasync::sleep();
            $log[] = 'A1';
            eventLoopPreempt();
            $log[] = 'A2';
        });
        $h = eventLoopGoIn($scope, function () use (&$log) {
            try {
                phasync::sleep();
                $log[] = 'H not cancelled';
            } catch (CancelledException) {
                $log[] = 'H cancelled';
            }
        });
        eventLoopGoIn(new Scope(), function () use ($h) {
            phasync::sleep();
            phasync::cancel($h); // H is held back by A's frozen scope
        });
        phasync::await($a);
        phasync::await($h);

        return $log;
    });
    expect($log)->toBe(['A1', 'A2', 'H cancelled']);
});

test('a coroutine cancelled by the one that resumed it in its frozen scope meets the cancellation at its next wait', function () {
    $log = phasync::run(function () {
        $log   = [];
        $scope = new Scope();
        $h     = null;
        $a     = eventLoopGoIn($scope, function () use (&$log, &$h) {
            phasync::sleep();
            eventLoopPreempt();
            $log[] = 'A resumed';
            phasync::cancel($h); // H is held, and about to run: nothing to interrupt
        });
        $h = eventLoopGoIn($scope, function () use (&$log) {
            phasync::sleep();
            $log[] = 'H resumed';
            try {
                phasync::sleep();
            } catch (CancelledException) {
                $log[] = 'H cancelled';
            }
        });
        phasync::await($a);
        phasync::await($h);

        return $log;
    });
    expect($log)->toBe(['A resumed', 'H resumed', 'H cancelled']);
});

test('a coroutine that throws after being preempted fails like any other', function () {
    expect(fn () => phasync::run(function () {
        $a = eventLoopGoIn(new Scope(), function () {
            eventLoopPreempt();

            throw new RuntimeException('after the preemption');
        });
        phasync::await($a);
    }))->toThrow(RuntimeException::class, 'after the preemption');
});

test('a coroutine busy in a plain Fiber of its own is preempted as a whole: the Fiber stays where it is', function () {
    $log = phasync::run(function () {
        $log = [];
        $b   = eventLoopGoIn(new Scope(), function () use (&$log) {
            $log[] = 'B';
        });
        $plain = new Fiber(function () use (&$log) {
            eventLoopBusy(0.005);
            $log[] = 'plain';
        });
        $plain->start();
        $log[] = 'main';
        phasync::await($b);

        return $log;
    });

    expect($log)->toBe(['B', 'plain', 'main']);
});

test('the runtime itself is never preempted', function () {
    $log = phasync::run(function () {
        $log = [];
        $a   = eventLoopGoIn(new Scope(), function () use (&$log) {
            $log[] = 'A1';
            // busy in a frame of a phasync class
            phasync\ext\Suspension::setPreemptInterval(0.001);
            Closure::bind(function () {
                for ($end = \microtime(true) + 0.005; \microtime(true) < $end;) {
                }
            }, null, phasync::class)();
            phasync\ext\Suspension::setPreemptInterval(phasync::getLoop()->preemptInterval);
            $log[] = 'A2';
        });
        $b = eventLoopGoIn(new Scope(), function () use (&$log) {
            $log[] = 'B';
        });
        phasync::await($a);
        phasync::await($b);

        return $log;
    });

    expect($log)->toBe(['A1', 'A2', 'B']);
});

test('a hook that throws while a scope is frozen throws from run()', function () {
    phasyncResetProcessState();
    $hooked = new Scope();
    $enter  = 0;
    phasync::onScopeChange(function (Scope $new) use ($hooked, &$enter) {
        if ($new === $hooked && 1 === ++$enter) { // the loop enters it to resume the coroutine
            throw new RuntimeException('hook refused the scope');
        }
    });
    try {
        expect(fn () => phasync::run(function () use ($hooked) {
            $a = eventLoopGoIn(new Scope(), function () {
                phasync::sleep(0);
                eventLoopPreempt();
                phasync::sleep(0);
            });
            $b = eventLoopGoIn($hooked, fn () => phasync::sleep(0));
            phasync::await($a);
            phasync::await($b);
        }))->toThrow(RuntimeException::class, 'hook refused the scope');
    } finally {
        phasyncResetProcessState();
    }
});

test('a wait in place resumes after the preempted coroutine of its scope has run', function () {
    $log = phasync::run(function () {
        $log   = [];
        $scope = new Scope();
        $p     = eventLoopGoIn($scope, function () use (&$log) {
            phasync::sleep();
            $log[] = 'P1';
            eventLoopPreempt();
            $log[] = 'P2';
        });
        // a plain Fiber inside a coroutine waits in place, running the loop meanwhile
        $h = eventLoopGoIn($scope, function () use (&$log) {
            $plain = new Fiber(function () use (&$log) {
                phasync::sleep();
                $log[] = 'H in place resumed';
            });
            $plain->start();
        });
        phasync::await($p);
        phasync::await($h);

        return $log;
    });

    expect($log)->toBe(['P1', 'P2', 'H in place resumed']);
});
