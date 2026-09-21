<?php

/*
 * Characterization tests for docs/SEMANTICS.md section 5 (CAN-1 .. CAN-9).
 * They pin how cancellation behaves TODAY. Read tests/Characterization/README.md
 * before changing anything here.
 */

use phasync\CancelledException;

uses()->group('characterization');

if (!\function_exists('canPair')) {
    /**
     * A connected socket pair. Both ends must stay referenced, otherwise the
     * peer closes and the other end is immediately readable (EOF).
     */
    function canPair(): array
    {
        return \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
    }
}

// ---------------------------------------------------------------------------
// CAN-1: cancellation is delivered as an exception at a suspension point
// ---------------------------------------------------------------------------

$suspensionKinds = [
    'sleep'       => function () {
        return [static fn () => phasync::sleep(5), static fn () => null];
    },
    'awaitFlag'   => function () {
        return [static fn () => heldFlagWait(), static fn () => null];
    },
    'readable'    => function () {
        [$a, $b] = canPair();

        return [static fn () => phasync::readable($a), static fn () => [$a, $b]];
    },
    'await'       => function () {
        return [static fn () => phasync::await(phasync::go(static fn () => phasync::sleep(0.3))), static fn () => null];
    },
    'channel read' => function () {
        phasync::channel($r, $w);

        return [static fn () => $r->read(), static fn () => [$r, $w]];
    },
];

foreach ($suspensionKinds as $kind => $factory) {
    test("CAN-1: cancel() throws CancelledException into a coroutine suspended in $kind", function () use ($factory) {
        $caught = phasync::run(function () use ($factory) {
            [$body, $keepAlive] = $factory();
            $child              = phasync::go(static function () use ($body) {
                try {
                    $body();
                } catch (Throwable $e) {
                    return $e::class;
                }

                return 'returned normally';
            });
            phasync::sleep(0.01);
            phasync::cancel($child);
            $keepAlive();

            return phasync::await($child);
        });

        expect($caught)->toBe(CancelledException::class);
    });
}

test('CAN-1: cancel() only queues the exception, the target has not run when cancel() returns', function () {
    $log = phasync::run(function () {
        $log   = [];
        $child = phasync::go(static function () use (&$log) {
            try {
                phasync::sleep(5);
            } catch (CancelledException) {
                $log[] = 'child caught';
            }
        });
        phasync::cancel($child);
        $log[] = 'cancel returned';
        phasync::await($child);

        return $log;
    });

    expect($log)->toBe(['cancel returned', 'child caught']);
});

test('CAN-1: cancel() of a runnable coroutine (suspended in sleep(0)) is delivered when it next runs', function () {
    $log = phasync::run(function () {
        $log   = [];
        $child = phasync::go(static function () use (&$log) {
            try {
                phasync::sleep(0);
                $log[] = 'resumed normally';
            } catch (Throwable $e) {
                $log[] = $e::class;
            }
        });
        phasync::cancel($child);
        phasync::await($child);

        return $log;
    });

    expect($log)->toBe([CancelledException::class]);
});

test('CAN-1: cancel($fiber, $exception) delivers that exception instead of CancelledException', function () {
    $caught = phasync::run(function () {
        $child = phasync::go(static function () {
            try {
                phasync::sleep(5);
            } catch (Throwable $e) {
                return $e::class . ':' . $e->getMessage();
            }
        });
        phasync::sleep(0.01);
        phasync::cancel($child, new RuntimeException('custom'));

        return phasync::await($child);
    });

    expect($caught)->toBe('RuntimeException:custom');
});

test('CAN-1: cancel() of a Fiber that phasync did not create throws LogicException', function () {
    $fiber = new Fiber(static function () {
        Fiber::suspend();
    });
    $fiber->start();

    expect(static fn () => phasync::cancel($fiber))->toThrow(LogicException::class, 'is not a phasync fiber');
});

// ---------------------------------------------------------------------------
// CAN-2 / CAN-3: what cancel() does to a running or terminated coroutine
// ---------------------------------------------------------------------------

test('CAN-2: cancelling the running coroutine (itself) throws RuntimeException [DIVERGENCE]', function () {
    // The contract (CAN-2) says this is legal and delivered at the next suspension.
    $message = phasync::run(static function () {
        try {
            phasync::cancel(Fiber::getCurrent());
        } catch (Throwable $e) {
            return $e::class . ': ' . $e->getMessage();
        }

        return 'no exception';
    });

    expect($message)->toStartWith('RuntimeException: Unable to cancel fiber');
    expect($message)->toEndWith('not found.');
})->group('divergence');

test('CAN-2: cancelling a running parent from its child throws RuntimeException [DIVERGENCE]', function () {
    // go() runs the child immediately, so the parent is running (not suspended) at that point.
    $result = phasync::run(static function () {
        $parent = Fiber::getCurrent();
        $child  = phasync::go(static function () use ($parent) {
            try {
                phasync::cancel($parent);

                return 'cancelled';
            } catch (Throwable $e) {
                return $e::class;
            }
        });

        return phasync::await($child);
    });

    expect($result)->toBe(RuntimeException::class);
})->group('divergence');

test('CAN-3: cancelling a terminated coroutine throws InvalidArgumentException [DIVERGENCE]', function () {
    // The contract (CAN-3) says this is a no-op.
    $result = phasync::run(static function () {
        $child = phasync::go(static fn () => 1);
        try {
            phasync::cancel($child);
        } catch (Throwable $e) {
            return $e::class . ': ' . $e->getMessage();
        }

        return 'no exception';
    });

    expect($result)->toBe('InvalidArgumentException: Fiber is already terminated');
})->group('divergence');

// ---------------------------------------------------------------------------
// CAN-4: delivery count
// ---------------------------------------------------------------------------

test('CAN-4: a coroutine that catches CancelledException can keep suspending in its cleanup', function () {
    $result = phasync::run(static function () {
        $child = phasync::go(static function () {
            try {
                phasync::sleep(5);
            } catch (CancelledException) {
                phasync::sleep(0.05);
                phasync::sleep(0.05);

                return 'cleaned up';
            }
        });
        phasync::cancel($child);

        return phasync::await($child);
    });

    expect($result)->toBe('cleaned up');
});

test('CAN-4: suspending in a finally block during cancellation is not cancelled again', function () {
    $log = phasync::run(static function () {
        $log   = [];
        $child = phasync::go(static function () use (&$log) {
            try {
                phasync::sleep(5);
            } finally {
                phasync::sleep(0.05);
                $log[] = 'finally finished';
            }
        });
        phasync::cancel($child);
        try {
            phasync::await($child);
        } catch (CancelledException) {
            $log[] = 'awaiter got CancelledException';
        }

        return $log;
    });

    expect($log)->toBe(['finally finished', 'awaiter got CancelledException']);
});

test('CAN-4: a second cancel() while the target is suspended in its cleanup cancels the cleanup', function () {
    $log = [];
    phasync::run(static function () use (&$log) {
        $child = phasync::go(static function () use (&$log) {
            try {
                phasync::sleep(5);
            } catch (CancelledException) {
                $log[] = 'child caught';
                phasync::sleep(0.2);
                $log[] = 'cleanup finished';
            }
        });
        phasync::cancel($child);
        phasync::sleep(0.02);
        phasync::cancel($child);
        try {
            phasync::await($child);
        } catch (Throwable $e) {
            $log[] = 'await threw ' . $e::class;
        }
    });

    expect($log)->toBe(['child caught', 'await threw ' . CancelledException::class]);
});

test('CAN-4: cancelling twice before the target resumes delivers only the second exception and run() throws the first [SURPRISE]', function () {
    $log    = [];
    $thrown = null;
    try {
        phasync::run(static function () use (&$log) {
            $child = phasync::go(static function () use (&$log) {
                try {
                    phasync::sleep(5);
                } catch (Throwable $e) {
                    $log[] = 'child got ' . $e::class . ':' . $e->getMessage();
                }
            });
            phasync::cancel($child, new RuntimeException('one'));
            phasync::cancel($child, new LogicException('two'));
            phasync::await($child);
        });
    } catch (Throwable $e) {
        $thrown = $e::class . ':' . $e->getMessage();
    }

    // The child handled the exception it received, yet run() still throws the first one.
    expect($log)->toBe(['child got LogicException:two']);
    expect($thrown)->toBe('RuntimeException:one');
})->group('surprise');

test('CAN-4: cancelling twice with the default exception makes run() throw CancelledException although the child caught it [SURPRISE]', function () {
    $log    = [];
    $thrown = null;
    try {
        phasync::run(static function () use (&$log) {
            $child = phasync::go(static function () use (&$log) {
                try {
                    phasync::sleep(5);
                } catch (Throwable $e) {
                    $log[] = 'child caught ' . $e::class;
                }
            });
            phasync::cancel($child);
            phasync::cancel($child);
            phasync::await($child);
        });
    } catch (Throwable $e) {
        $thrown = $e::class;
    }

    expect($log)->toBe(['child caught ' . CancelledException::class]);
    expect($thrown)->toBe(CancelledException::class);
})->group('surprise');

// ---------------------------------------------------------------------------
// CAN-5: scopes
// ---------------------------------------------------------------------------

test('CAN-5: cancelling the coroutine running a nested run() leaves the nested scope\'s other coroutines running [DIVERGENCE]', function () {
    // The contract (CAN-5) says cancelling a scope cancels every coroutine in it, and
    // (SCO-2) that run() does not exit before they finish. Today the nested run() exits
    // through its CancelledException while its child keeps running to completion.
    $log = phasync::run(static function () {
        $log   = [];
        $outer = phasync::go(static function () use (&$log) {
            return phasync::run(static function () use (&$log) {
                phasync::go(static function () use (&$log) {
                    try {
                        phasync::sleep(0.3);
                        $log[] = 'inner child finished';
                    } catch (Throwable $e) {
                        $log[] = 'inner child got ' . $e::class;
                    }
                });
                try {
                    phasync::sleep(5);
                } catch (Throwable $e) {
                    $log[] = 'inner main got ' . $e::class;
                    throw $e;
                }
            });
        });
        phasync::sleep(0.05);
        phasync::cancel($outer);
        try {
            phasync::await($outer);
        } catch (Throwable $e) {
            $log[] = 'outer await threw ' . $e::class;
        }
        phasync::sleep(0.5);

        return $log;
    });

    expect($log)->toBe([
        'inner main got ' . CancelledException::class,
        'outer await threw ' . CancelledException::class,
        'inner child finished',
    ]);
})->group('divergence');

test('CAN-5: a coroutine created by an already-cancelled coroutine runs normally [DIVERGENCE]', function () {
    // The contract (CAN-5) says coroutines created in a cancelled scope start cancelled.
    $log = phasync::run(static function () {
        $log   = [];
        $child = phasync::go(static function () use (&$log) {
            try {
                phasync::sleep(5);
            } catch (CancelledException) {
                $grandchild = phasync::go(static function () use (&$log) {
                    phasync::sleep(0.01);
                    $log[] = 'grandchild ran normally';
                });
                phasync::await($grandchild);
            }
        });
        phasync::cancel($child);
        phasync::await($child);

        return $log;
    });

    expect($log)->toBe(['grandchild ran normally']);
})->group('divergence');

// ---------------------------------------------------------------------------
// CAN-6: cancellation never loses or duplicates data
// ---------------------------------------------------------------------------

test('CAN-6: a cancelled channel read consumes nothing', function () {
    $result = phasync::run(static function () {
        phasync::channel($r, $w);
        $reader = phasync::go(static function () use ($r) {
            try {
                return $r->read();
            } catch (Throwable $e) {
                return $e::class;
            }
        });
        phasync::sleep(0.01);
        phasync::cancel($reader);
        $cancelled = phasync::await($reader);

        $next = phasync::go(static fn () => $r->read());
        $w->write('hello');

        return [$cancelled, phasync::await($next)];
    });

    expect($result)->toBe([CancelledException::class, 'hello']);
});

test('CAN-6: a cancelled blocked channel write delivers nothing', function () {
    $log = phasync::run(static function () {
        phasync::channel($r, $w, 1);
        $w->write('first');
        $log    = [];
        $writer = phasync::go(static function () use ($w, &$log) {
            try {
                $w->write('second');
                $log[] = 'wrote second';
            } catch (Throwable $e) {
                $log[] = 'writer got ' . $e::class;
            }
        });
        phasync::sleep(0.01);
        phasync::cancel($writer);
        phasync::await($writer);
        $log[] = 'read ' . $r->read();
        try {
            $r->read(0.05);
        } catch (Throwable $e) {
            $log[] = 'second read ' . $e::class;
        }

        return $log;
    });

    expect($log)->toBe([
        'writer got ' . CancelledException::class,
        'read first',
        'second read phasync\TimeoutException',
    ]);
});

// ---------------------------------------------------------------------------
// CAN-7: cancelling one waiter does not disturb the others
// ---------------------------------------------------------------------------

test('CAN-7: cancelling one of two flag waiters leaves the other waiting and it is woken by raiseFlag()', function () {
    $result = phasync::run(static function () {
        $flag = new stdClass();
        $log  = [];
        $a    = phasync::go(static function () use ($flag, &$log) {
            try {
                phasync::awaitFlag($flag);
                $log[] = 'a woke';
            } catch (Throwable $e) {
                $log[] = 'a ' . $e::class;
            }
        });
        $b = phasync::go(static function () use ($flag, &$log) {
            try {
                phasync::awaitFlag($flag);
                $log[] = 'b woke';
            } catch (Throwable $e) {
                $log[] = 'b ' . $e::class;
            }
        });
        phasync::sleep(0.01);
        phasync::cancel($a);
        phasync::sleep(0.01);
        $woken = phasync::raiseFlag($flag);
        phasync::await($a);
        phasync::await($b);

        return [$log, $woken];
    });

    expect($result)->toBe([['a ' . CancelledException::class, 'b woke'], 1]);
});

test('CAN-7: cancelling one of two waiters on the same stream leaves the other waiting', function () {
    $log = phasync::run(static function () {
        [$a, $b] = canPair();
        $log     = [];
        $first   = phasync::go(static function () use ($a, &$log) {
            try {
                phasync::readable($a);
                $log[] = 'first readable';
            } catch (Throwable $e) {
                $log[] = 'first ' . $e::class;
            }
        });
        $second = phasync::go(static function () use ($a, &$log) {
            try {
                phasync::readable($a);
                $log[] = 'second readable';
            } catch (Throwable $e) {
                $log[] = 'second ' . $e::class;
            }
        });
        phasync::sleep(0.01);
        phasync::cancel($first);
        \fwrite($b, 'x');
        phasync::await($first);
        phasync::await($second);

        return $log;
    });

    expect($log)->toBe(['first ' . CancelledException::class, 'second readable']);
});

test('CAN-7: cancelling one of two awaiters of the same coroutine leaves the other awaiting', function () {
    $result = phasync::run(static function () {
        $target = phasync::go(static function () {
            phasync::sleep(0.1);

            return 'target result';
        });
        $log = [];
        $a   = phasync::go(static function () use ($target, &$log) {
            try {
                $log[] = 'a ' . phasync::await($target);
            } catch (Throwable $e) {
                $log[] = 'a ' . $e::class;
            }
        });
        $b = phasync::go(static function () use ($target, &$log) {
            try {
                $log[] = 'b ' . phasync::await($target);
            } catch (Throwable $e) {
                $log[] = 'b ' . $e::class;
            }
        });
        phasync::sleep(0.01);
        phasync::cancel($a);
        phasync::await($a);
        phasync::await($b);

        return [$log, phasync::await($target)];
    });

    expect($result)->toBe([['a ' . CancelledException::class, 'b target result'], 'target result']);
});

// ---------------------------------------------------------------------------
// CAN-8: cancellation cannot be forced
// ---------------------------------------------------------------------------

test('CAN-8: a coroutine may swallow CancelledException and continue, and the awaiter waits for it', function () {
    $result = phasync::run(static function () {
        $start = \microtime(true);
        $child = phasync::go(static function () {
            try {
                phasync::sleep(5);
            } catch (Throwable) {
                // swallowed on purpose
            }
            phasync::sleep(0.2);

            return 'finished after swallowing';
        });
        phasync::cancel($child);
        $value = phasync::await($child);

        return [$value, \microtime(true) - $start >= 0.19];
    });

    expect($result)->toBe(['finished after swallowing', true]);
});

test('CAN-8: finally blocks run when a coroutine is cancelled', function () {
    $log = phasync::run(static function () {
        $log   = [];
        $child = phasync::go(static function () use (&$log) {
            try {
                phasync::sleep(5);
            } finally {
                $log[] = 'finally ran';
            }
        });
        phasync::cancel($child);
        try {
            phasync::await($child);
        } catch (CancelledException) {
            $log[] = 'awaiter got CancelledException';
        }

        return $log;
    });

    expect($log)->toBe(['finally ran', 'awaiter got CancelledException']);
});

// ---------------------------------------------------------------------------
// CAN-9: ending with the cancellation you were given
// ---------------------------------------------------------------------------

test('CAN-9: run() throws CancelledException when a coroutine cancelled on purpose does not catch it [DIVERGENCE]', function () {
    // The contract (CAN-9) says this is normal termination and run() must not throw.
    // Decision D12 is open.
    $thrown = null;
    try {
        phasync::run(static function () {
            $child = phasync::go(static fn () => phasync::sleep(5));
            phasync::cancel($child);
        });
    } catch (Throwable $e) {
        $thrown = $e::class;
    }

    expect($thrown)->toBe(CancelledException::class);
})->group('divergence');

test('CAN-9: run() throws a custom cancellation exception the child did not catch [DIVERGENCE]', function () {
    $thrown = null;
    try {
        phasync::run(static function () {
            $child = phasync::go(static fn () => phasync::sleep(5));
            phasync::cancel($child, new DomainException('boom'));
        });
    } catch (Throwable $e) {
        $thrown = $e::class . ':' . $e->getMessage();
    }

    expect($thrown)->toBe('DomainException:boom');
})->group('divergence');

test('CAN-9: an awaiter of the cancelled coroutine sees CancelledException', function () {
    $seen = phasync::run(static function () {
        $child = phasync::go(static fn () => phasync::sleep(5));
        phasync::cancel($child);
        try {
            phasync::await($child);
        } catch (Throwable $e) {
            return $e::class;
        }

        return 'no exception';
    });

    expect($seen)->toBe(CancelledException::class);
});

test('CAN-9: when the cancelled coroutine is awaited and the exception handled, run() returns normally', function () {
    $result = phasync::run(static function () {
        $child = phasync::go(static fn () => phasync::sleep(5));
        phasync::cancel($child);
        try {
            phasync::await($child);
        } catch (CancelledException) {
            return 'handled';
        }
    });

    expect($result)->toBe('handled');
});

test('CAN-9: a cancelled coroutine that catches CancelledException lets run() return normally', function () {
    $result = phasync::run(static function () {
        $child = phasync::go(static function () {
            try {
                phasync::sleep(5);
            } catch (CancelledException) {
                return 'cleaned';
            }
        });
        phasync::cancel($child);

        return phasync::await($child);
    });

    expect($result)->toBe('cleaned');
});
