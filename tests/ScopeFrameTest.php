<?php

/*
 * phasync 2.x: Scope for identity, Frame for lifetime.
 *
 * Scope (fiber-interop/scope: ext-scope or its polyfill) is the ambient identity of the operation
 * a coroutine belongs to. Frame is what run() creates: it waits for its coroutines, rethrows,
 * cancels and carries a deadline.
 */

use phasync\AggregateException;
use phasync\CancelledException;
use phasync\Frame;
use phasync\TimeoutException;

// onScopeChange() hooks stay for the life of the loop: every test gets a loop of its own
beforeEach(fn () => phasyncResetProcessState());
afterEach(fn () => phasyncResetProcessState());

// Scope

test('go() runs the coroutine in the scope current where go() was called', function () {
    $scope = new Scope();
    $seen  = phasync::run(function () use ($scope) {
        $fiber = $scope->run(fn () => phasync::go(function () {
            phasync::sleep(0.01);

            return Scope::getCurrent();
        }));

        return [Scope::getCurrent(), phasync::await($fiber)];
    });
    expect($seen[0])->toBe(Scope::getRootScope());
    expect($seen[1])->toBe($scope);
});

test('a coroutine\'s scope is freed as it ends, also while nothing else runs (#159)', function () {
    $freed = null;
    $probe = new class($freed) {
        public function __construct(private ?float &$freed)
        {
        }

        public function __destruct()
        {
            $this->freed = \microtime(true);
        }
    };
    phasync::run(function () use (&$probe, &$freed) {
        $map   = new WeakMap();
        $start = \microtime(true);
        phasync::go(function () use ($map, &$probe) {
            (new Scope())->run(function () use ($map, &$probe) {
                $map[Scope::getCurrent()] = $probe;
                $probe                    = null; // only the scope holds it now
                phasync::sleep(0.01);
            });
        });
        phasync::sleep(0.3); // nothing else runs meanwhile
        expect($freed)->not->toBeNull();
        expect($freed - $start)->toBeLessThan(0.15);
    });
});

test('a scope switched out while the loop is idle keeps its state for its coroutines (#159)', function () {
    phasync::run(function () {
        $scope = new Scope();
        $a     = phasync::go(fn () => $scope->run(function () {
            $_GET['who'] = 'a';
            phasync::sleep(0.05); // the loop is idle meanwhile

            return $_GET['who'] ?? null;
        }));
        expect(phasync::await($a))->toBe('a');
    });
});

test('the scope survives waits while other scopes run in between', function () {
    $a   = new Scope();
    $b   = new Scope();
    $log = [];
    phasync::run(function () use ($a, $b, &$log) {
        foreach (['a' => $a, 'b' => $b] as $name => $scope) {
            $scope->run(function () use ($name, $scope, &$log) {
                phasync::go(function () use ($name, $scope, &$log) {
                    for ($i = 0; $i < 3; ++$i) {
                        phasync::sleep(0);
                        $log[] = $name . (Scope::getCurrent() === $scope ? '=' : '!');
                    }
                });
            });
        }
    });
    expect($log)->toBe(['a=', 'b=', 'a=', 'b=', 'a=', 'b=']);
});

test('each coroutine sees its own scope\'s superglobals across waits, and run() leaves the caller\'s as they were', function () {
    $_GET = ['who' => 'root'];
    $seen = [];
    phasync::run(function () use (&$seen) {
        foreach (['a', 'b'] as $name) {
            (new Scope())->run(function () use ($name, &$seen) {
                $_GET = ['who' => $name];
                phasync::go(function () use ($name, &$seen) {
                    for ($i = 0; $i < 2; ++$i) {
                        phasync::sleep(0);
                        $seen[]      = $name . '=' . $_GET['who'];
                        $_GET['who'] = $name; // written while live: kept as this scope's
                    }
                });
            });
        }
        phasync::sleep(0.01);
        $seen[] = 'root=' . $_GET['who'];
    });
    expect($seen)->toBe(['a=a', 'b=b', 'a=a', 'b=b', 'root=root']);
    expect($_GET)->toBe(['who' => 'root']);

    // run() called inside a scope: its coroutine is in that scope, and the scope's state is back after
    $scope = new Scope();
    expect($scope->run(function () {
        $_GET = ['who' => 'outer'];
        $who  = phasync::run(function () {
            phasync::go(fn () => (new Scope())->run(function () {
                $_GET = ['who' => 'inner'];
                phasync::sleep(0);
            }));
            phasync::sleep(0.01);

            return [$_GET['who'], Scope::getCurrent()];
        });

        return [...$who, $_GET['who'], Scope::getCurrent()];
    }))->toBe(['outer', $scope, 'outer', $scope]);
    expect($_GET)->toBe(['who' => 'root']);
});

test('a coroutine that suspends inside $scope->run() finds that scope again, and the others do not see it', function () {
    $request = new Scope();
    $log     = [];
    phasync::run(function () use ($request, &$log) {
        phasync::go(function () use ($request, &$log) {
            $request->run(function () use ($request, &$log) {
                phasync::sleep(0.01);
                $log[] = 'inside: ' . (Scope::getCurrent() === $request ? 'request' : 'other');
            });
            $log[] = 'after: ' . (Scope::getCurrent() === Scope::getRootScope() ? 'root' : 'other');
        });
        phasync::go(function () use (&$log) {
            phasync::sleep(0.005);
            $log[] = 'neighbour: ' . (Scope::getCurrent() === Scope::getRootScope() ? 'root' : 'other');
        });
    });
    expect($log)->toBe(['neighbour: root', 'inside: request', 'after: root']);
});

test('a coroutine that leaves $scope->run() and then waits resumes in the outer scope', function () {
    $scope = new Scope();
    $log   = [];
    phasync::run(function () use ($scope, &$log) {
        phasync::go(function () use ($scope, &$log) {
            $scope->run(function () {
                phasync::sleep(0.01);
            });
            $log[] = 'before wait: ' . (Scope::getCurrent() === Scope::getRootScope() ? 'root' : 'other');
            phasync::sleep(0.01);
            $log[] = 'after wait: ' . (Scope::getCurrent() === Scope::getRootScope() ? 'root' : 'other');
        });
        phasync::go(function () use (&$log) {
            phasync::sleep(0.005);
            $log[] = 'neighbour: ' . (Scope::getCurrent() === Scope::getRootScope() ? 'root' : 'other');
        });
    });
    expect($log)->toBe(['neighbour: root', 'before wait: root', 'after wait: root']);
});

test('queued callbacks run in the scope they were queued from', function () {
    $scope = new Scope();
    $seen  = null;
    phasync::run(function () use ($scope, &$seen) {
        $scope->run(function () use (&$seen) {
            phasync::getLoop()->defer(function () use (&$seen) {
                $seen = Scope::getCurrent();
            });
        });
        phasync::sleep(0.01);
    });
    expect($seen)->toBe($scope);
});

test('onScopeChange() fires before a coroutine of another scope resumes, and not between coroutines of one scope', function () {
    $a       = new Scope();
    $b       = new Scope();
    $changes = [];
    $names   = static fn (?Scope $s) => match ($s) {
        $a => 'a', $b => 'b', Scope::getRootScope() => 'root', null => 'null', default => '?',
    };
    phasync::onScopeChange(function (Scope $new, ?Scope $old) use (&$changes, $names) {
        $changes[] = $names($old) . '>' . $names($new);
    });
    phasync::run(function () use ($a, $b) {
        foreach ([$a, $a, $b] as $scope) {
            $scope->run(fn () => phasync::go(function () {
                phasync::sleep(0.01);
            }));
        }
        phasync::sleep(0.02);
    });
    // a and a resume back to back: one change into a (from whatever was live: with ext-scope the
    // loop's own scope is never applied, so b stays live from the coroutine created last); then b;
    // then the root again for the main coroutine
    expect(\array_slice($changes, -3))->toBeIn([['root>a', 'a>b', 'b>root'], ['b>a', 'a>b', 'b>root']]);
    expect(\count(\array_filter($changes, static fn ($c) => 'a>a' === $c || 'b>b' === $c || 'root>root' === $c)))->toBe(0);
});

test('an onScopeChange() hook may not wait', function () {
    $scope = new Scope();
    phasync::onScopeChange(function (Scope $new) use ($scope) {
        if ($new === $scope) {
            phasync::sleep(0.01);
        }
    });
    expect(fn () => phasync::run(function () use ($scope) {
        $scope->run(fn () => phasync::go(fn () => phasync::sleep(0.01)));
        phasync::sleep(0.02);
    }))->toThrow(LogicException::class);
});

test('a hook that throws while a coroutine starts in another scope throws from the call, and leaves no coroutine behind (#106)', function () {
    $thrown = 0;
    phasync::onScopeChange(function (Scope $new) use (&$thrown) {
        if ($new === Scope::getRootScope() && 0 === $thrown++) {
            throw new RuntimeException('hook');
        }
    });
    $caught = null;
    phasync::run(function () use (&$caught) {
        (new Scope())->run(function () use (&$caught) {
            phasync::go(function () use (&$caught) {   // starts in its own scope: the loop switches to it
                try {
                    phasync::service(fn () => null);   // a service runs in the root scope
                } catch (RuntimeException $e) {
                    $caught = $e->getMessage();
                }
            });
        });
    });
    expect($caught)->toBe('hook');
    expect(phasync::getLoop(...))->toThrow(LogicException::class);   // the run is over
    $driver = (new ReflectionMethod(phasync::class, 'getDriver'))->invoke(null);
    expect($driver->countFibers())->toBe(0);
});

test('service() starts a coroutine in the root scope, returns it, and does not hold run() open', function () {
    $scope   = new Scope();
    $started = \microtime(true);
    $seen    = null;
    $service = null;
    phasync::run(function () use ($scope, &$seen, &$service) {
        $service = $scope->run(function () use (&$seen) {
            return phasync::service(function () use (&$seen) {
                $seen = Scope::getCurrent();
                phasync::sleep(10);
            });
        });
        phasync::sleep(0.01);
    });
    expect($service)->toBeInstanceOf(Fiber::class);
    expect($seen)->toBe(Scope::getRootScope());
    expect($service->isTerminated())->toBeTrue();      // cancelled when the outermost run() returned
    expect(\microtime(true) - $started)->toBeLessThan(1.0);
});

test('a service has no frame', function () {
    expect(fn () => phasync::run(function () {
        phasync::await(phasync::service(fn () => phasync::frame()));
    }))->toThrow(LogicException::class);
});

// Frame

test('run() waits for every coroutine, and rethrows what nobody awaited after the others finished', function () {
    $log = [];
    try {
        phasync::run(function () use (&$log) {
            phasync::go(function () {
                phasync::sleep(0.01);
                throw new RuntimeException('never awaited');
            });
            phasync::go(function () use (&$log) {
                phasync::sleep(0.03);
                $log[] = 'sibling finished';
            });
        });
    } catch (RuntimeException $e) {
        $log[] = 'run rethrew: ' . $e->getMessage();
    }
    expect($log)->toBe(['sibling finished', 'run rethrew: never awaited']);
});

test('run() throws an AggregateException when $f threw and a child failed: $f\'s first, after the coroutines finished', function () {
    $log = [];
    try {
        phasync::run(function () use (&$log) {
            phasync::go(function () { throw new RuntimeException('child'); });
            phasync::go(function () use (&$log) {
                phasync::sleep(0.01);
                $log[] = 'sibling finished';
            });
            throw new LogicException('main');
        });
    } catch (AggregateException $e) {
        $log[] = \array_map(static fn (Throwable $x) => \get_class($x) . ': ' . $x->getMessage(), $e->getExceptions());
    }
    expect($log)->toBe(['sibling finished', ['LogicException: main', 'RuntimeException: child']]);
});

test('run() throws an AggregateException of every failure nobody awaited, in the order they happened', function () {
    try {
        phasync::run(function () {
            $late  = phasync::go(function () {
                phasync::sleep(0.02);
                throw new LogicException('late');
            });
            $early = phasync::go(function () {
                phasync::sleep(0.01);
                throw new RuntimeException('early');
            });
            phasync::sleep(0.03);
            unset($late, $early); // the late one is released first
        });
    } catch (AggregateException $e) {
    }
    expect(\array_map(static fn (Throwable $x) => $x->getMessage(), $e->getExceptions()))->toBe(['early', 'late']);
    expect($e->getPrevious()->getMessage())->toBe('early');
    expect($e->getMessage())->toStartWith("2 failures:\n  RuntimeException: early in " . __FILE__ . ':');
    expect($e->getMessage())->toContain("\n  LogicException: late in " . __FILE__ . ':');
    expect($e->contains(LogicException::class))->toBeTrue();
    expect($e->contains(Exception::class))->toBeTrue();
    expect($e->contains(TypeError::class))->toBeFalse();
    expect(\array_map(static fn (Throwable $x) => $x->getMessage(), $e->getExceptions(LogicException::class)))->toBe(['late']);
    expect($e->getExceptions(Exception::class))->toHaveCount(2);
});

test('an awaited failure is not rethrown by run()', function () {
    $result = phasync::run(function () {
        $fiber = phasync::go(function () {
            phasync::sleep(0.01);
            throw new RuntimeException('handled');
        });
        try {
            phasync::await($fiber);
        } catch (RuntimeException) {
            return 'caught';
        }
    });
    expect($result)->toBe('caught');
});

test('a nested run() waits only for its own coroutines, runs inline, and rethrows alone', function () {
    $log = [];
    phasync::run(function () use (&$log) {
        $outer = phasync::getFiber();
        phasync::go(function () use (&$log) {
            phasync::sleep(0.03);
            $log[] = 'outer child done';
        });
        $r = phasync::run(function () use (&$log, $outer) {
            phasync::go(function () use (&$log) {
                phasync::sleep(0.01);
                $log[] = 'inner child done';
            });
            $log[] = 'inline: ' . (phasync::getFiber() === $outer ? 'yes' : 'no');

            return 'inner f';
        });
        $log[] = "nested run returned: $r";
        try {
            phasync::run(function () { phasync::go(function () { throw new LogicException('inner unawaited'); }); });
        } catch (LogicException $e) {
            $log[] = 'nested run rethrew: ' . $e->getMessage();
        }
    });
    $log[] = 'outer run returned';
    expect($log)->toBe(['inline: yes', 'inner child done', 'nested run returned: inner f', 'nested run rethrew: inner unawaited', 'outer child done', 'outer run returned']);
});

test('phasync::frame() is the frame of the innermost run() of the coroutine; go() inherits it', function () {
    phasync::run(function () {
        $outer = phasync::frame();
        expect($outer)->toBeInstanceOf(Frame::class);
        phasync::run(function () use ($outer) {
            $inner = phasync::frame();
            expect($inner)->not->toBe($outer);
            expect(phasync::await(phasync::go(fn () => phasync::frame())))->toBe($inner);
        });
        expect(phasync::frame())->toBe($outer);
    });
});

test('$frame->cancel() cancels every coroutine of the frame and of the frames nested in it', function () {
    $log = [];
    phasync::run(function () use (&$log) {
        $frame = null;
        $job   = phasync::go(function () use (&$frame, &$log) {
            phasync::run(function () use (&$frame, &$log) {
                $frame = phasync::frame();
                phasync::go(function () use (&$log) {
                    try {
                        phasync::sleep(10);
                    } catch (CancelledException) {
                        $log[] = 'child cancelled';
                    }
                });
                phasync::go(function () use (&$log) {
                    phasync::run(function () use (&$log) {
                        try {
                            phasync::sleep(10);
                        } catch (CancelledException) {
                            $log[] = 'nested cancelled';
                        }
                    });
                });
                try {
                    phasync::sleep(10);
                } catch (CancelledException $e) {
                    $log[] = 'main cancelled';

                    throw $e;
                }
            });
        });
        phasync::sleep(0.01);
        $frame->cancel();
        try {
            phasync::await($job);
        } catch (CancelledException) {
            $log[] = 'job ended cancelled';
        }
    });
    \sort($log);
    expect($log)->toBe(['child cancelled', 'job ended cancelled', 'main cancelled', 'nested cancelled']);
});

test('cancelling the frame you are in throws nothing at once: the next wait throws (#110)', function () {
    expect(fn () => phasync::run(function () {
        phasync::frame()->cancel('stop');
        phasync::sleep(0);
    }))->toThrow(CancelledException::class, 'stop');
    expect(phasync::run(function () {
        phasync::frame()->cancel('stop');

        return 'returned';
    }))->toBe('returned');
});

test('requestCancellation() sets the flag of the frame, throws nothing and wakes nothing (#110)', function () {
    $log = [];
    phasync::run(function () use (&$log) {
        $frame = phasync::frame();
        $child = phasync::go(function () use ($frame, &$log) {
            phasync::sleep(0.05);
            $log[] = 'child slept, requested: ' . \var_export($frame->isCancellationRequested(), true);
        });
        phasync::sleep(0.01);
        expect($frame->isCancellationRequested())->toBeFalse();
        $frame->requestCancellation();
        $log[] = 'requested';
        expect($frame->isCancellationRequested())->toBeTrue();
        phasync::sleep(0);     // no cancellation: waits go on as before
        phasync::await($child);
    });
    expect($log)->toBe(['requested', 'child slept, requested: true']);
});

test('requestCancellation() reaches the frames nested in the frame, those entered later too (#110)', function () {
    phasync::run(function () {
        $outer  = phasync::frame();
        $nested = null;
        $gate   = new stdClass();
        $job    = phasync::go(function () use (&$nested, $gate) {
            phasync::run(function () use (&$nested, $gate) {
                $nested = phasync::frame();
                phasync::awaitFlag($gate);

                return phasync::run(fn () => phasync::frame()->isCancellationRequested());
            });
        });
        phasync::sleep(0.01);
        expect($nested->isCancellationRequested())->toBeFalse();
        $outer->requestCancellation();
        expect($nested->isCancellationRequested())->toBeTrue();
        phasync::raiseFlag($gate);
        phasync::await($job);
        expect(phasync::run(fn () => phasync::frame()->isCancellationRequested()))->toBeTrue();
    });
});

test('requestCancellation() on a frame whose run() has returned throws a LogicException (#110)', function () {
    $frame = phasync::run(fn () => phasync::frame());
    expect(fn () => $frame->requestCancellation())->toThrow(LogicException::class);
});

test('cancel() and an expired setTimeout() request cancellation too; nothing else does (#110)', function () {
    $flags = [];
    phasync::run(function () use (&$flags) {
        $flags['plain'] = phasync::frame()->isCancellationRequested();
        $flags['go']    = phasync::await(phasync::go(fn () => phasync::frame()->isCancellationRequested()));
        try {
            phasync::run(function () use (&$flags) {
                phasync::frame()->cancel();
                $flags['cancel'] = phasync::frame()->isCancellationRequested();
            });
        } catch (CancelledException) {
        }
        try {
            phasync::run(function () use (&$flags) {
                phasync::frame()->setTimeout(0.01);
                $flags['before deadline'] = phasync::frame()->isCancellationRequested();
                try {
                    phasync::sleep(1);
                } catch (CancelledException) {
                    $flags['after deadline'] = phasync::frame()->isCancellationRequested();
                }
            });
        } catch (TimeoutException) {
        }
        $flags['after'] = phasync::frame()->isCancellationRequested();
    });
    expect($flags)->toBe(['plain' => false, 'go' => false, 'cancel' => true, 'before deadline' => false, 'after deadline' => true, 'after' => false]);
});

test('a frame timeout cancels the frame, and run() throws a TimeoutException', function () {
    $started = \microtime(true);
    $log     = [];
    try {
        phasync::run(function () use (&$log) {
            phasync::frame()->setTimeout(0.05);
            phasync::go(function () use (&$log) {
                try {
                    phasync::sleep(10);
                } catch (CancelledException) {
                    $log[] = 'child cancelled';
                }
            });
            phasync::sleep(10);
        });
    } catch (TimeoutException) {
        $log[] = 'timed out';
    }
    expect($log)->toBe(['child cancelled', 'timed out']);
    expect(\microtime(true) - $started)->toBeLessThan(1.0);
});

test('a frame exception handler takes the failures nobody awaited instead of run()', function () {
    $handled = [];
    $result  = phasync::run(function () use (&$handled) {
        phasync::frame()->setExceptionHandler(function (Throwable $e) use (&$handled) {
            $handled[] = $e->getMessage();
        });
        phasync::go(function () { throw new RuntimeException('one'); });
        phasync::go(function () {
            phasync::sleep(0.01);
            throw new RuntimeException('two');
        });

        return 'ok';
    });
    expect($result)->toBe('ok');
    expect($handled)->toBe(['one', 'two']);
});

test('with a frame exception handler, the children\'s failures go to it one by one, and $f\'s is thrown unchanged', function () {
    $handled = [];
    $thrown  = null;
    try {
        phasync::run(function () use (&$handled) {
            phasync::frame()->setExceptionHandler(function (Throwable $e) use (&$handled) {
                $handled[] = $e->getMessage();
            });
            phasync::go(function () { throw new RuntimeException('child'); });
            throw new LogicException('main');
        });
    } catch (Throwable $e) {
        $thrown = \get_class($e) . ': ' . $e->getMessage();
    }
    expect($thrown)->toBe('LogicException: main');
    expect($handled)->toBe(['child']);
});

test('a deadlock still fails the outermost run() with a DeadlockException', function () {
    expect(fn () => phasync::run(function () {
        phasync::awaitFlag($flag = new stdClass());
    }))->toThrow(phasync\DeadlockException::class);
});

// The in-place tick

test('a Fiber phasync did not start drives the loop in place with tick(), and the coroutine that started it is still current afterwards', function () {
    phasync::run(function () {
        $coroutine = phasync::getFiber();
        $frame     = phasync::frame();
        $done      = false;
        phasync::go(function () use (&$done) {
            phasync::sleep(0.01);
            $done = true;
        });
        $foreign = new Fiber(function () use (&$done, $coroutine, $frame) {
            while (!$done) {
                phasync::getLoop()->tick();
            }
            expect(phasync::getFiber())->toBe($coroutine);
            expect(phasync::frame())->toBe($frame);
        });
        $foreign->start();
        expect($foreign->isTerminated())->toBeTrue();
    });
});

// The api

test('go() and run() take their arguments variadically', function () {
    expect(phasync::run(fn ($a, $b) => phasync::await(phasync::go(fn ($c, $d) => $a . $b . $c . $d, 'c', 'd')), 'a', 'b'))->toBe('abcd');
});

test('a Frame cannot be made outside phasync::run()', function () {
    expect(fn () => new Frame(null))->toThrow(Error::class, 'Call to private phasync\Frame::__construct()');
});

// #108: waits in a Fiber phasync did not start (run in place)

test('a wait in a Fiber phasync did not start keeps its scope, and onScopeChange() switches back to it after every wait (#108)', function () {
    $names = new WeakMap();
    $live  = 'none';
    phasync::onScopeChange(function (Scope $new) use ($names, &$live) {
        $live = $names[$new] ?? 'root';
    });
    $log = [];
    phasync::run(function () use ($names, &$live, &$log) {
        $a         = new Scope();
        $names[$a] = 'A';
        $b         = new Scope();
        $names[$b] = 'B';
        $b->run(function () use (&$live, &$log) {
            phasync::go(function () use (&$live, &$log) {
                phasync::sleep(0.05);
                $log[] = "B live=$live";
                phasync::sleep(0.1);
                $log[] = "B live=$live";
            });
        });
        $a->run(function () use ($names, &$live, &$log) {
            phasync::go(function () use ($names, &$live, &$log) {
                $f = new Fiber(function () use ($names, &$live, &$log) {
                    $log[] = "A live=$live";
                    phasync::sleep(0.1);
                    $log[] = "A live=$live scope=" . ($names[Scope::getCurrent()] ?? 'root');
                    phasync::sleep(0.1);
                    $log[] = "A live=$live scope=" . ($names[Scope::getCurrent()] ?? 'root');
                });
                $f->start();
            });
        });
    });
    expect($log)->toBe(['A live=A', 'B live=B', 'A live=A scope=A', 'B live=B', 'A live=A scope=A']);
});

test('nested in-place waits from Fibers of two scopes, with a third scope running between, each keep their scope and their hook state (#108)', function () {
    $names = new WeakMap();
    $live  = 'none';
    phasync::onScopeChange(function (Scope $new) use ($names, &$live) {
        $live = $names[$new] ?? 'root';
    });
    $log = [];
    phasync::run(function () use ($names, &$live, &$log) {
        $c         = new Scope();
        $names[$c] = 'C';
        $c->run(function () {   // a plain coroutine of a third scope runs in between
            phasync::go(function () {
                for ($i = 0; $i < 8; ++$i) {
                    phasync::sleep(0.012);
                }
            });
        });
        foreach (['A' => 0.03, 'B' => 0.02] as $name => $delay) {
            $scope         = new Scope();
            $names[$scope] = $name;
            $scope->run(function () use ($name, $delay, $names, &$live, &$log) {
                phasync::go(function () use ($name, $delay, $names, &$live, &$log) {
                    (new Fiber(function () use ($name, $delay, $names, &$live, &$log) {
                        for ($i = 0; $i < 3; ++$i) {
                            phasync::sleep($delay);
                            $log[] = "$name: live=$live scope=" . ($names[Scope::getCurrent()] ?? 'root');
                        }
                    }))->start();
                });
            });
        }
    });
    expect(\count($log))->toBe(6);
    foreach ($log as $line) {
        [$name, $rest] = \explode(': ', $line);
        expect($rest)->toBe("live=$name scope=$name");
    }
});

test('count() of a frame is its coroutines: those started in it that have not ended, and the one running it', function () {
    $counts = phasync::run(function () {
        $counts   = [\count(phasync::frame())];
        $sleeper  = phasync::go(fn () => phasync::sleep(0.01));
        $counts[] = \count(phasync::frame());
        $counts[] = phasync::run(fn () => \count(phasync::frame())); // a nested run() is a frame of its own
        phasync::await($sleeper);
        $counts[] = \count(phasync::frame());

        return $counts;
    });

    expect($counts)->toBe([1, 2, 1, 1]);
});
