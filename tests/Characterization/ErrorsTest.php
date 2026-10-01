<?php

/*
 * Characterization tests for docs/SEMANTICS.md section 4 (ERR-1..6). These pin how
 * phasync behaves TODAY. See tests/Characterization/README.md before changing any of them.
 */

use phasync\CancelledException;
use phasync\RethrowExceptionInterface;
use phasync\TimeoutException;

uses()->group('characterization');

beforeEach(function () {
    // phasync logs nothing; error_log() is captured to show that.
    $this->errLogFile     = \tempnam(\sys_get_temp_dir(), 'phasync-errlog');
    $this->previousErrLog = \ini_set('error_log', $this->errLogFile);
});
afterEach(function () {
    \ini_set('error_log', false === $this->previousErrLog ? '' : $this->previousErrLog);
    @\unlink($this->errLogFile);
});

/**
 * Runs $body in phasync::run(), returning how it ended: 'ok:<value>' or 'threw ClassName: message'.
 */
function errOutcome(Closure $body): string
{
    try {
        $value = phasync::run($body);

        return 'ok:' . \var_export($value, true);
    } catch (Throwable $e) {
        return 'threw ' . \get_class($e) . ': ' . $e->getMessage();
    }
}

/**
 * The text logged through error_log() by phasync::logUnhandledException().
 */
function errLogged(object $test): string
{
    \clearstatcache();

    return (string) \file_get_contents($test->errLogFile);
}

// ---------------------------------------------------------------------------
// ERR-1  await rethrows, every time, for every awaiter
// ---------------------------------------------------------------------------

test('ERR-1: every awaiter, however many, gets the same exception instance', function () {
    $caught = [];
    phasync::run(function () use (&$caught) {
        $child = phasync::go(function () {
            phasync::sleep(0.01);
            throw new RuntimeException('x');
        });
        foreach ([1, 2, 3] as $unused) {
            phasync::go(function () use ($child, &$caught) {
                try {
                    phasync::await($child);
                } catch (Throwable $e) {
                    $caught[] = $e;
                }
            });
        }
        foreach ([1, 2] as $unused) {
            try {
                phasync::await($child);
            } catch (Throwable $e) {
                $caught[] = $e;
            }
        }
    });
    expect($caught)->toHaveCount(5);
    expect(\count(\array_unique(\array_map('spl_object_id', $caught))))->toBe(1);
    expect($caught[0]->getMessage())->toBe('x');
});

test('ERR-1: await() of an already terminated failed coroutine rethrows the same exception again', function () {
    $r = phasync::run(function () {
        $child = phasync::go(function () {
            throw new LogicException('t');
        });
        $first = $second = null;
        try {
            phasync::await($child);
        } catch (LogicException $e) {
            $first = $e;
        }
        try {
            phasync::await($child);
        } catch (LogicException $e) {
            $second = $e;
        }

        return $first === $second && null !== $first;
    });
    expect($r)->toBeTrue();
});

test('ERR-1: await() returns the same result every time', function () {
    $r = phasync::run(function () {
        $child = phasync::go(function () {
            phasync::sleep(0.01);

            return 42;
        });

        return [phasync::await($child), phasync::await($child)];
    });
    expect($r)->toBe([42, 42]);
});

// ---------------------------------------------------------------------------
// ERR-2  An awaited failure is handled
// ---------------------------------------------------------------------------

test('ERR-2: a failure that was awaited and caught does not reach run()', function () {
    $outcome = errOutcome(function () {
        $c = phasync::go(function () {
            phasync::sleep(0.01);
            throw new RuntimeException('handled');
        });
        try {
            phasync::await($c);
        } catch (RuntimeException) {
        }

        return 'fine';
    });
    expect($outcome)->toBe("ok:'fine'");
    expect(errLogged($this))->toBe('');
});

test('ERR-2: a failure awaited (and caught) only by a sibling coroutine does not reach run()', function () {
    $outcome = errOutcome(function () {
        $c = phasync::go(function () {
            phasync::sleep(0.01);
            throw new RuntimeException('handled by sibling');
        });
        phasync::go(function () use ($c) {
            try {
                phasync::await($c);
            } catch (RuntimeException) {
            }
        });

        return 'fine';
    });
    expect($outcome)->toBe("ok:'fine'");
    expect(errLogged($this))->toBe('');
});

test('ERR-2: a failure that terminated before it was awaited, then awaited and caught, does not reach run()', function () {
    $outcome = errOutcome(function () {
        $c = phasync::go(function () {
            throw new RuntimeException('early');
        });
        phasync::sleep(0.02);
        try {
            phasync::await($c);
        } catch (RuntimeException) {
        }

        return 'fine';
    });
    expect($outcome)->toBe("ok:'fine'");
    expect(errLogged($this))->toBe('');
});

test('ERR-2: a failure that is awaited and rethrown surfaces from run() once, and is not logged', function () {
    $outcome = errOutcome(function () {
        $c = phasync::go(function () {
            phasync::sleep(0.01);
            throw new RuntimeException('rethrown');
        });
        phasync::await($c);

        return 'unreachable';
    });
    expect($outcome)->toBe('threw RuntimeException: rethrown');
    expect(errLogged($this))->toBe('');
});

// ---------------------------------------------------------------------------
// ERR-3  An un-awaited failure reaches the scope deterministically
// ---------------------------------------------------------------------------

test('ERR-3: an un-awaited failure with no handler fails the run: its coroutines, the parent too, are cancelled, and run() throws the failure', function () {
    $log     = [];
    $outcome = errOutcome(function () use (&$log) {
        phasync::go(function () {
            phasync::sleep(0.01);
            throw new RuntimeException('unawaited');
        });
        try {
            phasync::sleep(0.05);
            $log[] = 'parent slept fully';
        } catch (Throwable $e) {
            $log[] = 'parent interrupted by ' . $e->getMessage();
        }
        $log[] = 'parent end';

        return 'parent value';
    });
    // Cancelled with the failure as the reason; it caught that, so it finished
    expect($log)->toBe(['parent interrupted by Cancelled: the run failed with RuntimeException: unawaited', 'parent end']);
    expect($outcome)->toBe('threw RuntimeException: unawaited');
});

test('ERR-3: awaiting some other coroutine is interrupted by an un-awaited failure too', function () {
    $log     = [];
    $outcome = errOutcome(function () use (&$log) {
        phasync::go(function () {
            throw new RuntimeException('u2');
        });
        $c     = phasync::go(function () {
            phasync::sleep(0.02);

            return 'v';
        });
        $log[] = 'await got ' . phasync::await($c);

        return 'ret';
    });
    expect($log)->toBe([]);
    expect($outcome)->toBe('threw RuntimeException: u2');
});

test('ERR-3: a failed coroutine whose Fiber object is kept past its run() fails that run(), and can not be awaited after it', function () {
    $keep    = null;
    $outcome = errOutcome(function () use (&$keep) {
        $keep = phasync::go(function () {
            throw new RuntimeException('retained');
        });
        phasync::sleep(0.02);

        return 'ret';
    });
    expect($outcome)->toBe('threw RuntimeException: retained'); // the run owns its coroutines' failures
    expect(fn () => phasync::await($keep))->toThrow(LogicException::class, "Can't await a coroutine whose failure ended its run()");
    $keep = null; // nothing more is thrown when it goes
});

test('ERR-3: the same failure is delivered when the Fiber object is not retained', function () {
    // Counterpart of the test above: identical code, but the Fiber is a temporary.
    $outcome = errOutcome(function () {
        phasync::go(function () {
            throw new RuntimeException('not retained');
        });
        phasync::sleep(0.02);

        return 'ret';
    });
    expect($outcome)->toBe('threw RuntimeException: not retained');
})->group('surprise');

test('ERR-3: a failure inside a nested run() surfaces from that nested run(), in the coroutine that called it', function () {
    $outcome = errOutcome(function () {
        return phasync::run(function () {
            phasync::go(function () {
                throw new RuntimeException('inner');
            });

            return 'inner value';
        });
    });
    expect($outcome)->toBe('threw RuntimeException: inner');
});

// ---------------------------------------------------------------------------
// ERR-4  No failure is dropped silently (two are, today)
// ---------------------------------------------------------------------------

test('ERR-4: the first un-awaited failure cancels the scope before a later one happens; run() throws the first', function () {
    $outcome = errOutcome(function () {
        phasync::go(function () {
            phasync::sleep(0.01);
            throw new RuntimeException('c1');
        });
        phasync::go(function () {
            phasync::sleep(0.02);
            throw new RuntimeException('c2');
        });
        phasync::sleep(0.05);
    });
    expect($outcome)->toBe('threw RuntimeException: c1');
    expect(errLogged($this))->not->toContain('RuntimeException: c2'); // cancelled before it failed
});

test('ERR-4: two failures in the same tick: both in the AggregateException, the first first', function () {
    $outcome = errOutcome(function () {
        phasync::go(function () {
            throw new RuntimeException('cA');
        });
        phasync::go(function () {
            throw new RuntimeException('cB');
        });
    });
    expect($outcome)->toBe('threw phasync\\AggregateException: 2 failures, the first: cA');
});

test('ERR-4: when the main coroutine fails as well, both are thrown: the main coroutine\'s first', function () {
    $outcome = errOutcome(function () {
        phasync::go(function () {
            throw new RuntimeException('child failure');
        });
        throw new RuntimeException('main failure');
    });
    expect($outcome)->toBe('threw phasync\\AggregateException: 2 failures, the first: main failure');
});

test('ERR-4: every failure is in the AggregateException, the first as its previous exception', function () {
    $e = null;
    try {
        phasync::run(function () {
            phasync::go(function () {
                throw new RuntimeException('log-a');
            });
            phasync::go(function () {
                throw new RuntimeException('log-b');
            });
        });
    } catch (phasync\AggregateException $e) {
    }
    expect(\array_map(fn ($x) => $x->getMessage(), $e->getExceptions()))->toBe(['log-a', 'log-b']);
    expect($e->getPrevious()->getMessage())->toBe('log-a');
});

// ---------------------------------------------------------------------------
// ERR-5  finally always runs
// ---------------------------------------------------------------------------

test('ERR-5: a finally block runs when the coroutine is cancelled, and the awaiter receives CancelledException', function () {
    $log = [];
    phasync::run(function () use (&$log) {
        $c = phasync::go(function () use (&$log) {
            try {
                phasync::sleep(1);
            } finally {
                $log[] = 'finally';
            }
        });
        phasync::sleep(0.01);
        phasync::cancel($c);
        try {
            phasync::await($c);
        } catch (Throwable $e) {
            $log[] = 'awaiter got ' . \get_class($e);
        }
    });
    expect($log)->toBe(['finally', 'awaiter got phasync\CancelledException']);
});

test('ERR-5: a finally block runs when an exception is thrown into the suspended coroutine via cancel($fiber, $exception)', function () {
    $log = [];
    phasync::run(function () use (&$log) {
        $c = phasync::go(function () use (&$log) {
            try {
                phasync::sleep(1);
            } finally {
                $log[] = 'finally';
            }
        });
        phasync::sleep(0.01);
        phasync::cancel($c, new DomainException('custom'));
        try {
            phasync::await($c);
        } catch (Throwable $e) {
            $log[] = 'awaiter got ' . \get_class($e) . ': ' . $e->getMessage();
        }
    });
    expect($log)->toBe(['finally', 'awaiter got DomainException: custom']);
});

test('ERR-5: finally blocks of a cancelled coroutine run, but a wait in them is cancelled too; phasync::finally() callbacks complete', function () {
    $log = [];
    phasync::run(function () use (&$log) {
        $c = phasync::go(function () use (&$log) {
            phasync::finally(function () use (&$log) {
                phasync::sleep(0.01);
                $log[] = 'finally() callback end';
            });
            try {
                phasync::sleep(1);
            } finally {
                $log[] = 'cleanup start';
                phasync::sleep(0.01);
                $log[] = 'cleanup end';
            }
        });
        phasync::sleep(0.01);
        phasync::cancel($c);
        try {
            phasync::await($c);
        } catch (Throwable) {
        }
        $log[] = 'parent continues';
        phasync::sleep(0.05);
    });
    expect($log)->toBe(['cleanup start', 'parent continues', 'finally() callback end']);
});

test('ERR-5: phasync::finally() callbacks run last-registered-first, after the coroutine has failed and before an awaiter sees it', function () {
    $log = [];
    phasync::run(function () use (&$log) {
        $c = phasync::go(function () use (&$log) {
            phasync::finally(function () use (&$log) {
                $log[] = 'f1';
            });
            phasync::finally(function () use (&$log) {
                $log[] = 'f2';
            });
            $log[] = 'body';
            phasync::sleep(0.01);
            throw new RuntimeException('fail');
        });
        try {
            phasync::await($c);
        } catch (Throwable) {
            $log[] = 'awaiter caught';
        }
        phasync::sleep(0.01);
    });
    expect($log)->toBe(['body', 'f2', 'f1', 'awaiter caught']);
});

test('ERR-5: phasync::finally() callbacks run after a coroutine that completed normally', function () {
    $log = [];
    phasync::run(function () use (&$log) {
        phasync::go(function () use (&$log) {
            phasync::finally(function () use (&$log) {
                $log[] = 'finally callback';
            });
            $log[] = 'body';
        });
        $log[] = 'parent';
    });
    expect($log)->toBe(['body', 'parent', 'finally callback']);
});

test('ERR-4: a coroutine that registered a finally() callback still has its failure reach run()', function () {
    $log = [];
    expect(function () use (&$log) {
        phasync::run(function () use (&$log) {
            phasync::go(function () use (&$log) {
                phasync::finally(function () use (&$log) {
                    $log[] = 'finally callback';
                });
                phasync::sleep(0.01);
                throw new RuntimeException('boom');
            });
        });
    })->toThrow(RuntimeException::class, 'boom');
    expect($log)->toBe(['finally callback']);
});

test('ERR-5: phasync::finally() outside a coroutine throws LogicException', function () {
    expect(fn () => phasync::finally(fn () => null))->toThrow(LogicException::class);
});

// ---------------------------------------------------------------------------
// ERR-6  Exceptions are not used for flow control, except Cancelled and Timeout
// ---------------------------------------------------------------------------

test('ERR-6: cancel() throws CancelledException("Operation cancelled") into the coroutine', function () {
    $seen = null;
    phasync::run(function () use (&$seen) {
        $c = phasync::go(function () use (&$seen) {
            try {
                phasync::sleep(1);
            } catch (Throwable $e) {
                $seen = [\get_class($e), $e->getMessage()];
            }
        });
        phasync::sleep(0.01);
        phasync::cancel($c);
    });
    expect($seen)->toBe([CancelledException::class, 'Operation cancelled']);
});

test('ERR-6: cancel($fiber, $exception) throws that very exception instance into the coroutine', function () {
    $sent = new DomainException('custom');
    $seen = null;
    phasync::run(function () use (&$seen, $sent) {
        $c = phasync::go(function () use (&$seen) {
            try {
                phasync::sleep(1);
            } catch (Throwable $e) {
                $seen = $e;
            }
        });
        phasync::sleep(0.01);
        phasync::cancel($c, $sent);
    });
    expect($seen)->toBe($sent);
});

test('ERR-6: an expired timeout throws TimeoutException, whose message names the waiting coroutine', function () {
    $seen = null;
    phasync::run(function () use (&$seen) {
        try {
            heldFlagWait(0.01);
        } catch (Throwable $e) {
            $seen = [\get_class($e), $e->getMessage()];
        }
    });
    expect($seen[0])->toBe(TimeoutException::class);
    expect($seen[1])->toStartWith('Operation timed out for Fiber');
});

test('ERR-6: CancelledException and TimeoutException are RuntimeExceptions; CancelledException is also a RethrowExceptionInterface', function () {
    expect(\is_subclass_of(CancelledException::class, RuntimeException::class))->toBeTrue();
    expect(\is_subclass_of(TimeoutException::class, RuntimeException::class))->toBeTrue();
    expect(\is_subclass_of(CancelledException::class, RethrowExceptionInterface::class))->toBeTrue();
});

test('ERR-6: ordinary coordination (sleep, yield, go, await, flags) throws nothing and logs nothing', function () {
    $outcome = errOutcome(function () {
        $flag  = new stdClass();
        $child = phasync::go(function () use ($flag) {
            phasync::awaitFlag($flag);

            return 'child';
        });
        phasync::sleep(0.005);
        phasync::yield();
        phasync::raiseFlag($flag);

        return phasync::await($child);
    });
    expect($outcome)->toBe("ok:'child'");
    expect(errLogged($this))->toBe('');
});
