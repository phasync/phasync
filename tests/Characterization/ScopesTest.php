<?php

/*
 * Characterization tests for docs/SEMANTICS.md section 3 (SCO-1..7). These pin how
 * phasync behaves TODAY. See tests/Characterization/README.md before changing any of them.
 *
 * Rewritten for phasync 2.x's Scope + Frame redesign (owner's decision, 2026-10-13): the
 * context argument and getContext() are gone; run() creates a Frame (lifetime), the ambient
 * Scope (fiber-interop/scope) is the identity, and a failure no longer cancels the frame.
 */

use phasync\Frame;

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
// SCO-1  Every coroutine belongs to exactly one frame
// ---------------------------------------------------------------------------

test('SCO-1: a child and a grandchild share the creator\'s frame', function () {
    $r = phasync::run(function () {
        $frame = phasync::frame();

        return [
            'child'      => phasync::await(phasync::go(fn () => phasync::frame() === $frame)),
            'grandchild' => phasync::await(phasync::go(function () use ($frame) {
                return phasync::await(phasync::go(fn () => phasync::frame() === $frame));
            })),
        ];
    });
    expect($r)->toBe(['child' => true, 'grandchild' => true]);
});

test('SCO-1: run() creates a frame, and a nested run() gets a new one, until it returns', function () {
    $r = phasync::run(function () {
        $outer = phasync::frame();

        return [
            'class'         => \get_class($outer),
            'nested is new' => phasync::run(fn () => phasync::frame() !== $outer),
            'outer again'   => phasync::frame() === $outer,
        ];
    });
    expect($r)->toBe(['class' => Frame::class, 'nested is new' => true, 'outer again' => true]);
});

test('SCO-1: the frame tracks the fibers that are alive in it', function () {
    $counts = phasync::run(function () {
        $frame  = phasync::frame();
        $count  = fn () => \count(phasync::getLoop()->getFibers($frame));
        $before = $count();
        $child  = phasync::go(fn () => phasync::sleep(0.01));
        $during = $count();
        phasync::await($child);
        $after = $count();

        return [$before, $during, $after];
    });
    expect($counts)->toBe([1, 2, 1]);
});

test('SCO-1: go() can\'t put a coroutine in another frame: only run() and service() leave the creator\'s', function () {
    $params = (new ReflectionMethod('phasync', 'go'))->getParameters();
    expect(\array_map(fn ($p) => $p->getName(), $params))->toBe(['fn', 'args']);
    expect($params[1]->isVariadic())->toBeTrue();
});

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
// SCO-3  A failure nobody awaits does not cancel the frame; run() throws it at the end
// ---------------------------------------------------------------------------

test('SCO-3: an un-awaited failing child does not cancel its siblings or the parent; run() throws it once they have ended', function () {
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
    expect(\microtime(true) - $t)->toBeGreaterThanOrEqual(0.099);
});

test('SCO-3: the parent\'s return value is lost when a child failed without being awaited', function () {
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
});

test('SCO-3: with an exception handler on the frame, the failures go to it, and run() returns', function () {
    $handled = [];
    $ret     = phasync::run(function () use (&$handled) {
        phasync::frame()->setExceptionHandler(function (Throwable $e) use (&$handled) {
            $handled[] = $e->getMessage();
        });
        phasync::go(function () {
            throw new RuntimeException('boom');
        });

        return 'parent value';
    });
    expect($ret)->toBe('parent value');
    expect($handled)->toBe(['boom']);
});

test('SCO-3: what the exception handler throws, run() throws', function () {
    $log = [];
    scoRun(function () {
        phasync::frame()->setExceptionHandler(function (Throwable $e) {
            throw new LogicException('rethrown: ' . $e->getMessage());
        });
        phasync::go(function () {
            throw new RuntimeException('boom');
        });
    }, $log);
    expect($log)->toBe(['run threw LogicException: rethrown: boom']);
});

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

test('SCO-4: when the main coroutine fails too, its own failure is thrown', function () {
    $log = [];
    scoRun(function () {
        phasync::go(function () {
            throw new RuntimeException('child');
        });
        throw new RuntimeException('main');
    }, $log);
    expect($log)->toBe(['run threw RuntimeException: main']);
});

test('SCO-4: the earliest failure is thrown, by when it happened, not by when it became known', function () {
    $log = [];
    scoRun(function () {
        $late  = phasync::go(function () {
            phasync::sleep(0.02);
            throw new RuntimeException('late');
        });
        $early = phasync::go(function () {
            phasync::sleep(0.01);
            throw new RuntimeException('early');
        });
        phasync::sleep(0.03);
        unset($late, $early); // the late one is released first
    }, $log);
    expect($log)->toBe(['run threw RuntimeException: early']);
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
// SCO-5  Nested run() is a child frame
// ---------------------------------------------------------------------------

test('SCO-5: cancelling a coroutine blocked in a nested run() cancels the nested frame\'s children too, and waits for them', function () {
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
        'inner child got phasync\CancelledException',
        'x got phasync\CancelledException',
        'outer end',
    ]);
});

// ---------------------------------------------------------------------------
// SCO-6  service(): detached work
// ---------------------------------------------------------------------------

test('SCO-6: service() outside a coroutine throws LogicException', function () {
    expect(fn () => phasync::service(fn () => 1))->toThrow(LogicException::class);
});

test('SCO-6: a service coroutine runs in the root scope and has no frame', function () {
    $r = phasync::run(function () {
        $service = null;
        (new Scope())->run(function () use (&$service) {
            phasync::service(function () use (&$service) {
                try {
                    phasync::frame();
                } catch (LogicException) {
                    $service = Scope::getCurrent() === Scope::getRootScope() ? 'root, no frame' : 'other';
                }
            });
        });
        phasync::sleep(0.01);

        return $service;
    });
    expect($r)->toBe('root, no frame');
});

test('SCO-6: a service started after earlier services ended can start coroutines and nested runs (#78)', function () {
    $log = [];
    phasync::run(function () use (&$log) {
        phasync::service(function () { phasync::sleep(0.01); });
        phasync::sleep(0.05); // the service has ended
        phasync::service(function () use (&$log) {
            phasync::await(phasync::go(function () use (&$log) {
                phasync::run(function () use (&$log) { $log[] = 'inner ok'; });
            }));
        });
        phasync::sleep(0.01);
    });
    expect($log)->toBe(['inner ok']);
});

test('SCO-6: the top-level run() cancels its services once its frame has no coroutine left, and returns when they have ended', function () {
    $log = [];
    $t   = \microtime(true);
    $ret = phasync::run(function () use (&$log) {
        phasync::service(function () use (&$log) {
            try {
                phasync::sleep(5);
                $log[] = 'service done';
            } catch (phasync\CancelledException) {
                $log[] = 'service cancelled';
            }
        });
        $log[] = 'main end';

        return 'ret';
    });
    expect($ret)->toBe('ret');
    expect($log)->toBe(['main end', 'service cancelled']);
    expect(\microtime(true) - $t)->toBeLessThan(1.0);
});

test('SCO-6: a nested run() does not wait for services', function () {
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
        phasync::sleep(0.1);
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
// SCO-7  The Scope is the identity; the frame is the lifetime
// ---------------------------------------------------------------------------

test('SCO-7: go() inherits Scope::getCurrent(), run() leaves it as it is, service() runs in the root scope', function () {
    $scope = new Scope();
    $r     = phasync::run(function () use ($scope) {
        return $scope->run(function () use ($scope) {
            return [
                'go'      => phasync::await(phasync::go(fn () => Scope::getCurrent() === $scope)),
                'run'     => phasync::run(fn () => Scope::getCurrent() === $scope),
                'service' => phasync::await(phasync::service(fn () => Scope::getCurrent() === Scope::getRootScope())),
            ];
        });
    });
    expect($r)->toBe(['go' => true, 'run' => true, 'service' => true]);
});

test('SCO-7: Frame::cancel() reaches the deepest coroutines first, and spares the other frames until they are cancelled', function () {
    $log  = [];
    $wait = static function (string $name) use (&$log) {
        try {
            phasync::sleep(5);
        } catch (phasync\CancelledException) {
            $log[] = "$name cancelled";
        }
    };
    phasync::run(function () use ($wait, &$log) {
        $frame = null;
        phasync::go(function () use ($wait, &$frame) {
            phasync::run(function () use ($wait, &$frame) {
                $frame = phasync::frame();
                phasync::go(function () use ($wait) {
                    phasync::go(fn () => $wait('grandchild'));
                    $wait('child');
                });
                phasync::go(fn () => phasync::run(fn () => $wait('nested')));
                $wait('main');
            });
        });
        $other = phasync::go(fn () => phasync::run(fn () => $wait('other frame')));
        phasync::sleep(0.01);
        $frame->cancel();
        phasync::sleep(0.01);
        $log[] = 'other frame still waits: ' . ($other->isTerminated() ? 'no' : 'yes');
        phasync::cancel($other);
    });
    expect($log)->toBe(['grandchild cancelled', 'child cancelled', 'nested cancelled', 'main cancelled', 'other frame still waits: yes', 'other frame cancelled']);
});
