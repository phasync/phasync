<?php

/*
 * Characterization tests for docs/SEMANTICS.md section 3 (SCO-1..7). These pin how
 * phasync behaves TODAY. See tests/Characterization/README.md before changing any of them.
 */

use phasync\Context\DefaultContext;
use phasync\Context\ServiceContext;
use phasync\ContextUsedException;

uses()->group('characterization');

// Without this, go() may suspend its caller depending on wall-clock time (see SCH-5).
beforeEach(function () {
    phasync::setPreemptInterval(3_600_000_000);
});
afterEach(function () {
    phasync::setPreemptInterval(50_000);
});

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

test('SCO-1: run() creates a DefaultContext, and a nested run() gets a new one', function () {
    $r = phasync::run(function () {
        $outer = phasync::getContext();

        return [
            'class'         => \get_class($outer),
            'nested is new' => phasync::run(fn () => phasync::getContext() !== $outer),
        ];
    });
    expect($r['class'])->toBe(DefaultContext::class);
    expect($r['nested is new'])->toBeTrue();
});

test('SCO-1: run() uses a context passed in, marks it activated, and refuses to reuse it', function () {
    $ctx = new DefaultContext();
    expect($ctx->isActivated())->toBeFalse();
    $same = phasync::run(fn () => phasync::getContext() === $ctx, context: $ctx);
    expect($same)->toBeTrue();
    expect($ctx->isActivated())->toBeTrue();
    expect(fn () => phasync::run(fn () => 1, context: $ctx))->toThrow(ContextUsedException::class);
});

test('SCO-1: the context tracks the fibers that are alive in it', function () {
    $counts = phasync::run(function () {
        $ctx    = phasync::getContext();
        $before = $ctx->getFibers()->count();
        $child  = phasync::go(fn () => phasync::sleep(0.01));
        $during = $ctx->getFibers()->count();
        phasync::await($child);
        $after = $ctx->getFibers()->count();

        return [$before, $during, $after];
    });
    expect($counts)->toBe([1, 2, 1]);
});

test('SCO-1: go(context:) puts the child in a different context, so a coroutine can leave its creator\'s scope [SURPRISE]', function () {
    // The contract says a coroutine created by go() joins the creator's scope.
    $r = phasync::run(function () {
        $mine  = phasync::getContext();
        $other = new DefaultContext();
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
// SCO-3  First unhandled failure cancels the scope (NOT implemented today)
// ---------------------------------------------------------------------------

test('SCO-3: an un-awaited failing child neither cancels its siblings nor interrupts the parent [DIVERGENCE]', function () {
    // Contract (SCO-3): the scope is cancelled and the sibling receives CancelledException.
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
    expect($log)->toBe(['sibling finished', 'parent finished', 'run threw RuntimeException: boom']);
    // run() only threw once everything had run to completion.
    expect(\microtime(true) - $t)->toBeGreaterThanOrEqual(0.099);
})->group('divergence');

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

test('SCO-4: when the main coroutine fails too, its own exception wins over a child\'s', function () {
    $log = [];
    scoRun(function () {
        phasync::go(function () {
            throw new RuntimeException('child');
        });
        throw new RuntimeException('main');
    }, $log);
    expect($log)->toBe(['run threw RuntimeException: main']);
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

test('SCO-6: a service coroutine runs in a ServiceContext, not in the creator\'s scope', function () {
    $r = phasync::run(function () {
        $mine    = phasync::getContext();
        $service = null;
        phasync::service(function () use (&$service) {
            $service = phasync::getContext();
        });
        phasync::sleep(0.01);

        return [\get_class($service), $service !== $mine];
    });
    expect($r)->toBe([ServiceContext::class, true]);
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

test('SCO-6: an unhandled exception in a service is printed to STDERR as "FATAL" and run() carries on as if nothing happened [DIVERGENCE]', function () {
    // Contract (SCO-6): unhandled service exceptions go to logUnhandledException().
    // Run in a subprocess because the report goes straight to STDERR.
    $process = \proc_open(
        [\PHP_BINARY, __DIR__ . '/fixtures/service-failure.php'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    $stdout = \stream_get_contents($pipes[1]);
    $stderr = \stream_get_contents($pipes[2]);
    \fclose($pipes[1]);
    \fclose($pipes[2]);
    $exit = \proc_close($process);

    expect($stdout)->toContain('run returned: main done');
    expect($stderr)->toContain('ERROR IN SERVICE CONTEXT');
    expect($stderr)->toContain('service failure');
    expect($stderr)->toContain('THIS IS A FATAL ERROR. ALWAYS HANDLE EXCEPTIONS IN SERVICES');
    expect($exit)->toBe(0);
})->group('divergence');

// ---------------------------------------------------------------------------
// SCO-7  Scope-local data (ArrayAccess on the context)
// ---------------------------------------------------------------------------

test('SCO-7: the context stores values by key; children of the scope see them, a nested run() does not', function () {
    $r = phasync::run(function () {
        $ctx      = phasync::getContext();
        $ctx['k'] = 'v';
        $out      = [
            'get'         => $ctx['k'],
            'isset'       => isset($ctx['k']),
            'child sees'  => phasync::await(phasync::go(fn () => phasync::getContext()['k'])),
            'nested sees' => phasync::run(fn () => phasync::getContext()['k'] ?? 'nothing'),
        ];
        unset($ctx['k']);
        $out['after unset'] = isset($ctx['k']);
        $ctx[]              = 'appended';
        $out['appended']    = $ctx[0];

        return $out;
    });
    expect($r)->toBe([
        'get'         => 'v',
        'isset'       => true,
        'child sees'  => 'v',
        'nested sees' => 'nothing',
        'after unset' => false,
        'appended'    => 'appended',
    ]);
});

test('SCO-7: reading a missing key returns null but raises a PHP notice about returning by reference [SURPRISE]', function () {
    $notices = [];
    \set_error_handler(function (int $no, string $str) use (&$notices) {
        $notices[] = $str;

        return true;
    });
    try {
        $value = phasync::run(fn () => phasync::getContext()['missing']);
    } finally {
        \restore_error_handler();
    }
    expect($value)->toBeNull();
    expect($notices)->toHaveCount(1);
    expect($notices[0])->toContain('Only variable references should be returned by reference');
})->group('surprise');

test('SCO-7: object keys do not work; the first use throws an Error about an uninitialized property [SURPRISE]', function () {
    $error = null;
    phasync::run(function () use (&$error) {
        try {
            phasync::getContext()[new stdClass()] = 1;
        } catch (Throwable $e) {
            $error = \get_class($e) . ': ' . $e->getMessage();
        }
    });
    expect($error)->toBe('Error: Typed property phasync\Context\DefaultContext::$dataObjectKeys must not be accessed before initialization');
})->group('surprise');

test('SCO-7: isset() with a null key throws a TypeError [SURPRISE]', function () {
    $error = null;
    phasync::run(function () use (&$error) {
        try {
            isset(phasync::getContext()[null]);
        } catch (Throwable $e) {
            $error = \get_class($e);
        }
    });
    expect($error)->toBe(TypeError::class);
})->group('surprise');
