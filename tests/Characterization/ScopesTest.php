<?php

/*
 * Characterization tests for docs/SEMANTICS.md section 3 (SCO-1..7). These pin how
 * phasync behaves TODAY. See tests/Characterization/README.md before changing any of them.
 */

use phasync\ContextUsedException;

uses()->group('characterization');

/**
 * Runs $body in phasync::run(). Returns the log array, with a final entry if run() threw.
 */
function scoRun(Closure $body, array &$log): void
{
    try {
        phasync::run($body);
    } catch (Throwable $e) {
        $log[] = 'run threw ' . \get_class($e) . ': ' . $e->getMessage();
    }
}

// ---------------------------------------------------------------------------
// SCO-1  Every coroutine belongs to exactly one scope
// ---------------------------------------------------------------------------

test('SCO-1: a child and a grandchild share the creator\'s context object', function () {
    $r = phasync::run(function () {
        $ctx = phasync::getContext();

        return [
            'child'      => phasync::await(phasync::go(fn () => phasync::getContext() === $ctx)),
            'grandchild' => phasync::await(phasync::go(function () use ($ctx) {
                return phasync::await(phasync::go(fn () => phasync::getContext() === $ctx));
            })),
        ];
    });
    expect($r)->toBe(['child' => true, 'grandchild' => true]);
});

test('SCO-1: run() creates a context (an object), and a nested run() gets a new one', function () {
    $r = phasync::run(function () {
        $outer = phasync::getContext();

        return [
            'class'         => \get_class($outer),
            'nested is new' => phasync::run(fn () => phasync::getContext() !== $outer),
        ];
    });
    expect($r['class'])->toBe(stdClass::class);
    expect($r['nested is new'])->toBeTrue();
});

test('SCO-1: run() uses a context passed in, and refuses to reuse it', function () {
    $ctx  = new stdClass();
    $same = phasync::run(fn () => phasync::getContext() === $ctx, context: $ctx);
    expect($same)->toBeTrue();
    expect(fn () => phasync::run(fn () => 1, context: $ctx))->toThrow(ContextUsedException::class);
});

test('SCO-1: the context tracks the fibers that are alive in it', function () {
    $counts = phasync::run(function () {
        $ctx    = phasync::getContext();
        $count  = fn () => \count(phasync::getLoop()->getFibers($ctx));
        $before = $count();
        $child  = phasync::go(fn () => phasync::sleep(0.01));
        $during = $count();
        phasync::await($child);
        $after = $count();

        return [$before, $during, $after];
    });
    expect($counts)->toBe([1, 2, 1]);
});

test('SCO-1: go(context:) puts the child in a different context, so a coroutine can leave its creator\'s scope [SURPRISE]', function () {
    // The contract says a coroutine created by go() joins the creator's scope.
    $r = phasync::run(function () {
        $mine  = phasync::getContext();
        $other = new stdClass();
        $child = phasync::go(fn () => phasync::getContext() === $other, context: $other);

        return [phasync::await($child), $other !== $mine];
    });
    expect($r)->toBe([true, true]);
})->group('surprise');

// ---------------------------------------------------------------------------
// SCO-2  A scope does not exit until all its coroutines have finished
// ---------------------------------------------------------------------------

test('SCO-2: run() waits for un-awaited children and grandchildren, and still returns the main result', function () {
    $done = [];
    $t    = \microtime(true);
    $ret  = phasync::run(function () use (&$done) {
        phasync::go(function () use (&$done) {
            phasync::sleep(0.03);
            $done[] = 'child';
            phasync::go(function () use (&$done) {
                phasync::sleep(0.02);
                $done[] = 'grandchild';
            });
        });

        return 'main result';
    });
    expect($ret)->toBe('main result');
    expect($done)->toBe(['child', 'grandchild']);
    expect(\microtime(true) - $t)->toBeGreaterThanOrEqual(0.049);
});

test('SCO-2: a nested run() waits only for the coroutines of its own scope', function () {
    $log = [];
    phasync::run(function () use (&$log) {
        phasync::go(function () use (&$log) {
            phasync::sleep(0.1);
            $log[] = 'outer child';
        });
        phasync::go(function () use (&$log) {
            phasync::run(function () use (&$log) {
                phasync::go(function () use (&$log) {
                    phasync::sleep(0.02);
                    $log[] = 'inner child';
                });
            });
            $log[] = 'inner run returned';
        });
    });
    expect($log)->toBe(['inner child', 'inner run returned', 'outer child']);
});

test('SCO-2: the main coroutine\'s return value is only delivered after the scope has drained', function () {
    $log = [];
    $ret = phasync::run(function () use (&$log) {
        phasync::go(function () use (&$log) {
            phasync::sleep(0.02);
            $log[] = 'child done';
        });
        $log[] = 'main returning';

        return 'value';
    });
    $log[] = "run returned $ret";
    expect($log)->toBe(['main returning', 'child done', 'run returned value']);
});

// ---------------------------------------------------------------------------
// SCO-3  First unhandled failure cancels the scope
// ---------------------------------------------------------------------------

test('SCO-3: an un-awaited failing child with no handler fails the run: siblings and parent are cancelled, run() throws at once', function () {
    $log = [];
    $t   = \microtime(true);
    scoRun(function () use (&$log) {
        phasync::go(function () use (&$log) {
            try {
                phasync::sleep(0.05);
                $log[] = 'sibling finished';
            } catch (Throwable $e) {
                $log[] = 'sibling got ' . \get_class($e);
                throw $e;
            }
        });
        phasync::go(function () {
            phasync::sleep(0.01);
            throw new RuntimeException('boom');
        });
        phasync::sleep(0.1);
        $log[] = 'parent finished';
    }, $log);
    expect($log)->toBe(['sibling got phasync\CancelledException', 'run threw RuntimeException: boom']);
    // run() threw as soon as the failure had cancelled the scope, not after the parent's 0.1 s
    expect(\microtime(true) - $t)->toBeLessThan(0.09);
});

test('SCO-3: the parent\'s return value is lost when a child failed without being awaited [DIVERGENCE]', function () {
    $log = [];
    $ret = null;
    try {
        $ret = phasync::run(function () {
            phasync::go(function () {
                throw new RuntimeException('boom');
            });

            return 'parent value';
        });
    } catch (Throwable $e) {
        $log[] = \get_class($e) . ': ' . $e->getMessage();
    }
    expect($ret)->toBeNull();
    expect($log)->toBe(['RuntimeException: boom']);
})->group('divergence');

// ---------------------------------------------------------------------------
// SCO-4  run() throws the scope's failure
// ---------------------------------------------------------------------------

test('SCO-4: run() throws the first of several un-awaited failures', function () {
    $log = [];
    scoRun(function () {
        phasync::go(function () {
            phasync::sleep(0.01);
            throw new RuntimeException('first');
        });
        phasync::go(function () {
            phasync::sleep(0.03);
            throw new RuntimeException('second');
        });
        phasync::sleep(0.08);
    }, $log);
    expect($log)->toBe(['run threw RuntimeException: first']);
});

test('SCO-4: when the main coroutine fails too, both are thrown, its own first', function () {
    $log = [];
    scoRun(function () {
        phasync::go(function () {
            throw new RuntimeException('child');
        });
        throw new RuntimeException('main');
    }, $log);
    expect($log)->toBe(['run threw phasync\\AggregateException: 2 failures, the first: main']);
});

test('SCO-4: a failure in a grandchild surfaces from run()', function () {
    $log = [];
    scoRun(function () {
        phasync::go(function () {
            phasync::go(function () {
                throw new RuntimeException('grandchild');
            });
            phasync::sleep(0.01);
        });
    }, $log);
    expect($log)->toBe(['run threw RuntimeException: grandchild']);
});

test('SCO-4: a nested run() throws its scope\'s failure into the calling coroutine, which can handle it', function () {
    $log = [];
    scoRun(function () use (&$log) {
        try {
            phasync::run(function () {
                phasync::go(function () {
                    throw new LogicException('inner');
                });
            });
        } catch (LogicException $e) {
            $log[] = 'caught ' . $e->getMessage();
        }
        $log[] = 'outer continues';
    }, $log);
    expect($log)->toBe(['caught inner', 'outer continues']);
});

// ---------------------------------------------------------------------------
// SCO-5  Nested run() is a child scope
// ---------------------------------------------------------------------------

test('SCO-5: cancelling a coroutine blocked in a nested run() cancels the nested main coroutine, but not the nested scope\'s other children [DIVERGENCE]', function () {
    // Contract (SCO-5): cancelling the outer scope cancels the inner one. Today the inner
    // child keeps running after its parent was cancelled, and the outer run() waits for it.
    $log = [];
    scoRun(function () use (&$log) {
        $x = phasync::go(function () use (&$log) {
            try {
                phasync::run(function () use (&$log) {
                    phasync::go(function () use (&$log) {
                        try {
                            phasync::sleep(0.2);
                            $log[] = 'inner child finished';
                        } catch (Throwable $e) {
                            $log[] = 'inner child got ' . \get_class($e);
                            throw $e;
                        }
                    });
                    try {
                        phasync::sleep(0.2);
                        $log[] = 'inner main finished';
                    } catch (Throwable $e) {
                        $log[] = 'inner main got ' . \get_class($e);
                        throw $e;
                    }
                });
                $log[] = 'nested run returned';
            } catch (Throwable $e) {
                $log[] = 'x got ' . \get_class($e);
            }
        });
        phasync::sleep(0.02);
        phasync::cancel($x);
        try {
            phasync::await($x);
        } catch (Throwable $e) {
            $log[] = 'await x: ' . \get_class($e);
        }
        $log[] = 'outer end';
    }, $log);
    expect($log)->toBe([
        'inner main got phasync\CancelledException',
        'x got phasync\CancelledException',
        'outer end',
        'inner child finished',
    ]);
})->group('divergence');

// ---------------------------------------------------------------------------
// SCO-6  service(): detached work
// ---------------------------------------------------------------------------

test('SCO-6: service() outside a coroutine throws LogicException', function () {
    expect(fn () => phasync::service(fn () => 1))->toThrow(LogicException::class);
});

test('SCO-6: a service coroutine runs in the service context, not in the creator\'s scope', function () {
    $r = phasync::run(function () {
        $mine    = phasync::getContext();
        $service = null;
        phasync::service(function () use (&$service) {
            $service = phasync::getContext();
        });
        phasync::sleep(0.01);

        return [\is_object($service), $service !== $mine];
    });
    expect($r)->toBe([true, true]);
});

test('SCO-6: a service started after earlier services ended can enter contexts (#78)', function () {
    $log = [];
    phasync::run(function () use (&$log) {
        phasync::service(function () { phasync::sleep(0.01); });
        phasync::sleep(0.05); // the service has ended
        phasync::service(function () use (&$log) {
            phasync::await(phasync::go(function () use (&$log) {
                phasync::withContext(function () use (&$log) { $log[] = 'inner ok'; }, new stdClass());
            }));
        });
    });
    expect($log)->toBe(['inner ok']);
});

test('SCO-6: the top-level run() keeps running until its services have finished, though the main coroutine returned', function () {
    $log = [];
    $t   = \microtime(true);
    $ret = phasync::run(function () use (&$log) {
        phasync::service(function () use (&$log) {
            phasync::sleep(0.05);
            $log[] = 'service done';
        });
        $log[] = 'main end';

        return 'ret';
    });
    expect($ret)->toBe('ret');
    expect($log)->toBe(['main end', 'service done']);
    expect(\microtime(true) - $t)->toBeGreaterThanOrEqual(0.049);
});

test('SCO-6: a nested run() does not wait for services; only the top-level run() does', function () {
    $log = [];
    phasync::run(function () use (&$log) {
        phasync::run(function () use (&$log) {
            phasync::service(function () use (&$log) {
                phasync::sleep(0.05);
                $log[] = 'service';
            });
            $log[] = 'nested end';
        });
        $log[] = 'after nested';
    });
    expect($log)->toBe(['nested end', 'after nested', 'service']);
});

test('SCO-6: an unhandled exception in a service fails the outermost run(), which throws it', function () {
    $process = \proc_open(
        [\PHP_BINARY, __DIR__ . '/fixtures/service-failure.php'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    $stdout = \stream_get_contents($pipes[1]);
    \stream_get_contents($pipes[2]);
    \fclose($pipes[1]);
    \fclose($pipes[2]);
    expect(\proc_close($process))->toBe(0);
    expect($stdout)->toBe("run threw RuntimeException: service failure\n");
});

// ---------------------------------------------------------------------------
// SCO-7  A context is any object; phasync keeps no storage on it
// ---------------------------------------------------------------------------

test('SCO-7: a context is any object: run(), go() and withContext() take it, getContext() returns it', function () {
    $r = phasync::run(function () {
        $mine  = phasync::getContext();
        $other = new ArrayObject();

        return [
            phasync::await(phasync::go(fn () => phasync::getContext() === $other, context: $other)),
            phasync::withContext(fn () => phasync::getContext(), $inner = new SplQueue()) === $inner,
            phasync::getContext() === $mine,
        ];
    }, context: new DateTime());
    expect($r)->toBe([true, true, true]);
});

test('SCO-7: cancel() of a context cancels the waiting coroutines of it and of the contexts nested in it, the deepest first, but not the caller', function () {
    $log = phasync::run(function () {
        $log     = [];
        $context = new stdClass();
        $wait    = static function (string $name) use (&$log) {
            try {
                phasync::sleep(5);
            } catch (phasync\CancelledException) {
                $log[] = "$name cancelled";
            }
        };
        phasync::go(function () use ($wait) {
            phasync::go(fn () => $wait('grandchild'));
            $wait('child');
        }, context: $context);
        phasync::go(fn () => phasync::withContext(fn () => $wait('nested'), new stdClass()), context: new stdClass()); // not nested in $context
        phasync::go(function () use ($context, $wait) {
            phasync::withContext(function () use ($context, $wait) {
                phasync::go(fn () => $wait('nested in context'));
                phasync::sleep(0.01);
                phasync::cancel($context); // the caller is in $context too, and is not cancelled
                $wait('caller');
            }, new stdClass());
        }, context: new class($context) {
            public function __construct(public object $outer)
            {
            }
        });
        phasync::sleep(0.05);

        return $log;
    });
    // Only $context's own coroutines and those nested in it; the caller's own context is not
    // nested in $context, so the caller isn't either
    expect($log)->toBe(['grandchild cancelled', 'child cancelled']);
});

// ---------------------------------------------------------------------------
// SCO-6  service(background: true)
// ---------------------------------------------------------------------------

test('SCO-6: a background service does not keep the top-level run() waiting; it is cancelled when the rest has ended', function () {
    $log   = [];
    $start = \microtime(true);
    phasync::run(function () use (&$log) {
        phasync::service(function () use (&$log) {
            try {
                phasync::sleep(10);
            } finally {
                $log[] = 'background unwound';
            }
        }, background: true);
        $log[] = 'main end';
    });
    expect($log)->toBe(['main end', 'background unwound']);
    expect(\microtime(true) - $start)->toBeLessThan(1.0);
});

test('SCO-6: a background service runs as long as anything else does', function () {
    $ticks = 0;
    phasync::run(function () use (&$ticks) {
        phasync::service(function () use (&$ticks) {
            while (true) {
                phasync::sleep(0.01);
                ++$ticks;
            }
        }, background: true);
        phasync::go(function () {
            phasync::sleep(0.1);
        });
        phasync::sleep(0.05);
    });
    expect($ticks)->toBeGreaterThanOrEqual(8);
    expect($ticks)->toBeLessThan(13);
});

test('SCO-6: a background service that has ended on its own is not counted again, and the next run() waits for ordinary services', function () {
    phasync::run(fn () => phasync::service(fn () => 1, background: true));
    $log = [];
    phasync::run(function () use (&$log) {
        phasync::service(function () use (&$log) {
            phasync::sleep(0.03);
            $log[] = 'service done';
        });
    });
    expect($log)->toBe(['service done']);
});
