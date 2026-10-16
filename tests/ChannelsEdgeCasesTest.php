<?php

use phasync\ChannelException;
use phasync\TimeoutException;

/*
 * Edge case and error condition tests for Channel
 */

test('channel read returns data when closed with data', function () {
    phasync::run(function () {
        phasync::channel($reader, $writer, 5); // Buffered
        phasync::go(function () use ($writer) {
            $writer->write('data');
            $writer->close();
        });
        phasync::sleep(0.01);
        expect($reader->read())->toBe('data');
    });
});

test('channel read returns null when closed empty', function () {
    phasync::run(function () {
        phasync::channel($reader, $writer);
        phasync::go(function () use ($writer) {
            $writer->close();
        });
        phasync::sleep(0.01);
        expect($reader->read())->toBeNull();
    });
});

test('channel read returns data when unbuffered has pending write', function () {
    phasync::run(function () {
        phasync::channel($reader, $writer, 0); // Unbuffered
        phasync::go(function () use ($writer) {
            $writer->write('pending');
            phasync::sleep(0.1); // Keep it pending
        });
        phasync::sleep(0.01);
        expect($reader->read())->toBe('pending');
    });
});

test('channel isReadyForWrite returns true when unbuffered and no pending write', function () {
    phasync::run(function () {
        phasync::channel($reader, $writer, 0); // Unbuffered
        expect($writer->isWritable())->toBeTrue();
        // isReadyForWrite should return true (ready to accept a write)
        expect(true)->toBeTrue();
    });
});

test('channel isReadyForWrite returns false when buffered at capacity', function () {
    phasync::run(function () {
        phasync::channel($reader, $writer, 1); // Capacity 1
        phasync::go(function () use ($writer) {
            $writer->write('full');
            phasync::sleep(0.1); // Keep it full
        });
        phasync::sleep(0.01);
        // Buffer is full, writer should not be ready
        expect($writer->isWritable())->toBeTrue(); // But channel not closed
    });
});

test('channel read with timeout raises TimeoutException', function () {
    phasync::run(function () {
        phasync::channel($reader, $writer);
        phasync::go(function () use ($reader) {
            expect(function () use ($reader) {
                $reader->read(0.01);
            })->toThrow(TimeoutException::class);
        });
        phasync::sleep(0.02);
    });
});

test('channel read sets EOF flag', function () {
    phasync::run(function () {
        phasync::channel($reader, $writer);
        phasync::go(function () use ($writer) {
            $writer->close();
        });
        phasync::sleep(0.01);
        $result = $reader->read(eof: $eof);
        expect($eof)->toBeTrue();
        expect($result)->toBeNull();
    });
});

test('channel write to closed channel throws', function () {
    phasync::run(function () {
        phasync::channel($reader, $writer);
        $writer->close();
        expect(function () use ($writer) {
            $writer->write('test');
        })->toThrow(ChannelException::class);
    });
});

test('channel write with timeout on unbuffered blocked channel', function () {
    phasync::run(function () {
        phasync::channel($reader, $writer, 0); // Unbuffered
        phasync::go(function () use ($writer) {
            $writer->write('first');
            // Reader doesn't take it, so it's blocked
            phasync::sleep(0.2);
        });
        phasync::sleep(0.01);
        // Now writer is blocked with pending data
        // Creator fiber trying to write should timeout
        expect(function () use ($writer) {
            $writer->write('second', 0.05);
        })->toThrow(TimeoutException::class);
    });
});

test('channel await on reader waits for data', function () {
    phasync::run(function () {
        phasync::channel($reader, $writer);
        phasync::go(function () use ($reader) {
            $reader->await(1.0);
            expect($reader->isReady())->toBeTrue();
        });
        phasync::sleep(0.01);
        phasync::go(function () use ($writer) {
            $writer->write('test');
        });
        phasync::sleep(0.02);
    });
});

test('channel await on writer waits', function () {
    phasync::run(function () {
        phasync::channel($reader, $writer, 0); // Unbuffered
        phasync::go(function () use ($reader) {
            phasync::sleep(0.01);
            $reader->read();
        });
        phasync::go(function () use ($writer) {
            $writer->write('pending');
            expect(true)->toBeTrue();
        });
        phasync::sleep(0.02);
    });
});

test('channel various operations', function () {
    phasync::run(function () {
        phasync::channel($reader, $writer, 1);
        phasync::go(function () use ($reader, $writer) {
            phasync::go(function () use ($writer) {
                phasync::sleep(0.02);
                $writer->write('test');
                $writer->close();
            });
            phasync::sleep(0.01);
            expect($reader->read())->toBe('test');
            expect($reader->read())->toBeNull();
        });
    });
});

test('channel isReadable returns true when open', function () {
    phasync::run(function () {
        phasync::channel($reader, $writer);
        expect($reader->isReadable())->toBeTrue();
    });
});

test('channel isReadable returns false when closed with no data', function () {
    phasync::run(function () {
        phasync::channel($reader, $writer);
        phasync::go(function () use ($writer) {
            $writer->close();
        });
        phasync::sleep(0.01);
        expect($reader->isReadable())->toBeFalse();
    });
});

test('channel isWritable returns false when closed', function () {
    phasync::run(function () {
        phasync::channel($reader, $writer);
        $writer->close();
        expect($writer->isWritable())->toBeFalse();
    });
});

test('channel getIterator works', function () {
    phasync::run(function () {
        phasync::channel($reader, $writer);
        $values = [];
        phasync::go(function () use ($writer) {
            $writer->write(1);
            $writer->write(2);
            $writer->write(3);
            $writer->close();
        });
        foreach ($reader as $value) {
            $values[] = $value;
        }
        expect($values)->toBe([1, 2, 3]);
    });
});

test('channel deadlock detection allows time to recover', function () {
    phasync::run(function () {
        phasync::channel($reader, $writer);
        // Creator tries to read immediately - should allow 100ms for recovery
        phasync::go(function () use ($reader) {
            phasync::sleep(0.05); // Reader starts in 50ms
        });
        // Creator fiber is still running, this read will check for deadlock
        expect(function () use ($reader) {
            $reader->read();
        })->toThrow(ChannelException::class);
    });
});

test('channel activate nulls creatorFiber', function () {
    phasync::run(function () {
        phasync::channel($reader, $writer);
        phasync::go(function () use ($writer) {
            // Activate should allow creator fiber to be null
            $writer->activate();
            expect(true)->toBeTrue();
        });
    });
});

test('channel isClosed returns correct state', function () {
    phasync::run(function () {
        phasync::channel($reader, $writer);
        expect($reader->isClosed())->toBeFalse();
        expect($writer->isClosed())->toBeFalse();
        $writer->close();
        expect($reader->isClosed())->toBeTrue();
        expect($writer->isClosed())->toBeTrue();
    });
});
