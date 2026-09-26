<?php

/*
 * Characterization tests for channels (CHN-*) and publishers (PUB-1).
 * They pin today's behaviour. See tests/Characterization/README.md before editing.
 * A failing test here means: stop and tell the maintainer.
 */

use phasync\ChannelException;
use phasync\TimeoutException;

uses()->group('characterization');

// ---------------------------------------------------------------------------
// CHN-1  Unbuffered channel
// ---------------------------------------------------------------------------

test('CHN-1: unbuffered write() returns only after a reader has read the value', function () {
    // Fixed: isReadyForWrite() was inverted for the unbuffered case (returned
    // $hasPendingWrite instead of !$hasPendingWrite), so write() returned the instant it
    // queued a value instead of waiting for a reader to consume it. Now a true rendezvous.
    $log = phasync::run(function () {
        phasync::channel($r, $w, 0);
        $log    = [];
        $writer = phasync::go(function () use ($w, &$log) {
            $w->write('a');
            $log[] = 'writer returned';
        });
        $reader = phasync::go(function () use ($r, &$log) {
            phasync::sleep(0.05);
            $log[] = 'reader reading';
            $log[] = 'reader got ' . $r->read();
        });
        phasync::await($writer);
        phasync::await($reader);

        return $log;
    });

    expect($log)->toBe(['reader reading', 'reader got a', 'writer returned']);
});

test('CHN-1: unbuffered write() with a timeout and no reader throws TimeoutException', function () {
    $result = phasync::run(function () {
        phasync::channel($r, $w, 0);
        $r->activate();
        $writer = phasync::go(function () use ($w) {
            try {
                $w->write(1, 0.05);

                return 'returned';
            } catch (Throwable $e) {
                return \get_class($e);
            }
        });

        return phasync::await($writer);
    });

    expect($result)->toBe(TimeoutException::class);
});

test('CHN-1: an unbuffered channel holds one pending value, a second writer blocks until it is read', function () {
    $log = phasync::run(function () {
        phasync::channel($r, $w, 0);
        $log = [];
        $a   = phasync::go(function () use ($w, &$log) {
            $w->write('A');
            $log[] = 'A written';
        });
        $b = phasync::go(function () use ($w, &$log) {
            $w->write('B');
            $log[] = 'B written';
        });
        $reader = phasync::go(function () use ($r, &$log) {
            phasync::sleep(0.03);
            $log[] = 'read ' . $r->read();
            phasync::sleep(0.03);
            $log[] = 'read ' . $r->read();
        });
        phasync::await($a);
        phasync::await($b);
        phasync::await($reader);

        return $log;
    });

    // Each writer's own "X written" now logs only after its value has actually been
    // read (true rendezvous), not right after being queued.
    expect($log)->toBe(['read A', 'A written', 'read B', 'B written']);
});

test('CHN-1: isReady() on the write end of an unbuffered channel is true only while no write is pending', function () {
    // Write-then-read in the SAME fiber (the original form of this test) is a genuine
    // self-deadlock now that write() is a true rendezvous: write() can't return until a
    // reader calls read(), but read() can't run until write() returns first. Needs two
    // fibers, like every other CHN-1 test above.
    $out = phasync::run(function () {
        phasync::channel($r, $w, 0);
        $out                      = [];
        $out['write end, empty']  = $w->isReady();
        $out['read end, empty']   = $r->isReady();

        $writer = phasync::go(function () use ($w) {
            $w->write('x');
        });
        phasync::sleep(0.01); // let the writer register its pending value
        $out['write end, pending'] = $w->isReady();
        $out['read end, pending']  = $r->isReady();

        $r->read();
        phasync::await($writer);
        $out['write end, consumed'] = $w->isReady();
        $out['read end, consumed']  = $r->isReady();

        return $out;
    });

    expect($out)->toBe([
        'write end, empty'    => true,
        'read end, empty'     => false,
        'write end, pending'  => false,
        'read end, pending'   => true,
        'write end, consumed' => true,
        'read end, consumed'  => false,
    ]);
});

// ---------------------------------------------------------------------------
// CHN-2  Buffered channel
// ---------------------------------------------------------------------------

test('CHN-2: a buffered channel accepts writes up to its capacity, then blocks until a read', function () {
    $log = phasync::run(function () {
        phasync::channel($r, $w, 2);
        $log    = [];
        $writer = phasync::go(function () use ($w, &$log) {
            foreach ([1, 2, 3] as $i) {
                $w->write($i);
                $log[] = "wrote $i";
            }
            $w->close();
        });
        $reader = phasync::go(function () use ($r, &$log) {
            phasync::sleep(0.05);
            $log[] = 'reader start';
            foreach ($r as $v) {
                $log[] = "read $v";
            }
        });
        phasync::await($writer);
        phasync::await($reader);

        return $log;
    });

    expect($log)->toBe(['wrote 1', 'wrote 2', 'reader start', 'read 1', 'wrote 3', 'read 2', 'read 3']);
});

test('CHN-2: writing to a full buffered channel with a timeout throws TimeoutException', function () {
    [$class, $message, $elapsed] = phasync::run(function () {
        phasync::channel($r, $w, 1);
        $r->activate();
        $c = phasync::go(function () use ($w) {
            $w->write(1);
            $t = \microtime(true);
            try {
                $w->write(2, 0.05);
            } catch (Throwable $e) {
                return [\get_class($e), $e->getMessage(), \microtime(true) - $t];
            }
        });

        return phasync::await($c);
    });

    expect($class)->toBe(TimeoutException::class);
    expect($message)->toBe('Channel write operation timed out');
    // Timeouts never fire early; they may fire late (see TMO-3)
    expect($elapsed)->toBeGreaterThanOrEqual(0.049)->toBeLessThan(3.0);
});

// ---------------------------------------------------------------------------
// CHN-3  FIFO, exactly once
// ---------------------------------------------------------------------------

test('CHN-3: values arrive in write order to a single reader', function () {
    $got = phasync::run(function () {
        phasync::channel($r, $w, 4);
        $writer = phasync::go(function () use ($w) {
            for ($i = 0; $i < 50; ++$i) {
                $w->write($i);
            }
            $w->close();
        });
        $reader = phasync::go(function () use ($r) {
            $got = [];
            foreach ($r as $v) {
                $got[] = $v;
            }

            return $got;
        });
        phasync::await($writer);

        return phasync::await($reader);
    });

    expect($got)->toBe(\range(0, 49));
});

test('CHN-3: with several readers every value is delivered exactly once, in order per reader', function () {
    $got = phasync::run(function () {
        phasync::channel($r, $w, 0);
        $got     = [0 => [], 1 => [], 2 => []];
        $readers = [];
        foreach ([0, 1, 2] as $n) {
            $readers[] = phasync::go(function () use ($r, $n, &$got) {
                while (null !== ($v = $r->read())) {
                    $got[$n][] = $v;
                    phasync::sleep(0.001);
                }
            });
        }
        $writer = phasync::go(function () use ($w) {
            for ($i = 0; $i < 30; ++$i) {
                $w->write($i);
            }
            $w->close();
        });
        phasync::await($writer);
        foreach ($readers as $reader) {
            phasync::await($reader);
        }

        return $got;
    });

    $all = \array_merge(...\array_values($got));
    \sort($all);
    expect($all)->toBe(\range(0, 29));
    foreach ($got as $mine) {
        $sorted = $mine;
        \sort($sorted);
        expect($mine)->toBe($sorted);
    }
});

// ---------------------------------------------------------------------------
// CHN-4  close()
// ---------------------------------------------------------------------------

test('CHN-4: close() is idempotent and closing either end closes the channel for both', function () {
    $out = phasync::run(function () {
        phasync::channel($r, $w, 1);
        $out   = [];
        $out[] = [$r->isClosed(), $w->isClosed()];
        $w->close();
        $w->close();
        $out[] = [$r->isClosed(), $w->isClosed()];

        phasync::channel($r2, $w2, 1);
        $r2->close();
        $r2->close();
        $out[] = [$r2->isClosed(), $w2->isClosed()];

        return $out;
    });

    expect($out)->toBe([[false, false], [true, true], [true, true]]);
});

// ---------------------------------------------------------------------------
// CHN-5  Drain after close, then end-of-stream
// ---------------------------------------------------------------------------

test('CHN-5: a closed buffered channel still delivers its buffered values, then returns null', function () {
    $out = phasync::run(function () {
        phasync::channel($r, $w, 3);
        $c = phasync::go(function () use ($r, $w) {
            $out = [];
            $w->write(1);
            $w->write(2);
            $w->close();
            $out[] = $r->isReadable();
            $out[] = $r->read();
            $out[] = $r->read();
            $out[] = $r->isReadable();
            $out[] = $r->read();
            $out[] = $r->read();

            return $out;
        });

        return phasync::await($c);
    });

    expect($out)->toBe([true, 1, 2, false, null, null]);
});

test('CHN-5: an unbuffered value that is pending when the channel closes is still delivered', function () {
    $out = phasync::run(function () {
        phasync::channel($r, $w, 0);
        $out    = [];
        $writer = phasync::go(function () use ($w, &$out) {
            $w->write('x');
            $out[] = 'write returned';
        });
        $reader = phasync::go(function () use ($r, $w, &$out) {
            phasync::sleep(0.02);
            $w->close();
            $out[] = 'read 1: ' . \var_export($r->read(), true);
            $out[] = 'read 2: ' . \var_export($r->read(), true);
        });
        phasync::await($writer);
        phasync::await($reader);

        return $out;
    });

    expect($out)->toBe(['write returned', "read 1: 'x'", 'read 2: NULL']);
});

test('CHN-5: a written null is read as null and does not close the channel', function () {
    $out = phasync::run(function () {
        phasync::channel($r, $w, 2);
        $c = phasync::go(function () use ($r, $w) {
            $w->write(null);
            $w->write('x');

            return [$r->read(), $r->isClosed(), $r->read()];
        });

        return phasync::await($c);
    });

    expect($out)->toBe([null, false, 'x']);
});

// The next two tests replaced a test that pinned foreach stopping at a written null and
// leaving the rest unread (D1 in SEMANTICS.md). Approved by the maintainer: read() now takes
// an optional &$eof out-parameter (true only when the channel is closed with nothing left,
// false otherwise, including when the returned value happens to be null), and getIterator()
// uses it, so a written null no longer terminates foreach early.
test('CHN-5: foreach over a ReadChannel now gets a written null too, not just the values before it', function () {
    $out = phasync::run(function () {
        phasync::channel($r, $w, 3);
        $c = phasync::go(function () use ($r, $w) {
            $w->write(1);
            $w->write(null);
            $w->write(2);
            $w->close();
            $seen = [];
            foreach ($r as $v) {
                $seen[] = $v;
            }

            return $seen;
        });

        return phasync::await($c);
    });

    expect($out)->toBe([1, null, 2]);
});

test('CHN-5: read($timeout, $eof) distinguishes a written null from end-of-stream', function () {
    $out = phasync::run(function () {
        phasync::channel($r, $w, 3);
        $w->write(1);
        $w->write(null);
        $w->close();

        $log   = [];
        $log[] = [$r->read(eof: $eof), $eof];
        $log[] = [$r->read(eof: $eof), $eof];
        $log[] = [$r->read(eof: $eof), $eof];

        return $log;
    });

    expect($out)->toBe([
        [1, false],
        [null, false],
        [null, true],
    ]);
});

// ---------------------------------------------------------------------------
// CHN-6  Write after close
// ---------------------------------------------------------------------------

test('CHN-6: writing to a closed channel throws ChannelException', function () {
    $out = phasync::run(function () {
        phasync::channel($r, $w, 1);
        $w->close();
        $c = phasync::go(function () use ($w) {
            try {
                $w->write(1);
            } catch (Throwable $e) {
                return [\get_class($e), $e->getMessage()];
            }
        });

        return phasync::await($c);
    });

    expect($out)->toBe([ChannelException::class, 'Channel is closed']);
});

test('CHN-6: a writer blocked on a full buffer wakes with ChannelException when the channel closes', function () {
    $out = phasync::run(function () {
        phasync::channel($r, $w, 1);
        $out    = [];
        $writer = phasync::go(function () use ($w, &$out) {
            $w->write(1);
            try {
                $w->write(2);
                $out[] = 'second write returned';
            } catch (Throwable $e) {
                $out[] = \get_class($e) . ': ' . $e->getMessage();
            }
        });
        $closer = phasync::go(function () use ($r, &$out) {
            phasync::sleep(0.03);
            $r->close();
            $out[] = 'closed by reader';
        });
        phasync::await($writer);
        phasync::await($closer);

        return $out;
    });

    expect($out)->toBe(['closed by reader', 'phasync\ChannelException: Channel is closed']);
});

// ---------------------------------------------------------------------------
// CHN-7  Close wakes blocked readers
// ---------------------------------------------------------------------------

test('CHN-7: closing the channel wakes every blocked reader with null', function () {
    $out = phasync::run(function () {
        phasync::channel($r, $w, 0);
        $out     = [];
        $readers = [];
        foreach ([1, 2, 3] as $i) {
            $readers[] = phasync::go(function () use ($r, $i, &$out) {
                $out[] = "reader $i: " . \var_export($r->read(), true);
            });
        }
        $closer = phasync::go(function () use ($w) {
            phasync::sleep(0.03);
            $w->close();
        });
        foreach ($readers as $reader) {
            phasync::await($reader);
        }
        phasync::await($closer);
        \sort($out);

        return $out;
    });

    expect($out)->toBe(['reader 1: NULL', 'reader 2: NULL', 'reader 3: NULL']);
});

// ---------------------------------------------------------------------------
// CHN-8  Dropping one end closes the other (refcount only)
// ---------------------------------------------------------------------------

test('CHN-8: dropping the read end makes a writer throw ChannelException', function () {
    $out = phasync::run(function () {
        phasync::channel($r, $w, 0);
        $writer = phasync::go(function () use ($w) {
            // go() may preempt its caller (wall-clock dependent), so wait long enough
            // for the read end to have been dropped.
            phasync::sleep(0.02);
            try {
                $w->write('x');

                return 'write returned';
            } catch (Throwable $e) {
                return \get_class($e) . ': ' . $e->getMessage();
            }
        });
        $r = null;

        return phasync::await($writer);
    });

    expect($out)->toBe('phasync\ChannelException: Channel is closed');
});

test('CHN-8: dropping the write end gives readers end-of-stream', function () {
    $out = phasync::run(function () {
        phasync::channel($r, $w, 0);
        $reader = phasync::go(function () use ($r) {
            return $r->read();
        });
        $w = null;
        unset($r);

        return phasync::await($reader);
    });

    expect($out)->toBeNull();
});

test('CHN-8: an end kept alive by a reference cycle is only closed by the cycle collector', function () {
    [$before, $after] = phasync::run(function () {
        phasync::channel($r, $w, 1);
        $holder       = new stdClass();
        $holder->r    = $r;
        $holder->self = $holder;
        $r            = null;
        $holder       = null;
        $before       = $w->isClosed();
        \gc_collect_cycles();

        return [$before, $w->isClosed()];
    });

    expect($before)->toBeFalse();
    expect($after)->toBeTrue();
});

// ---------------------------------------------------------------------------
// CHN-9  Values
// ---------------------------------------------------------------------------

test('CHN-9: scalars, arrays, null and Serializable objects pass through unchanged', function () {
    $out = phasync::run(function () {
        phasync::channel($r, $w, 6);
        $c = phasync::go(function () use ($r, $w) {
            $values = [1.5, false, [1, 'a' => 2], 0, '', new ArrayObject([1, 2])];
            foreach ($values as $v) {
                $w->write($v);
            }
            $out = [];
            foreach ($values as $ignored) {
                $out[] = $r->read();
            }

            return $out;
        });

        return phasync::await($c);
    });

    expect($out[0])->toBe(1.5);
    expect($out[1])->toBeFalse();
    expect($out[2])->toBe([1, 'a' => 2]);
    expect($out[3])->toBe(0);
    expect($out[4])->toBe('');
    expect($out[5])->toBeInstanceOf(ArrayObject::class);
});

test('CHN-9: any value -- including a non-Serializable object, a Closure and a resource -- round-trips through a channel unchanged (D2, 2.0.0)', function () {
    // Was CHN-9's divergence test: these four used to throw TypeError on write(). Fixed per
    // D2 (docs/roadmap-2.0.md's clustering section) -- a channel is single-process, in-memory
    // communication between coroutines, so there was never a serialization step to justify the
    // restriction; cross-process transport is a separate, later concern. Reads interleaved with
    // writes (capacity 1) rather than writing all four first: unlike the old TypeError-per-write
    // version of this test, every write here actually succeeds and occupies the one buffer slot,
    // so a second write before the matching read would block forever with nothing to drain it.
    $out = phasync::run(function () {
        phasync::channel($r, $w, 1);
        $c = phasync::go(function () use ($r, $w) {
            $tries = [
                'stdClass' => new stdClass(),
                'Closure'  => static fn () => 1,
                'DateTime' => new DateTime('2020-01-01'),
                'resource' => \STDIN,
            ];
            $out = [];
            foreach ($tries as $name => $value) {
                $w->write($value);
                $out[$name] = $r->read() === $value;
            }

            return $out;
        });

        return phasync::await($c);
    });

    expect($out)->toBe([
        'stdClass' => true,
        'Closure'  => true,
        'DateTime' => true,
        'resource' => true,
    ]);
});

// ---------------------------------------------------------------------------
// CHN-10  Timing heuristics inside Channel
// ---------------------------------------------------------------------------

test('CHN-10: the creator reading an empty channel with no other coroutine gets a "likely deadlock" ChannelException after about 100 ms [DIVERGENCE]', function () {
    // SEMANTICS DLK-1 / CHN-10 expect no time-based deadlock guessing.
    $t   = \microtime(true);
    $out = phasync::run(function () {
        phasync::channel($r, $w, 0);
        try {
            $r->read();
        } catch (Throwable $e) {
            return [\get_class($e), $e->getMessage()];
        }
    });
    $elapsed = \microtime(true) - $t;

    expect($out)->toBe([ChannelException::class, "Likely deadlock detected. Can't await a channel that has no known readers/writers."]);
    expect($elapsed)->toBeGreaterThanOrEqual(0.09)->toBeLessThan(3.0);
})->group('divergence');

test('CHN-10: the creator writing an unbuffered channel with no other coroutine gets the same ChannelException [DIVERGENCE]', function () {
    $out = phasync::run(function () {
        phasync::channel($r, $w, 0);
        try {
            $w->write(1);
        } catch (Throwable $e) {
            return \get_class($e);
        }

        return 'write returned';
    });

    expect($out)->toBe(ChannelException::class);
})->group('divergence');

test('CHN-10: the deadlock guess wins over a read timeout on the creator [DIVERGENCE]', function () {
    $out = phasync::run(function () {
        phasync::channel($r, $w, 0);
        try {
            $r->read(0.05);
        } catch (Throwable $e) {
            return \get_class($e);
        }
    });

    expect($out)->toBe(ChannelException::class);
})->group('divergence');

test('CHN-10: after activate() the creator blocks normally and a timeout throws TimeoutException', function () {
    [$class, $elapsed] = phasync::run(function () {
        phasync::channel($r, $w, 0);
        $r->activate();
        $t = \microtime(true);
        try {
            $r->read(0.1);
        } catch (Throwable $e) {
            return [\get_class($e), \microtime(true) - $t];
        }
    });

    expect($class)->toBe(TimeoutException::class);
    expect($elapsed)->toBeGreaterThanOrEqual(0.099)->toBeLessThan(3.0);
});

test('CHN-10: the creator may block if another coroutine uses the channel within the grace period', function () {
    $out = phasync::run(function () {
        phasync::channel($r, $w, 0);
        phasync::go(function () use ($w) {
            phasync::sleep(0.02);
            $w->write('hi');
        });

        return $r->read();
    });

    expect($out)->toBe('hi');
});

test('CHN-10: the creator can use a buffered channel without blocking', function () {
    $out = phasync::run(function () {
        phasync::channel($r, $w, 1);
        $w->write(1);

        return $r->read();
    });

    expect($out)->toBe(1);
});

test('CHN-10: even a non-blocking read() and write() let sibling coroutines run first [DIVERGENCE]', function () {
    // SEMANTICS CHN-10 expects no inserted phasync::sleep() calls. Every channel operation
    // starts with one, so a sibling gets at least one turn during each operation, even when
    // the buffer has room and data.
    [$writeTicks, $readTicks] = phasync::run(function () {
        phasync::channel($r, $w, 5);
        $ticks   = 0;
        $stop    = false;
        $sibling = phasync::go(function () use (&$ticks, &$stop) {
            while (!$stop) {
                ++$ticks;
                phasync::sleep(0);
            }
        });
        $c = phasync::go(function () use ($r, $w, &$ticks) {
            $before = $ticks;
            $w->write(1);
            $writeTicks = $ticks - $before;
            $before     = $ticks;
            $r->read();

            return [$writeTicks, $ticks - $before];
        });
        $result = phasync::await($c);
        $stop   = true;
        phasync::await($sibling);

        return $result;
    });

    expect($writeTicks)->toBeGreaterThanOrEqual(1);
    expect($readTicks)->toBeGreaterThanOrEqual(1);
})->group('divergence');

test('CHN-10: a read timeout on a child coroutine throws TimeoutException after at least the timeout', function () {
    [$class, $message, $elapsed] = phasync::run(function () {
        phasync::channel($r, $w, 0);
        $r->activate();
        $c = phasync::go(function () use ($r) {
            $t = \microtime(true);
            try {
                $r->read(0.05);
            } catch (Throwable $e) {
                return [\get_class($e), $e->getMessage(), \microtime(true) - $t];
            }
        });

        return phasync::await($c);
    });

    expect($class)->toBe(TimeoutException::class);
    expect($message)->toBe('Channel read operation timed out');
    expect($elapsed)->toBeGreaterThanOrEqual(0.049)->toBeLessThan(3.0);
});

test('CHN-10: channels cannot be created outside a coroutine', function () {
    $out = null;
    try {
        phasync::channel($r, $w, 1);
    } catch (Throwable $e) {
        $out = [\get_class($e), $e->getMessage()];
    }

    expect($out)->toBe([LogicException::class, 'This function can not be used outside of a coroutine']);
});

// ---------------------------------------------------------------------------
// PUB-1  Publisher / subscribers
// ---------------------------------------------------------------------------

// Found while implementing D1 for Subscriber: the message chain ends in a self-referencing
// sentinel node whose ->message is an uninitialized default (null), not a published value.
// The old code read that sentinel's message as if it were real on the call where the chain
// transitions to it, only recognizing end-of-stream on the *next* call -- indistinguishable
// from a genuinely published null (compounding D1's own ambiguity). Fixed: the transition to
// the sentinel is now recognized immediately, without ever returning its placeholder message.
test('PUB-1: a written null is a real message, not confused with the sentinel end-of-stream node', function () {
    $out = phasync::run(function () {
        phasync::publisher($subs, $pub);
        $c = phasync::go(function () use ($subs) {
            $sub  = $subs->subscribe();
            $seen = [];
            foreach ($sub as $v) {
                $seen[] = $v;
            }

            return $seen;
        });
        $pub->write(1);
        $pub->write(null);
        $pub->write(2);
        $pub->close();

        return phasync::await($c);
    });

    expect($out)->toBe([1, null, 2]);
});

test('PUB-1: read($timeout, $eof) on a subscriber distinguishes a written null from end-of-stream', function () {
    $out = phasync::run(function () {
        phasync::publisher($subs, $pub);
        $c = phasync::go(function () use ($subs) {
            $sub   = $subs->subscribe();
            $log   = [];
            $log[] = [$sub->read(eof: $eof), $eof];
            $log[] = [$sub->read(eof: $eof), $eof];
            $log[] = [$sub->read(eof: $eof), $eof];

            return $log;
        });
        $pub->write(1);
        $pub->write(null);
        $pub->close();

        return phasync::await($c);
    });

    expect($out)->toBe([
        [1, false],
        [null, false],
        [null, true],
    ]);
});

test('PUB-1: every subscriber receives every message in order, then end-of-stream', function () {
    $got = phasync::run(function () {
        phasync::publisher($subs, $pub);
        $got = ['a' => [], 'b' => []];
        $cs  = [];
        foreach (['a', 'b'] as $name) {
            $cs[] = phasync::go(function () use ($subs, $name, &$got) {
                foreach ($subs as $m) {
                    $got[$name][] = $m;
                }
            });
        }
        $cs[] = phasync::go(function () use ($pub) {
            foreach ([1, 2, 3] as $m) {
                $pub->write($m);
            }
            $pub->close();
        });
        foreach ($cs as $c) {
            phasync::await($c);
        }

        return $got;
    });

    expect($got)->toBe(['a' => [1, 2, 3], 'b' => [1, 2, 3]]);
});

test('PUB-1: the coroutine that created the publisher can subscribe, and receives what it publishes', function () {
    $out = phasync::run(function () {
        phasync::publisher($subs, $pub);
        $sub = $subs->subscribe();
        $pub->write('a');
        $pub->write('b');
        $out = [$sub->read(), $sub->read()];
        $pub->close();
        $out[] = $sub->read(eof: $eof);
        $out[] = $eof;

        return $out;
    });

    expect($out)->toBe(['a', 'b', null, true]);
});

test('PUB-1: a late subscriber only sees messages published after it subscribed', function () {
    $got = phasync::run(function () {
        phasync::publisher($subs, $pub);
        $got   = ['early' => [], 'late' => []];
        $early = phasync::go(function () use ($subs, &$got) {
            foreach ($subs as $m) {
                $got['early'][] = $m;
            }
        });
        $late = phasync::go(function () use ($subs, &$got) {
            phasync::sleep(0.05);
            foreach ($subs as $m) {
                $got['late'][] = $m;
            }
        });
        $publisher = phasync::go(function () use ($pub) {
            $pub->write('m1');
            $pub->write('m2');
            phasync::sleep(0.1);
            $pub->write('m3');
            $pub->close();
        });
        phasync::await($early);
        phasync::await($late);
        phasync::await($publisher);

        return $got;
    });

    expect($got)->toBe(['early' => ['m1', 'm2', 'm3'], 'late' => ['m3']]);
});

test('PUB-1: with no subscriber waiting, both writes succeed (the internal service is always a reader)', function () {
    // Subscribers' internal forwarding service reads from the underlying channel
    // unconditionally from construction (not only once a Subscriber is waiting), so it's
    // always available to rendezvous with a write(), regardless of whether any real
    // subscriber ever exists.
    $log = phasync::run(function () {
        phasync::publisher($subs, $pub);
        $log = [];
        $c   = phasync::go(function () use ($pub, &$log) {
            try {
                $pub->write(1);
                $log[] = 'write 1 returned';
                $pub->write(2, 0.1);
                $log[] = 'write 2 returned';
            } catch (Throwable $e) {
                $log[] = \get_class($e);
            }
        });
        phasync::await($c);
        $pub->close();

        return $log;
    });

    expect($log)->toBe(['write 1 returned', 'write 2 returned']);
});

test('PUB-1: a subscriber reads null once the publisher is closed and isClosed() turns true after the last message', function () {
    $out = phasync::run(function () {
        phasync::publisher($subs, $pub);
        $out        = [];
        $subscriber = phasync::go(function () use ($subs, &$out) {
            $sub   = $subs->subscribe();
            $out[] = $sub->read();
            $out[] = $sub->read();
            $out[] = $sub->isClosed();
            $out[] = $sub->read();
        });
        $publisher = phasync::go(function () use ($pub) {
            phasync::sleep(0.01);
            $pub->write('a');
            $pub->close();
        });
        phasync::await($subscriber);
        phasync::await($publisher);

        return $out;
    });

    expect($out)->toBe(['a', null, true, null]);
});

test('PUB-1: a subscriber read with a timeout throws TimeoutException', function () {
    $out = phasync::run(function () {
        phasync::publisher($subs, $pub);
        $subscriber = phasync::go(function () use ($subs) {
            $sub = $subs->subscribe();
            try {
                $sub->read(0.05);
            } catch (Throwable $e) {
                return \get_class($e);
            }
        });
        $keeper = phasync::go(function () use ($pub) {
            phasync::sleep(0.8);
            $pub->close();
        });
        $out = phasync::await($subscriber);
        phasync::await($keeper);

        return $out;
    });

    expect($out)->toBe(TimeoutException::class);
});

test('PUB-1: activate() on a subscriber throws RuntimeException', function () {
    $out = phasync::run(function () {
        phasync::publisher($subs, $pub);
        $subscriber = phasync::go(function () use ($subs) {
            try {
                $subs->subscribe()->activate();
            } catch (Throwable $e) {
                return \get_class($e);
            }
        });
        $pub->activate();
        $out = phasync::await($subscriber);
        $pub->close();

        return $out;
    });

    expect($out)->toBe(RuntimeException::class);
});
