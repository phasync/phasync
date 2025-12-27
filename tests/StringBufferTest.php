<?php

use phasync\Util\StringBuffer;
use phasync\TimeoutException;

/*
|--------------------------------------------------------------------------
| StringBuffer Test Suite
|--------------------------------------------------------------------------
|
| Comprehensive tests for the StringBuffer utility class covering:
| - Basic operations (read, write, unread)
| - Fixed-length reads
| - EOF and buffer state management
| - Async/concurrent behavior
| - Edge cases and error conditions
| - Performance and memory efficiency
| - Resource integration
|
*/

// ============================================================================
// INITIALIZATION & STATE TESTS
// ============================================================================

test('new StringBuffer is empty and not ended', function () {
    $sb = new StringBuffer();

    expect($sb->isEmpty())->toBeTrue();
    expect($sb->eof())->toBeFalse();
    expect($sb->isReady())->toBeFalse();
});

test('StringBuffer implements SelectableInterface', function () {
    $sb = new StringBuffer();

    expect($sb)->toBeInstanceOf(\phasync\SelectableInterface::class);
});

// ============================================================================
// WRITE TESTS
// ============================================================================

test('write() adds data to buffer', function () {
    $sb = new StringBuffer();

    $sb->write('Hello');

    expect($sb->isEmpty())->toBeFalse();
    expect($sb->isReady())->toBeTrue();
});

test('write() accepts empty string', function () {
    $sb = new StringBuffer();

    $sb->write('');

    // Empty string is still added to queue
    expect($sb->read(10, 0))->toBe('');
});

test('write() multiple chunks queues them in order', function () {
    $sb = new StringBuffer();

    $sb->write('Hello');
    $sb->write(' ');
    $sb->write('World');
    $sb->end();

    expect($sb->read(11))->toBe('Hello World');
});

test('write() throws RuntimeException after end()', function () {
    $sb = new StringBuffer();
    $sb->end();

    expect(fn() => $sb->write('data'))->toThrow(RuntimeException::class, 'Buffer has been ended');
});

test('write() handles large data', function () {
    $sb = new StringBuffer();
    $largeData = str_repeat('x', 100000);

    $sb->write($largeData);
    $sb->end();

    expect($sb->read(100000))->toBe($largeData);
});

test('write() handles binary data', function () {
    $sb = new StringBuffer();
    $binaryData = "\x00\x01\x02\xff\xfe\xfd";

    $sb->write($binaryData);
    $sb->end();

    expect($sb->read(6))->toBe($binaryData);
});

// ============================================================================
// READ TESTS
// ============================================================================

test('read() returns available data up to maxLength', function () {
    $sb = new StringBuffer();
    $sb->write('Hello World');
    $sb->end();

    expect($sb->read(5))->toBe('Hello');
    expect($sb->read(6))->toBe(' World');
});

test('read() returns less than maxLength if not enough data', function () {
    $sb = new StringBuffer();
    $sb->write('Hi');
    $sb->end();

    expect($sb->read(100))->toBe('Hi');
});

test('read() with zero maxLength returns empty string', function () {
    $sb = new StringBuffer();
    $sb->write('Hello');

    expect($sb->read(0))->toBe('');
});

test('read() throws OutOfBoundsException for negative length', function () {
    $sb = new StringBuffer();

    expect(fn() => $sb->read(-1))->toThrow(OutOfBoundsException::class, "Can't read negative lengths");
});

test('read() with timeout 0 returns empty if no data', function () {
    $sb = new StringBuffer();

    expect($sb->read(10, 0))->toBe('');
});

test('read() returns empty from ended empty buffer', function () {
    $sb = new StringBuffer();
    $sb->end();

    expect($sb->read(10))->toBe('');
});

test('read() consumes data from buffer', function () {
    $sb = new StringBuffer();
    $sb->write('ABCDEF');
    $sb->end();

    expect($sb->read(2))->toBe('AB');
    expect($sb->read(2))->toBe('CD');
    expect($sb->read(2))->toBe('EF');
    expect($sb->read(2))->toBe('');
});

test('read() merges multiple queued chunks', function () {
    $sb = new StringBuffer();
    $sb->write('AB');
    $sb->write('CD');
    $sb->write('EF');
    $sb->end();

    expect($sb->read(6))->toBe('ABCDEF');
});

test('read() handles partial chunk consumption', function () {
    $sb = new StringBuffer();
    $sb->write('ABCDEF');
    $sb->end();

    expect($sb->read(1))->toBe('A');
    expect($sb->read(1))->toBe('B');
    expect($sb->read(4))->toBe('CDEF');
});

// ============================================================================
// READFIXED TESTS
// ============================================================================

test('readFixed() returns exact number of bytes', function () {
    $sb = new StringBuffer();
    $sb->write('Hello World');
    $sb->end();

    expect($sb->readFixed(5))->toBe('Hello');
    expect($sb->readFixed(6))->toBe(' World');
});

test('readFixed() returns null if not enough data and ended', function () {
    $sb = new StringBuffer();
    $sb->write('Hi');
    $sb->end();

    expect($sb->readFixed(10))->toBeNull();
});

test('readFixed() returns null with timeout 0 if not enough data', function () {
    $sb = new StringBuffer();
    $sb->write('Hi');

    expect($sb->readFixed(10, 0))->toBeNull();
});

test('readFixed() throws OutOfBoundsException for negative length', function () {
    $sb = new StringBuffer();

    expect(fn() => $sb->readFixed(-1))->toThrow(OutOfBoundsException::class, "Can't read negative lengths");
});

test('readFixed() with zero length returns empty string', function () {
    $sb = new StringBuffer();
    $sb->write('Hello');

    expect($sb->readFixed(0))->toBe('');
});

test('readFixed() consumes exact bytes from buffer', function () {
    $sb = new StringBuffer();
    $sb->write('ABCDEFGH');
    $sb->end();

    expect($sb->readFixed(3))->toBe('ABC');
    expect($sb->readFixed(3))->toBe('DEF');
    expect($sb->readFixed(3))->toBeNull(); // Only 2 bytes left
});

// ============================================================================
// UNREAD TESTS
// ============================================================================

test('unread() prepends data to buffer', function () {
    $sb = new StringBuffer();
    $sb->write('World');
    $sb->unread('Hello ');
    $sb->end();

    expect($sb->read(11))->toBe('Hello World');
});

test('unread() works when buffer has partial data', function () {
    $sb = new StringBuffer();
    $sb->write('ABCDEF');

    $sb->read(2); // Read 'AB', buffer has 'CDEF'
    $sb->unread('XX');
    $sb->end();

    expect($sb->read(6))->toBe('XXCDEF');
});

test('unread() works when buffer is empty but queue has data', function () {
    $sb = new StringBuffer();
    $sb->write('Hello');

    $sb->read(5); // Drain buffer, queue is empty now
    $sb->write('World');
    $sb->unread('XX');
    $sb->end();

    expect($sb->read(7))->toBe('XXWorld');
});

test('unread() throws LogicException on ended empty buffer', function () {
    $sb = new StringBuffer();
    $sb->write('Hi');
    $sb->end();
    $sb->read(2); // Drain it

    expect(fn() => $sb->unread('data'))->toThrow(LogicException::class, "Can't unread to an ended and empty StringBuffer");
});

test('unread() works on ended buffer with remaining data', function () {
    $sb = new StringBuffer();
    $sb->write('Hello');
    $sb->end();

    $sb->unread('XX');

    expect($sb->read(7))->toBe('XXHello');
});

test('multiple unreads work correctly', function () {
    $sb = new StringBuffer();
    $sb->write('C');
    $sb->unread('B');
    $sb->unread('A');
    $sb->end();

    expect($sb->read(3))->toBe('ABC');
});

test('unread() with empty string', function () {
    $sb = new StringBuffer();
    $sb->write('Hello');
    $sb->unread('');
    $sb->end();

    expect($sb->read(5))->toBe('Hello');
});

// ============================================================================
// END & EOF TESTS
// ============================================================================

test('end() marks buffer as ended', function () {
    $sb = new StringBuffer();
    $sb->write('data');

    expect($sb->eof())->toBeFalse();

    $sb->end();

    expect($sb->eof())->toBeFalse(); // Still has data
    expect($sb->isReady())->toBeTrue();
});

test('end() twice throws LogicException', function () {
    $sb = new StringBuffer();
    $sb->end();

    expect(fn() => $sb->end())->toThrow(LogicException::class, 'StringBuffer already ended');
});

test('eof() is true only when ended and fully drained', function () {
    $sb = new StringBuffer();
    $sb->write('AB');
    $sb->end();

    expect($sb->eof())->toBeFalse();

    $sb->read(1);
    expect($sb->eof())->toBeFalse();

    $sb->read(1);
    expect($sb->eof())->toBeTrue();
});

test('eof() handles queue and buffer separately', function () {
    $sb = new StringBuffer();
    $sb->write('A');
    $sb->write('B');
    $sb->end();

    // Data in queue
    expect($sb->eof())->toBeFalse();

    $sb->read(1); // Moves 'A' to buffer and reads it
    expect($sb->eof())->toBeFalse();

    $sb->read(1); // Reads 'B'
    expect($sb->eof())->toBeTrue();
});

// ============================================================================
// ISEMPTY & ISREADY TESTS
// ============================================================================

test('isEmpty() returns true when no data available', function () {
    $sb = new StringBuffer();

    expect($sb->isEmpty())->toBeTrue();

    $sb->write('Hi');
    expect($sb->isEmpty())->toBeFalse();

    $sb->read(2, 0);
    expect($sb->isEmpty())->toBeTrue();
});

test('isReady() returns true when data available or ended', function () {
    $sb = new StringBuffer();

    expect($sb->isReady())->toBeFalse();

    $sb->write('Hi');
    expect($sb->isReady())->toBeTrue();

    $sb->read(2, 0);
    expect($sb->isReady())->toBeFalse();

    $sb->end();
    expect($sb->isReady())->toBeTrue(); // Ended, so always ready
});

// ============================================================================
// BUFFER_WASTE_LIMIT TESTS
// ============================================================================

test('buffer compaction occurs when offset exceeds BUFFER_WASTE_LIMIT', function () {
    $sb = new StringBuffer();

    // Write data larger than BUFFER_WASTE_LIMIT (4096)
    $largeData = str_repeat('X', 5000);
    $sb->write($largeData);
    $sb->write('END');
    $sb->end();

    // Read most of it, offset will exceed 4096
    $sb->read(4500);

    // Next read should trigger compaction internally
    $result = $sb->read(503);

    expect($result)->toBe(str_repeat('X', 500) . 'END');
});

test('multiple reads triggering compaction', function () {
    $sb = new StringBuffer();

    // Write a lot of data
    for ($i = 0; $i < 10; $i++) {
        $sb->write(str_repeat((string)$i, 1000));
    }
    $sb->end();

    // Read in small chunks to trigger compaction multiple times
    $total = '';
    while (!$sb->eof()) {
        $total .= $sb->read(500);
    }

    expect(strlen($total))->toBe(10000);
});

// ============================================================================
// ASYNC/CONCURRENT TESTS
// ============================================================================

test('read() blocks until data available', function () {
    phasync::run(function () {
        $sb = new StringBuffer();
        $result = null;

        phasync::go(function () use ($sb, &$result) {
            $result = $sb->read(5);
        });

        // Give reader time to block
        phasync::sleep(0.01);
        expect($result)->toBeNull();

        $sb->write('Hello');
        $sb->end();

        // Wait for reader to complete
        phasync::sleep(0.01);
        expect($result)->toBe('Hello');
    });
});

test('read() unblocks when buffer is ended', function () {
    phasync::run(function () {
        $sb = new StringBuffer();
        $result = null;

        phasync::go(function () use ($sb, &$result) {
            $result = $sb->read(100);
        });

        phasync::sleep(0.01);
        $sb->end();

        phasync::sleep(0.01);
        expect($result)->toBe('');
    });
});

test('readFixed() blocks until enough data available', function () {
    phasync::run(function () {
        $sb = new StringBuffer();
        $result = 'not set';

        phasync::go(function () use ($sb, &$result) {
            $result = $sb->readFixed(10);
        });

        phasync::sleep(0.01);
        expect($result)->toBe('not set');

        $sb->write('Hello');
        phasync::sleep(0.01);
        expect($result)->toBe('not set'); // Still waiting for 10 bytes

        $sb->write('World');
        phasync::sleep(0.01);
        expect($result)->toBe('HelloWorld');
    });
});

test('readFixed() returns null when ended with insufficient data', function () {
    phasync::run(function () {
        $sb = new StringBuffer();
        $result = 'not set';

        phasync::go(function () use ($sb, &$result) {
            $result = $sb->readFixed(10);
        });

        phasync::sleep(0.01);
        $sb->write('Hi');
        $sb->end();

        phasync::sleep(0.01);
        expect($result)->toBeNull();
    });
});

test('await() blocks until data or end', function () {
    phasync::run(function () {
        $sb = new StringBuffer();
        $awaited = false;

        phasync::go(function () use ($sb, &$awaited) {
            $sb->await();
            $awaited = true;
        });

        phasync::sleep(0.01);
        expect($awaited)->toBeFalse();

        $sb->write('data');

        phasync::sleep(0.01);
        expect($awaited)->toBeTrue();
    });
});

test('await() returns immediately if already ready', function () {
    phasync::run(function () {
        $sb = new StringBuffer();
        $sb->write('data');

        $start = microtime(true);
        $sb->await();
        $elapsed = microtime(true) - $start;

        expect($elapsed)->toBeLessThan(0.01);
    });
});

test('await() returns immediately if ended', function () {
    phasync::run(function () {
        $sb = new StringBuffer();
        $sb->end();

        $start = microtime(true);
        $sb->await();
        $elapsed = microtime(true) - $start;

        expect($elapsed)->toBeLessThan(0.01);
    });
});

test('concurrent readers and writers', function () {
    phasync::run(function () {
        $sb = new StringBuffer();
        $received = '';

        // Reader coroutine
        $reader = phasync::go(function () use ($sb, &$received) {
            while (!$sb->eof()) {
                $received .= $sb->read(10);
            }
        });

        // Writer coroutine
        phasync::go(function () use ($sb) {
            for ($i = 0; $i < 5; $i++) {
                $sb->write("chunk$i");
                phasync::sleep(0.01);
            }
            $sb->end();
        });

        // Must await reader before checking result
        phasync::await($reader);
        expect($received)->toBe('chunk0chunk1chunk2chunk3chunk4');
    });
});

test('multiple writers single reader', function () {
    phasync::run(function () {
        $sb = new StringBuffer();
        $received = '';
        $writersCompleted = 0;

        // Reader
        $reader = phasync::go(function () use ($sb, &$received) {
            while (!$sb->eof()) {
                $received .= $sb->read(100);
            }
        });

        // Writer 1
        phasync::go(function () use ($sb, &$writersCompleted) {
            for ($i = 0; $i < 3; $i++) {
                $sb->write("A$i");
            }
            $writersCompleted++;
        });

        // Writer 2
        phasync::go(function () use ($sb, &$writersCompleted) {
            for ($i = 0; $i < 3; $i++) {
                $sb->write("B$i");
            }
            $writersCompleted++;
        });

        // Wait for writers then end
        while ($writersCompleted < 2) {
            phasync::sleep(0.01);
        }
        $sb->end();

        phasync::await($reader);

        // All data should be present (order may vary due to concurrency)
        expect(strlen($received))->toBe(12);
        expect($received)->toContain('A0');
        expect($received)->toContain('B0');
    });
});

// ============================================================================
// RESOURCE INTEGRATION TESTS
// ============================================================================

test('readFromResource() throws for non-stream', function () {
    $sb = new StringBuffer();

    expect(fn() => $sb->readFromResource('not a resource'))->toThrow(InvalidArgumentException::class);
});

test('writeToResource() throws for non-stream', function () {
    $sb = new StringBuffer();

    expect(fn() => $sb->writeToResource('not a resource'))->toThrow(InvalidArgumentException::class);
});

test('readFromResource() reads stream into buffer', function () {
    phasync::run(function () {
        $sb = new StringBuffer();

        // Create a temp file with data
        $tmpFile = tempnam(sys_get_temp_dir(), 'phasync_test');
        file_put_contents($tmpFile, 'Hello from file');

        $fp = fopen($tmpFile, 'r');

        $reader = $sb->readFromResource($fp);

        // Read all data
        $result = '';
        while (!$sb->eof()) {
            $result .= $sb->read(100);
        }

        phasync::await($reader);
        fclose($fp);
        unlink($tmpFile);

        expect($result)->toBe('Hello from file');
    });
});

test('writeToResource() writes buffer to stream', function () {
    phasync::run(function () {
        $sb = new StringBuffer();

        // Create temp file for writing
        $tmpFile = tempnam(sys_get_temp_dir(), 'phasync_test');
        $fp = fopen($tmpFile, 'w');

        $writer = $sb->writeToResource($fp);

        $sb->write('Hello to file');
        $sb->end();

        phasync::await($writer);
        fclose($fp);

        expect(file_get_contents($tmpFile))->toBe('Hello to file');
        unlink($tmpFile);
    });
});

test('readFromResource() handles large file', function () {
    phasync::run(function () {
        $sb = new StringBuffer();

        // Create a larger temp file
        $tmpFile = tempnam(sys_get_temp_dir(), 'phasync_test');
        $data = str_repeat('X', 100000);
        file_put_contents($tmpFile, $data);

        $fp = fopen($tmpFile, 'r');
        $reader = $sb->readFromResource($fp);

        $result = '';
        while (!$sb->eof()) {
            $result .= $sb->read(10000);
        }

        phasync::await($reader);
        fclose($fp);
        unlink($tmpFile);

        expect(strlen($result))->toBe(100000);
    });
});

// ============================================================================
// EDGE CASES & BOUNDARY CONDITIONS
// ============================================================================

test('read and unread interleaved operations', function () {
    $sb = new StringBuffer();

    $sb->write('ABCDEF');
    expect($sb->read(3, 0))->toBe('ABC');

    $sb->unread('XX');
    expect($sb->read(5, 0))->toBe('XXDEF');

    $sb->write('GHI');
    $sb->unread('YY');
    $sb->end();

    expect($sb->read(5))->toBe('YYGHI');
});

test('empty buffer behavior after complete drain', function () {
    $sb = new StringBuffer();

    $sb->write('test');
    $sb->read(4, 0);

    expect($sb->isEmpty())->toBeTrue();
    expect($sb->eof())->toBeFalse();
    expect($sb->read(10, 0))->toBe('');

    $sb->write('more');
    expect($sb->isEmpty())->toBeFalse();
    expect($sb->read(4, 0))->toBe('more');
});

test('readFixed at exact buffer boundary', function () {
    $sb = new StringBuffer();
    $sb->write('12345');
    $sb->end();

    expect($sb->readFixed(5))->toBe('12345');
    expect($sb->readFixed(1))->toBeNull();
    expect($sb->eof())->toBeTrue();
});

test('operations with single byte data', function () {
    $sb = new StringBuffer();

    $sb->write('A');
    expect($sb->read(1, 0))->toBe('A');
    expect($sb->isEmpty())->toBeTrue();

    $sb->write('B');
    $sb->unread('X');
    expect($sb->read(2, 0))->toBe('XB');

    $sb->end();
    expect($sb->eof())->toBeTrue();
});

test('isReady respects SelectableInterface contract', function () {
    $sb = new StringBuffer();

    // Empty and not ended = not ready
    expect($sb->isReady())->toBeFalse();

    // Has data = ready
    $sb->write('x');
    expect($sb->isReady())->toBeTrue();

    // Drain data
    $sb->read(1, 0);
    expect($sb->isReady())->toBeFalse();

    // Ended = always ready (even if empty)
    $sb->end();
    expect($sb->isReady())->toBeTrue();
});

test('StringBuffer can be used with phasync::select', function () {
    phasync::run(function () {
        $sb = new StringBuffer();

        phasync::go(function () use ($sb) {
            phasync::sleep(0.05);
            $sb->write('data');
        });

        $selected = phasync::select([$sb], timeout: 1.0);

        expect($selected)->toBe($sb);
        expect($sb->read(4, 0))->toBe('data');
    });
});

// ============================================================================
// PERFORMANCE TESTS
// ============================================================================

test('StringBuffer handles high-frequency writes', function () {
    phasync::run(function () {
        $sb = new StringBuffer();
        $count = 0;

        $reader = phasync::go(function () use ($sb, &$count) {
            while (!$sb->eof()) {
                $data = $sb->read(1);
                if ($data !== '') {
                    $count++;
                }
            }
        });

        phasync::go(function () use ($sb) {
            for ($i = 0; $i < 1000; $i++) {
                $sb->write('x');
            }
            $sb->end();
        });

        phasync::await($reader);
        expect($count)->toBe(1000);
    });
});

test('StringBuffer performance with larger chunks', function () {
    phasync::run(function () {
        $sb = new StringBuffer();
        $totalReceived = 0;

        $reader = phasync::go(function () use ($sb, &$totalReceived) {
            while (!$sb->eof()) {
                $data = $sb->read(8192);
                $totalReceived += strlen($data);
            }
        });

        phasync::go(function () use ($sb) {
            $chunk = str_repeat('x', 1024);
            for ($i = 0; $i < 100; $i++) {
                $sb->write($chunk);
            }
            $sb->end();
        });

        phasync::await($reader);
        expect($totalReceived)->toBe(102400);
    });
});

test('StringBuffer memory efficiency with large data', function () {
    $sb = new StringBuffer();

    // Write 1MB of data
    $chunk = str_repeat('x', 65536);
    for ($i = 0; $i < 16; $i++) {
        $sb->write($chunk);
    }
    $sb->end();

    // Read it all back
    $total = 0;
    while (!$sb->eof()) {
        $total += strlen($sb->read(65536));
    }

    expect($total)->toBe(1048576);
});

// ============================================================================
// BACKWARD COMPATIBILITY (Original Tests)
// ============================================================================

test('StringBuffer basic tests (original)', function () {
    $sb = new StringBuffer();
    expect($sb->eof())->toBeFalse();
    $sb->write('Hello');
    expect($sb->read(10000))->toBe('Hello');
    expect($sb->read(100, 0))->toBe('');
    expect($sb->write('ab'))->toBeNull();
    expect($sb->read(1))->toBe('a');
    expect($sb->read(2))->toBe('b');
    $sb->write('abc');
    expect($sb->eof())->toBeFalse();
    $sb->write('def');
    $sb->end();
    $sb->unread('b');
    expect($sb->read(5))->toBe('babcd');
    expect($sb->eof())->toBeFalse();
    expect($sb->read(10000))->toBe('ef');
    expect($sb->eof())->toBeTrue();
});

test('StringBuffer concurrent tests (original)', function () {
    phasync::run(function () {
        $sb = new StringBuffer();
        phasync::go(function () use ($sb) {
            expect($sb->read(10, true))->toBe('abc');
            expect($sb->eof())->toBe(false);
            expect($sb->read(1, true))->toBe('');
            expect($sb->eof())->toBeTrue();
        });
        $sb->write('abc');
        phasync::go(function () use ($sb) {
            phasync::sleep(0.1);
            $sb->end();
        });
    });
});

test('StringBuffer performance tests (original)', function () {
    phasync::run(function () {
        $t  = \microtime(true);
        $sb = new StringBuffer();
        phasync::go(function () use ($sb) {
            $s = '';
            while (!$sb->eof()) {
                $s .= $sb->read(1, true);
            }
        });
        phasync::go(function () use ($sb) {
            for ($i = 0; $i < 1000; ++$i) {
                $sb->write('a');
            }
            $sb->end();
        });
        expect(\microtime(true) - $t)->toBeLessThan(0.3);
    });
});

// ============================================================================
// DEADMAN SWITCH TESTS
// ============================================================================

test('getDeadmanSwitch() returns DeadmanSwitch instance', function () {
    $sb = new StringBuffer();
    $deadman = $sb->getDeadmanSwitch();

    expect($deadman)->toBeInstanceOf(\phasync\DeadmanSwitch::class);
});

test('getDeadmanSwitch() returns same instance while alive', function () {
    $sb = new StringBuffer();
    $deadman1 = $sb->getDeadmanSwitch();
    $deadman2 = $sb->getDeadmanSwitch();

    expect($deadman1)->toBe($deadman2);
});

test('deadman switch triggers on garbage collection', function () {
    phasync::run(function () {
        $sb = new StringBuffer();

        // Create a scope where deadman goes out of scope
        $func = function () use ($sb) {
            $deadman = $sb->getDeadmanSwitch();
            $sb->write('hello');
            // $deadman goes out of scope here, writer didn't call end()
        };
        $func();
        gc_collect_cycles();

        // Existing data can still be read
        expect($sb->read(5, 0))->toBe('hello');

        // But trying to read more (which would block) throws
        expect(fn() => $sb->read(10))
            ->toThrow(\phasync\DeadmanException::class);
    });
});

test('deadman switch can be disarmed', function () {
    phasync::run(function () {
        $sb = new StringBuffer();
        $deadman = $sb->getDeadmanSwitch();
        $sb->write('data');

        $deadman->disarm();
        unset($deadman);
        gc_collect_cycles();

        // Buffer is not failed, but also not ended - read with timeout returns what's available
        expect($sb->read(4, 0))->toBe('data');
        // Non-blocking read returns empty (no more data, not ended)
        expect($sb->read(10, 0))->toBe('');
    });
});

test('read() can get existing data after deadman triggered', function () {
    phasync::run(function () {
        $sb = new StringBuffer();

        $func = function () use ($sb) {
            $deadman = $sb->getDeadmanSwitch();
            $sb->write('existing data');
        };
        $func();
        gc_collect_cycles();

        // Can read existing data
        expect($sb->read(8, 0))->toBe('existing');
        expect($sb->read(5, 0))->toBe(' data');

        // But blocking read for more throws
        expect(fn() => $sb->read(10))
            ->toThrow(\phasync\DeadmanException::class);
    });
});

test('readFixed() throws when would block after deadman triggered', function () {
    phasync::run(function () {
        $sb = new StringBuffer();

        $func = function () use ($sb) {
            $deadman = $sb->getDeadmanSwitch();
            $sb->write('short'); // Only 5 bytes
        };
        $func();
        gc_collect_cycles();

        // Asking for more than available, would need to block
        expect(fn() => $sb->readFixed(10))
            ->toThrow(\phasync\DeadmanException::class);
    });
});

test('deadman switch allows reading buffered data before throwing', function () {
    phasync::run(function () {
        $sb = new StringBuffer();

        $writer = phasync::go(function () use ($sb) {
            $deadman = $sb->getDeadmanSwitch();
            $sb->write('hello');
            $sb->write('world');
            // Exits without $sb->end()
        });

        phasync::await($writer);
        gc_collect_cycles();

        // Can read all buffered data
        expect($sb->read(10, 0))->toBe('helloworld');

        // Blocking read throws
        expect(fn() => $sb->read(10))
            ->toThrow(\phasync\DeadmanException::class);
    });
});

test('deadman switch is harmless when writer calls end()', function () {
    phasync::run(function () {
        $sb = new StringBuffer();
        $framesReceived = 0;

        $reader = phasync::go(function () use ($sb, &$framesReceived) {
            while (true) {
                $frame = $sb->readFixed(10, 0.1);
                if ($frame === null) {
                    break;
                }
                $framesReceived++;
            }
        });

        phasync::go(function () use ($sb) {
            $deadman = $sb->getDeadmanSwitch();
            $sb->write(str_repeat('A', 10));
            $sb->write(str_repeat('B', 10));
            $sb->write(str_repeat('C', 10));
            $sb->end(); // Proper termination
        });

        phasync::await($reader);
        expect($framesReceived)->toBe(3);
    });
});

test('DeadmanSwitch can be manually triggered', function () {
    phasync::run(function () {
        $sb = new StringBuffer();
        $deadman = $sb->getDeadmanSwitch();
        $sb->write('data');

        $deadman->trigger();

        // Data still readable
        expect($sb->read(4, 0))->toBe('data');

        // Blocking read throws
        expect(fn() => $sb->read(10))
            ->toThrow(\phasync\DeadmanException::class);
        expect($deadman->isTriggered())->toBeTrue();
    });
});

test('DeadmanSwitch trigger is idempotent', function () {
    $callCount = 0;
    $deadman = new \phasync\DeadmanSwitch(function () use (&$callCount) {
        $callCount++;
    });

    $deadman->trigger();
    $deadman->trigger();
    $deadman->trigger();

    expect($callCount)->toBe(1);
});

test('isReady() returns true when deadman switch triggered on empty buffer', function () {
    phasync::run(function () {
        $sb = new StringBuffer();

        // Empty buffer is not ready
        expect($sb->isReady())->toBeFalse();

        // Trigger deadman switch
        $deadman = $sb->getDeadmanSwitch();
        $deadman->trigger();

        // Failed buffer is ready (read won't block, it will throw)
        expect($sb->isReady())->toBeTrue();
    });
});

test('phasync::select() selects failed StringBuffer', function () {
    phasync::run(function () {
        $sb1 = new StringBuffer();
        $sb2 = new StringBuffer();

        // Trigger deadman on sb1
        $deadman = $sb1->getDeadmanSwitch();
        $deadman->trigger();

        // select() should return sb1 (the failed one)
        $selected = phasync::select([$sb1, $sb2], 0);
        expect($selected)->toBe($sb1);
    });
});
