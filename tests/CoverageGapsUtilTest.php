<?php

use phasync\CancelledException;
use phasync\Internal\Channel;
use phasync\TimeoutException;
use phasync\Util\LockTrait;
use phasync\Util\Pool;
use phasync\Util\StringBuffer;
use phasync\Util\Synchronized;

// StringBuffer

test('StringBuffer::await() returns at once when the timeout has already passed', function () {
    phasync::run(function () {
        $buffer = new StringBuffer();

        $buffer->await(0.0);

        expect($buffer->isReady())->toBeFalse();
    });
});

test('StringBuffer::write() throws when the timeout has passed before space was found', function () {
    phasync::run(function () {
        $buffer = new StringBuffer(5);
        $buffer->write('12345');

        // Smaller than the clock resolution: already expired at the first check
        expect(fn () => $buffer->write('x', 1e-9))->toThrow(TimeoutException::class, 'waiting for buffer space');
        expect($buffer->read(100))->toBe('12345');
    });
});

test('StringBuffer::readFixed() throws when the timeout has passed before data was found', function () {
    phasync::run(function () {
        $buffer = new StringBuffer();
        $buffer->write('abc');

        expect(fn () => $buffer->readFixed(10, 1e-9))->toThrow(TimeoutException::class, 'readFixed timed out');
        expect($buffer->readFixed(3))->toBe('abc');
    });
});

test('StringBuffer::readFromResource() fails the buffer when the stream cannot be read', function () {
    $file = \tempnam(\sys_get_temp_dir(), 'phasync_sb_');
    try {
        phasync::run(function () use ($file) {
            $buffer = new StringBuffer();
            $fp     = \fopen($file, 'w');
            $fiber  = $buffer->readFromResource($fp);

            \set_error_handler(fn () => true);
            try {
                expect(fn () => phasync::await($fiber))->toThrow(RuntimeException::class, "Can't read from the stream resource");
            } finally {
                \restore_error_handler();
                \fclose($fp);
            }
            expect($buffer->eof())->toBeTrue();
        });
    } finally {
        \unlink($file);
    }
});

test('StringBuffer::readFromResource() stops reading while more than a megabyte is unread', function () {
    $file = \tempnam(\sys_get_temp_dir(), 'phasync_sb_');
    $size = 1024 * 1024 * 3;
    \file_put_contents($file, \str_repeat('x', $size));
    try {
        phasync::run(function () use ($file, $size) {
            $buffer = new StringBuffer();
            $fp     = \fopen($file, 'r');
            $fiber  = $buffer->readFromResource($fp);

            phasync::sleep(0.01);
            // Reading stopped a little above the megabyte: the file is not in the buffer
            $first = $buffer->read(1024 * 1024 * 2);
            expect(\strlen($first))->toBeGreaterThan(1024 * 1024)->toBeLessThan($size);

            // Draining the buffer lets it go on to the end of the file
            $total = \strlen($first);
            while ('' !== ($chunk = $buffer->read(65536))) {
                $total += \strlen($chunk);
            }
            expect($total)->toBe($size);
            phasync::await($fiber);
            \fclose($fp);
        });
    } finally {
        \unlink($file);
    }
});

test('StringBuffer::writeToResource() throws when the stream cannot be written', function () {
    $file = \tempnam(\sys_get_temp_dir(), 'phasync_sb_');
    try {
        phasync::run(function () use ($file) {
            $buffer = new StringBuffer();
            $buffer->write('data');
            $buffer->end();
            $fp    = \fopen($file, 'r');
            $fiber = $buffer->writeToResource($fp);

            \set_error_handler(fn () => true);
            try {
                expect(fn () => phasync::await($fiber))->toThrow(RuntimeException::class, 'Unable to write to resource');
            } finally {
                \restore_error_handler();
                \fclose($fp);
            }
        });
    } finally {
        \unlink($file);
    }
});

test('StringBuffer::writeToResource() keeps what a full pipe did not take', function () {
    $fifo = \sys_get_temp_dir() . '/phasync_sb_fifo_' . \getmypid();
    \posix_mkfifo($fifo, 0600);
    try {
        phasync::run(function () use ($fifo) {
            $reader = \fopen($fifo, 'r+');
            $writer = \fopen($fifo, 'r+');
            \stream_set_blocking($reader, false);
            \stream_set_blocking($writer, false);

            // Fill the pipe, then make room for one page only: the 64 KiB chunk can't be written whole
            $prefilled = 0;
            while (($n = @\fwrite($writer, \str_repeat('p', 4096))) > 0) {
                $prefilled += $n;
            }

            $buffer = new StringBuffer();
            $buffer->write(\str_repeat('Z', 65536));
            $buffer->end();
            $fiber = $buffer->writeToResource($writer);
            phasync::sleep(0.001);

            $received = \fread($reader, 4096);
            phasync::sleep(0.001);

            while (\strlen($received) < $prefilled + 65536) {
                $chunk = \fread(phasync::readable($reader), 65536);
                expect($chunk)->not->toBeFalse();
                $received .= $chunk;
            }

            expect(phasync::await($fiber))->toBe(65536);
            expect(\substr($received, 0, $prefilled))->toBe(\str_repeat('p', $prefilled));
            expect(\substr($received, $prefilled))->toBe(\str_repeat('Z', 65536));
            \fclose($reader);
            \fclose($writer);
        });
    } finally {
        \unlink($fifo);
    }
})->skip(!\function_exists('posix_mkfifo'), 'needs posix');

// Channel

test('Channel::await() times out on a closed channel that holds nothing', function () {
    phasync::run(function () {
        $channel = new Channel();
        $channel->close();

        expect($channel->isReady())->toBeFalse();
        expect(fn () => $channel->await(0.01))->toThrow(TimeoutException::class);
    });
});

test('Channel::read() times out when the timeout has passed before a value was found', function () {
    phasync::run(function () {
        phasync::channel($reader, $writer);
        $reader->activate();

        expect(fn () => $reader->read(0.0))->toThrow(TimeoutException::class, 'Channel read operation timed out');
    });
});

test('Channel::write() times out when the timeout has passed before room was found', function () {
    phasync::run(function () {
        phasync::channel($reader, $writer, 1);
        $writer->activate();
        $writer->write('first');

        expect(fn () => $writer->write('second', 0.0))->toThrow(TimeoutException::class, 'Channel write operation timed out');
        expect($reader->read())->toBe('first');
    });
});

// Cancelled in the line, after being handed what it waited for

test('Pool::borrow() passes on an instance that was handed to a borrower cancelled before it ran', function () {
    phasync::run(function () {
        $pool     = new Pool(fn () => new stdClass(), 1);
        $instance = $pool->borrow();

        $waiter = phasync::go(fn () => $pool->borrow());
        phasync::sleep(0.001);

        // Handed to the waiter, which is cancelled before it gets to run
        $pool->release($instance);
        phasync::cancel($waiter);

        expect(fn () => phasync::await($waiter))->toThrow(CancelledException::class);
        expect($pool->borrow(0.1))->toBe($instance);
    });
});

test('Synchronized::run() passes on a lock that was handed to a waiter cancelled before it ran', function () {
    phasync::run(function () {
        $waiter = null;
        $holder = phasync::go(function () use (&$waiter) {
            Synchronized::run('coverage-gaps-lock', function () use (&$waiter) {
                phasync::sleep(0.01);
                // Starts after the lock is handed over, but before the waiter has run
                phasync::go(fn () => phasync::cancel($waiter));
            });
        });
        phasync::sleep(0.001);
        $waiter = phasync::go(fn () => Synchronized::run('coverage-gaps-lock', fn () => 'never'));
        phasync::await($holder);

        expect(fn () => phasync::await($waiter))->toThrow(CancelledException::class);
        expect(Synchronized::run('coverage-gaps-lock', fn () => 'free'))->toBe('free');
    });
});

test('LockTrait::lock() passes on a lock that was handed to a waiter cancelled before it ran', function () {
    phasync::run(function () {
        $object = new class {
            use LockTrait;
        };

        $waiter = null;
        $holder = phasync::go(function () use ($object, &$waiter) {
            $object->lock(function () use (&$waiter) {
                phasync::sleep(0.01);
                // Starts after the lock is handed over, but before the waiter has run
                phasync::go(fn () => phasync::cancel($waiter));
            });
        });
        phasync::sleep(0.001);
        $waiter = phasync::go(fn () => $object->lock(fn () => 'never'));
        phasync::await($holder);

        expect(fn () => phasync::await($waiter))->toThrow(CancelledException::class);
        expect($object->lock(fn () => 'free', 0.1))->toBe('free');
    });
});

test('rebuildStackTrace() skips frames without a file when it is called from an internal function', function () {
    $exception = new TimeoutException('rethrown');

    // array_map() calls the method, so the innermost frame has no file
    $line = __LINE__ + 1;
    \array_map([$exception, 'rebuildStackTrace'], [1]);

    expect($exception->getFile())->toBe(__FILE__);
    expect($exception->getLine())->toBe($line);
});
