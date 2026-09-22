<?php

/*
 * Characterization tests for phasync\Util\StringBuffer (docs/SEMANTICS.md section 11, BUF-1 .. BUF-6).
 *
 * These pin how StringBuffer behaves TODAY. A failing test means "stop and tell the
 * maintainer" (see tests/Characterization/README.md).
 *
 * BUF-5 ("shortcuts allowed") is a permission, not a behaviour, so it has no test here.
 */

use phasync\DeadmanException;
use phasync\TimeoutException;
use phasync\Util\StringBuffer;

uses()->group('characterization');

/**
 * Run PHP code in a separate process with a time limit, for behaviour that would
 * hang or kill the test process.
 *
 * @return array{stdout: string, timedOut: bool}
 */
function bufchar_child(string $code, float $limit): array
{
    $file = \tempnam(\sys_get_temp_dir(), 'bufchar');
    \file_put_contents($file, "<?php\nrequire " . \var_export(\dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ";\n" . $code);
    $process = \proc_open([\PHP_BINARY, '-d', 'display_errors=0', $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    \stream_set_blocking($pipes[1], false);
    \stream_set_blocking($pipes[2], false);
    $stdout   = '';
    $timedOut = false;
    $deadline = \microtime(true) + $limit;
    while (true) {
        $stdout .= \stream_get_contents($pipes[1]);
        if (!\proc_get_status($process)['running']) {
            break;
        }
        if (\microtime(true) > $deadline) {
            $timedOut = true;
            \proc_terminate($process, \SIGKILL);
            break;
        }
        \usleep(20000);
    }
    $stdout .= \stream_get_contents($pipes[1]);
    \fclose($pipes[1]);
    \fclose($pipes[2]);
    \proc_close($process);
    \unlink($file);

    return ['stdout' => \trim($stdout), 'timedOut' => $timedOut];
}

/**
 * @return array{0: resource, 1: resource}
 */
function bufchar_pair(): array
{
    return \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
}

/* ------------------------------------------------------------------ BUF-1 */

test('BUF-1: write() never blocks: megabytes can be written without a reader and read back', function () {
    phasync::run(function () {
        $buffer = new StringBuffer();
        $chunk  = \str_repeat('x', 1024);
        for ($i = 0; $i < 2000; ++$i) {
            $buffer->write($chunk);
        }
        expect($buffer->isEmpty())->toBeFalse();
        expect(\strlen($buffer->read(4000000)))->toBe(2000 * 1024);
        expect($buffer->isEmpty())->toBeTrue();
    });
});

test('BUF-1: write() lets other coroutines run when a slice has been used up (preempt), but does not wait for a reader', function () {
    phasync::run(function () {
        $buffer = new StringBuffer();
        $ticks  = 0;
        $stop   = false;
        $ticker = phasync::go(function () use (&$ticks, &$stop) {
            while (!$stop) {
                ++$ticks;
                phasync::yield();
            }
        });
        $until = \microtime(true) + 0.25;
        while (\microtime(true) < $until) {
            $buffer->write('x');
        }
        expect($ticks)->toBeGreaterThan(0);
        $stop = true;
        phasync::await($ticker);
    });
});

test('BUF-1: write() accepts an empty string', function () {
    phasync::run(function () {
        $buffer = new StringBuffer();
        $buffer->write('');
        expect($buffer->read(5, 0))->toBe('');
    });
});

test('BUF-1: write() works outside a coroutine', function () {
    $buffer = new StringBuffer();
    $buffer->write('outside');
    expect($buffer->isReady())->toBeTrue();
    expect($buffer->read(100))->toBe('outside');
});

test('BUF-1: a write() wakes a blocked reader', function () {
    phasync::run(function () {
        $buffer = new StringBuffer();
        $log    = [];
        $reader = phasync::go(function () use ($buffer, &$log) {
            $log[] = 'reader waits';
            $log[] = 'reader got ' . $buffer->read(100);
        });
        $log[] = 'writer writes';
        $buffer->write('data');
        phasync::await($reader);
        expect($log)->toBe(['reader waits', 'writer writes', 'reader got data']);
    });
});

/* ------------------------------------------------------------------ BUF-2 */

test('BUF-2: read($max) returns what is available up to $max, across chunks, without waiting to fill', function () {
    phasync::run(function () {
        $buffer = new StringBuffer();
        $buffer->write('ab');
        $buffer->write('cd');
        $buffer->write('ef');
        expect($buffer->read(5))->toBe('abcde');
        expect($buffer->read(5))->toBe('f');
    });
});

test('BUF-2: read() blocks while the buffer is empty and not ended', function () {
    phasync::run(function () {
        $buffer = new StringBuffer();
        $result = null;
        $reader = phasync::go(function () use ($buffer, &$result) {
            $result = $buffer->read(10);
        });
        phasync::sleep(0.05);
        expect($result)->toBeNull();
        $buffer->write('now');
        phasync::await($reader);
        expect($result)->toBe('now');
    });
});

test('BUF-2: read($max, 0) polls: it returns an empty string at once when nothing is available', function () {
    phasync::run(function () {
        $buffer = new StringBuffer();
        $start  = \microtime(true);
        expect($buffer->read(4, 0))->toBe('');
        expect(\microtime(true) - $start)->toBeLessThan(0.1);
    });
});

test('BUF-2: read($max, $timeout) returns data that arrives before the timeout', function () {
    phasync::run(function () {
        $buffer = new StringBuffer();
        phasync::go(function () use ($buffer) {
            phasync::sleep(0.05);
            $buffer->write('late');
        });
        expect($buffer->read(10, 1.0))->toBe('late');
    });
});

// These two tests replaced an earlier one that pinned the hang: read() with a timeout spun forever
// once the timeout expired, and no other coroutine could run. Approved by the maintainer: it now
// throws TimeoutException, as developers expect. (An infinite wait with no writer still blocks,
// StringBuffer is a fast primitive for protocol parsing and is not made safe.)
test('BUF-2: read($max, $timeout) on an empty buffer throws TimeoutException when the timeout expires', function () {
    phasync::run(function () {
        $buffer = new StringBuffer();
        $start  = \microtime(true);
        expect(static fn () => $buffer->read(4, 0.1))->toThrow(TimeoutException::class);
        expect(\microtime(true) - $start)->toBeGreaterThanOrEqual(0.1);
        expect(\microtime(true) - $start)->toBeLessThan(1.5);
    });
});

test('BUF-2: a writer that only arrives after the timeout is too late, the read throws and the data stays readable', function () {
    phasync::run(function () {
        $buffer = new StringBuffer();
        phasync::go(function () use ($buffer) {
            phasync::sleep(0.7);
            $buffer->write('late');
        });
        expect(static fn () => $buffer->read(4, 0.1))->toThrow(TimeoutException::class);
        expect($buffer->read(10, 3.0))->toBe('late');
    });
});

test('BUF-2: readFixed($n) waits until $n bytes are available', function () {
    phasync::run(function () {
        $buffer = new StringBuffer();
        $result = null;
        $reader = phasync::go(function () use ($buffer, &$result) {
            $result = $buffer->readFixed(6);
        });
        $buffer->write('abc');
        phasync::sleep(0.03);
        expect($result)->toBeNull();
        $buffer->write('def');
        phasync::await($reader);
        expect($result)->toBe('abcdef');
    });
});

// The next two tests replaced tests that pinned readFixed() returning null on a real timeout,
// ambiguous with the genuine end-of-stream case (D17 in SEMANTICS.md). Approved by the
// maintainer: readFixed() now throws TimeoutException on a real timeout, matching read(). A
// timeout of exactly 0 stays a non-blocking poll (BUF-2 below), never throwing.
test('BUF-2: readFixed($n, $timeout) throws TimeoutException on a real timeout, and leaves the data in the buffer', function () {
    phasync::run(function () {
        $buffer = new StringBuffer();
        $buffer->write('ab');
        $start = \microtime(true);
        expect(fn () => $buffer->readFixed(5, 0.1))->toThrow(TimeoutException::class);
        expect(\microtime(true) - $start)->toBeGreaterThanOrEqual(0.1);
        expect($buffer->read(10))->toBe('ab');
    });
});

test('BUF-2: readFixed() timeouts in an idle loop fire at the 0.5 s idle boundary', function () {
    // Same cause as TMO-3.
    phasync::run(function () {
        $buffer = new StringBuffer();
        $start  = \microtime(true);
        expect(fn () => $buffer->readFixed(4, 0.05))->toThrow(TimeoutException::class);
        expect(\microtime(true) - $start)->toBeGreaterThan(0.3)->toBeLessThan(1.2);
    });
});

test('BUF-2: readFixed($n, 0) is a non-blocking poll: returns null immediately if not enough data, never throws', function () {
    $buffer = new StringBuffer();
    $buffer->write('Hi');
    $start = \microtime(true);
    expect($buffer->readFixed(10, 0))->toBeNull();
    expect(\microtime(true) - $start)->toBeLessThan(0.05);
    expect($buffer->read(10))->toBe('Hi');
});

test('BUF-2: readFixed() returns null when the buffer ends with fewer bytes, and keeps them', function () {
    phasync::run(function () {
        $buffer = new StringBuffer();
        $buffer->write('abc');
        $buffer->end();
        expect($buffer->readFixed(5))->toBeNull();
        expect($buffer->readFixed(2))->toBe('ab');
        expect($buffer->readFixed(1))->toBe('c');
        expect($buffer->readFixed(1))->toBeNull();
        expect($buffer->readFixed(0))->toBe('');
    });
});

test('BUF-2: readFixed() serves fixed-size frames that span chunk boundaries', function () {
    phasync::run(function () {
        $buffer = new StringBuffer();
        $buffer->write('ab');
        $buffer->write('cd');
        expect($buffer->readFixed(3))->toBe('abc');
        expect($buffer->readFixed(1))->toBe('d');
    });
});

test('BUF-2: reading a negative length throws OutOfBoundsException', function () {
    $buffer = new StringBuffer();
    expect(fn () => $buffer->read(-1))->toThrow(OutOfBoundsException::class);
    expect(fn () => $buffer->readFixed(-1))->toThrow(OutOfBoundsException::class);
});

test('BUF-2: reading many small pieces across the compaction limit reassembles the data', function () {
    expect(StringBuffer::BUFFER_WASTE_LIMIT)->toBe(4096);
    $payload = \str_repeat('0123456789', 2000);
    $buffer  = new StringBuffer();
    $buffer->write($payload);
    $received = '';
    while (!$buffer->isEmpty()) {
        $received .= $buffer->read(7);
    }
    expect($received)->toBe($payload);
});

test('BUF-2: outside a coroutine, reads work while data is available and a blocking read throws LogicException', function () {
    $buffer = new StringBuffer();
    $buffer->write('hello');
    expect($buffer->read(3))->toBe('hel');
    expect($buffer->readFixed(2))->toBe('lo');
    expect($buffer->read(10, 0))->toBe('');
    expect(fn () => $buffer->readFixed(5, 0.1))->toThrow(LogicException::class, 'within a coroutine');
    expect(fn () => $buffer->read(10, 0.1))->toThrow(LogicException::class, 'within a coroutine');
});

/* ------------------------------------------------------------------ BUF-3 */

test('BUF-3: end() makes reads drain the remaining data and then return empty', function () {
    phasync::run(function () {
        $buffer = new StringBuffer();
        $buffer->write('tail');
        $buffer->end();
        expect($buffer->read(2))->toBe('ta');
        expect($buffer->eof())->toBeFalse();
        expect($buffer->read(5))->toBe('il');
        expect($buffer->eof())->toBeTrue();
        expect($buffer->read(5))->toBe('');
    });
});

test('BUF-3: writing after end() throws RuntimeException, and ending twice throws LogicException', function () {
    $buffer = new StringBuffer();
    $buffer->end();
    expect(fn () => $buffer->write('x'))->toThrow(RuntimeException::class, 'Buffer has been ended');
    expect(fn () => $buffer->end())->toThrow(LogicException::class, 'already ended');
});

test('BUF-3: end() wakes a blocked read() with an empty string and a blocked readFixed() with null', function () {
    phasync::run(function () {
        $readBuffer  = new StringBuffer();
        $fixedBuffer = new StringBuffer();
        $read        = 'unset';
        $fixed       = 'unset';
        $a           = phasync::go(function () use ($readBuffer, &$read) {
            $read = $readBuffer->read(10);
        });
        $b = phasync::go(function () use ($fixedBuffer, &$fixed) {
            $fixed = $fixedBuffer->readFixed(4);
        });
        phasync::sleep(0.02);
        $readBuffer->end();
        $fixedBuffer->end();
        phasync::await($a);
        phasync::await($b);
        expect($read)->toBe('');
        expect($fixed)->toBeNull();
    });
});

test('BUF-3: when the writer exits without end(), the reader gets the buffered data and then DeadmanException', function () {
    phasync::run(function () {
        $buffer  = new StringBuffer();
        $results = [];
        $reader  = phasync::go(function () use ($buffer, &$results) {
            try {
                $results[] = $buffer->read(10);
                $results[] = $buffer->read(10);
            } catch (Throwable $e) {
                $results[] = \get_class($e);
            }
        });
        $writer = phasync::go(function () use ($buffer) {
            $switch = $buffer->getDeadmanSwitch();
            $buffer->write('buffered');
            phasync::sleep(0.02);
            // leaves without end(); $switch is destroyed here
        });
        phasync::await($writer);
        phasync::await($reader);
        expect($results)->toBe(['buffered', DeadmanException::class]);
    });
});

test('BUF-3: after the deadman switch triggered, every blocking read throws, isReady() is true, eof() is false, and write() is still accepted', function () {
    phasync::run(function () {
        $buffer = new StringBuffer();
        $switch = $buffer->getDeadmanSwitch();
        $switch->trigger();
        expect($buffer->isReady())->toBeTrue();
        expect($buffer->eof())->toBeFalse();
        expect(fn () => $buffer->read(5))->toThrow(DeadmanException::class, 'Writer terminated unexpectedly');
        expect(fn () => $buffer->readFixed(5))->toThrow(DeadmanException::class);
        $buffer->write('more');
        expect($buffer->read(10))->toBe('more');
    });
});

test('BUF-3: getDeadmanSwitch() returns the same switch while it is alive', function () {
    $buffer = new StringBuffer();
    $switch = $buffer->getDeadmanSwitch();
    expect($buffer->getDeadmanSwitch())->toBe($switch);
});

test('BUF-3: a disarmed switch does not fail the buffer when it is destroyed', function () {
    phasync::run(function () {
        $buffer = new StringBuffer();
        $switch = $buffer->getDeadmanSwitch();
        $switch->disarm();
        unset($switch);
        expect($buffer->isReady())->toBeFalse();
    });
});

test('BUF-3: a switch that nobody holds triggers at once', function () {
    phasync::run(function () {
        $buffer = new StringBuffer();
        $buffer->getDeadmanSwitch();
        expect($buffer->isReady())->toBeTrue();
        expect(fn () => $buffer->read(5))->toThrow(DeadmanException::class);
    });
});

test('BUF-3: end() followed by the switch being destroyed leaves the data readable', function () {
    phasync::run(function () {
        $buffer = new StringBuffer();
        $switch = $buffer->getDeadmanSwitch();
        $buffer->write('x');
        $buffer->end();
        unset($switch);
        expect($buffer->read(5))->toBe('x');
        expect($buffer->eof())->toBeTrue();
    });
});

test('BUF-3: unread() puts bytes back in front of the unread data', function () {
    phasync::run(function () {
        $buffer = new StringBuffer();
        $buffer->write('xyz');
        $buffer->unread('12');
        expect($buffer->read(10))->toBe('12xyz');

        $buffer->write('hello');
        expect($buffer->read(2))->toBe('he');
        $buffer->unread('HE');
        expect($buffer->read(10))->toBe('HEllo');
    });
});

test('BUF-3: unread() wakes a blocked reader', function () {
    phasync::run(function () {
        $buffer = new StringBuffer();
        $result = null;
        $reader = phasync::go(function () use ($buffer, &$result) {
            $result = $buffer->read(10);
        });
        phasync::sleep(0.02);
        $buffer->unread('back');
        phasync::await($reader);
        expect($result)->toBe('back');
    });
});

test('BUF-3: unread() on an ended and empty buffer throws LogicException', function () {
    $buffer = new StringBuffer();
    $buffer->end();
    expect(fn () => $buffer->unread('q'))->toThrow(LogicException::class);
});

test('BUF-3: readFromResource() copies a stream into the buffer and ends the buffer at end of stream', function () {
    phasync::run(function () {
        [$source, $peer] = bufchar_pair();
        $buffer          = new StringBuffer();
        $copier          = $buffer->readFromResource($source);
        \fwrite($peer, 'hello ');
        phasync::sleep(0.02);
        \fwrite($peer, 'world');
        \fclose($peer);
        phasync::await($copier);
        expect($buffer->read(100))->toBe('hello world');
        expect($buffer->eof())->toBeTrue();
    });
});

test('BUF-3: readFromResource() rejects a non-stream with InvalidArgumentException', function () {
    $buffer = new StringBuffer();
    expect(fn () => $buffer->readFromResource('nope'))->toThrow(InvalidArgumentException::class, 'Expected stream resource');
    expect(fn () => $buffer->writeToResource('nope'))->toThrow(InvalidArgumentException::class, 'Expected stream resource');
});

test('BUF-3: writeToResource() drains an ended buffer into a stream and returns the byte count', function () {
    phasync::run(function () {
        [$target, $peer] = bufchar_pair();
        $buffer          = new StringBuffer();
        $buffer->write('payload');
        $buffer->end();
        $writer = $buffer->writeToResource($target);
        expect(phasync::await($writer))->toBe(7);
        \stream_set_blocking($peer, false);
        expect(\fread($peer, 100))->toBe('payload');
    });
});

/* ------------------------------------------------------------------ BUF-4 */

test('BUF-4: two blocked read() calls share the data: the first waiter gets what was written, the second keeps waiting', function () {
    phasync::run(function () {
        $buffer = new StringBuffer();
        $out    = [];
        $reader = function (string $name) use ($buffer, &$out) {
            return phasync::go(function () use ($name, $buffer, &$out) {
                $out[] = $name . ':' . $buffer->read(100);
            });
        };
        $a = $reader('A');
        $b = $reader('B');
        phasync::sleep(0.02);
        $buffer->write('one');
        phasync::sleep(0.02);
        expect($out)->toBe(['A:one']);
        $buffer->write('two');
        phasync::sleep(0.02);
        expect($out)->toBe(['A:one', 'B:two']);
        $buffer->end();
        phasync::await($a);
        phasync::await($b);
    });
});

test('BUF-4: two blocked readFixed(3) calls split the bytes in arrival order', function () {
    phasync::run(function () {
        $buffer = new StringBuffer();
        $out    = [];
        $reader = function (string $name) use ($buffer, &$out) {
            return phasync::go(function () use ($name, $buffer, &$out) {
                $out[] = $name . ':' . \var_export($buffer->readFixed(3), true);
            });
        };
        $a = $reader('A');
        $b = $reader('B');
        phasync::sleep(0.02);
        $buffer->write('abcd');
        phasync::sleep(0.02);
        expect($out)->toBe(["A:'abc'"]);
        $buffer->end();
        phasync::await($a);
        phasync::await($b);
        expect($out)->toBe(["A:'abc'", 'B:NULL']);
    });
});

/* ------------------------------------------------------------------ BUF-6 */

test('BUF-6: isReady(), isEmpty() and eof() through the life of a buffer', function () {
    phasync::run(function () {
        $buffer = new StringBuffer();
        expect([$buffer->isReady(), $buffer->isEmpty(), $buffer->eof()])->toBe([false, true, false]);

        $buffer->write('ab');
        expect([$buffer->isReady(), $buffer->isEmpty(), $buffer->eof()])->toBe([true, false, false]);

        $buffer->read(10);
        expect([$buffer->isReady(), $buffer->isEmpty(), $buffer->eof()])->toBe([false, true, false]);

        $buffer->write('c');
        $buffer->end();
        expect([$buffer->isReady(), $buffer->isEmpty(), $buffer->eof()])->toBe([true, false, false]);

        $buffer->read(10);
        expect([$buffer->isReady(), $buffer->isEmpty(), $buffer->eof()])->toBe([true, true, true]);
    });
});

test('BUF-6: await() returns when data arrives, when the buffer ends, or when the timeout passes', function () {
    phasync::run(function () {
        $buffer = new StringBuffer();
        phasync::go(function () use ($buffer) {
            phasync::sleep(0.03);
            $buffer->write('x');
        });
        $buffer->await();
        expect($buffer->isReady())->toBeTrue();

        $empty = new StringBuffer();
        $start = \microtime(true);
        $empty->await(0.1);
        expect($empty->isReady())->toBeFalse();
        expect(\microtime(true) - $start)->toBeGreaterThanOrEqual(0.1);
    });
});

test('BUF-6: phasync::select() returns a StringBuffer once it has data, and an ended empty one at once', function () {
    phasync::run(function () {
        $buffer = new StringBuffer();
        phasync::go(function () use ($buffer) {
            phasync::sleep(0.03);
            $buffer->write('x');
        });
        expect(phasync::select([$buffer]))->toBe($buffer);

        $ended = new StringBuffer();
        $ended->end();
        expect(phasync::select([$ended], 0))->toBe($ended);
    });
});

/* ------------------------------------------------------------- BUF-1 ($maxSize, 2.0.0) */

test('BUF-1: a negative or zero $maxSize throws OutOfBoundsException', function () {
    expect(fn () => new StringBuffer(0))->toThrow(OutOfBoundsException::class);
    expect(fn () => new StringBuffer(-1))->toThrow(OutOfBoundsException::class);
});

test('BUF-1: with no $maxSize, write() still never blocks past any size', function () {
    phasync::run(function () {
        $buffer = new StringBuffer();
        $buffer->write(\str_repeat('x', 5_000_000));
        expect(true)->toBeTrue(); // reaching here at all is the assertion
    });
});

test('BUF-1: write() blocks once $maxSize unread bytes are buffered, and a read() unblocks it', function () {
    phasync::run(function () {
        $buffer    = new StringBuffer(10);
        $unblocked = false;
        $buffer->write('0123456789'); // exactly at the limit
        $writer  = phasync::go(function () use ($buffer, &$unblocked) {
            $buffer->write('x'); // 10 - 0 >= 10, must block until a read happens
            $unblocked = true;
        });
        phasync::sleep(0.02);
        expect($unblocked)->toBeFalse();
        expect($buffer->read(1))->toBe('0'); // frees one byte: 10 - 1 = 9 < 10
        phasync::await($writer);
        expect($unblocked)->toBeTrue();
    });
});

test('BUF-1: write() throws TimeoutException if $maxSize space never frees up in time', function () {
    phasync::run(function () {
        $buffer = new StringBuffer(1);
        $buffer->write('x');
        $start = \microtime(true);
        expect(fn () => $buffer->write('y', 0.1))->toThrow(TimeoutException::class);
        expect(\microtime(true) - $start)->toBeGreaterThanOrEqual(0.1);
    });
});

test('BUF-1: write($chunk, 0) with $maxSize set never blocks and writes past the limit', function () {
    phasync::run(function () {
        $buffer = new StringBuffer(1);
        $buffer->write('x'); // already at the limit
        $start = \microtime(true);
        $buffer->write('y', 0); // must not throw, must not wait
        expect(\microtime(true) - $start)->toBeLessThan(0.05);
        expect($buffer->read(2))->toBe('xy');
    });
});

test('BUF-1: readFromResource()\'s own backpressure no longer busy-loops past 1 MB with a slow reader (found building $maxSize)', function () {
    // Runs in a subprocess with a wall-clock limit: before the fix this spun the CPU
    // forever instead of yielding, so a hang here (not a clean "done") is the failure.
    $result = bufchar_child(<<<'PHP'
        phasync::run(function () {
            [$source, $peer] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
            $buffer = new \phasync\Util\StringBuffer();
            $copier = $buffer->readFromResource($source);

            phasync::go(function () use ($peer) {
                // Push well past the 1 MB internal threshold, slowly enough that the
                // copier's own backpressure loop must actually engage and yield.
                for ($i = 0; $i < 20; $i++) {
                    \fwrite($peer, \str_repeat('x', 100_000));
                    phasync::sleep(0.005);
                }
                \fclose($peer);
            });

            $total = 0;
            while (!$buffer->eof()) {
                $total += \strlen($buffer->read(65536));
            }
            phasync::await($copier);
            echo $total === 2_000_000 ? "ok\n" : "FAIL: $total\n";
        });
        PHP, 5.0);

    expect($result['timedOut'])->toBeFalse();
    expect(\trim($result['stdout']))->toBe('ok');
});
