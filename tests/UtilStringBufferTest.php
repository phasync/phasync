<?php

use phasync\TimeoutException;
use phasync\Util\StringBuffer;

test('StringBuffer is initially empty', function () {
    $buffer = new StringBuffer();
    expect($buffer->isEmpty())->toBeTrue();
    expect($buffer->isReady())->toBeFalse();
    expect($buffer->eof())->toBeFalse();
});

test('write and read', function () {
    phasync::run(function () {
        $buffer = new StringBuffer();
        $buffer->write('hello');
        expect($buffer->isEmpty())->toBeFalse();
        expect($buffer->isReady())->toBeTrue();

        $data = $buffer->read(5);
        expect($data)->toBe('hello');
        expect($buffer->isEmpty())->toBeTrue();
    });
});

test('read with maxLength less than available', function () {
    phasync::run(function () {
        $buffer = new StringBuffer();
        $buffer->write('hello world');

        $part1 = $buffer->read(5);
        expect($part1)->toBe('hello');

        $part2 = $buffer->read(6);
        expect($part2)->toBe(' world');
    });
});

test('readFixed waits for exact length', function () {
    phasync::run(function () {
        $buffer = new StringBuffer();

        phasync::go(function () use ($buffer) {
            phasync::sleep(0.01);
            $buffer->write('hello world');
            $buffer->end();
        });

        $data = $buffer->readFixed(5);
        expect($data)->toBe('hello');

        $data = $buffer->readFixed(6);
        expect($data)->toBe(' world');

        $data = $buffer->readFixed(1, 0);
        expect($data)->toBeNull();
    });
});

test('readFixed returns null at end with insufficient data', function () {
    phasync::run(function () {
        $buffer = new StringBuffer();
        $buffer->write('hi');
        $buffer->end();

        $data = $buffer->readFixed(5);
        expect($data)->toBeNull();
    });
});

test('end() marks buffer as ended', function () {
    phasync::run(function () {
        $buffer = new StringBuffer();
        $buffer->write('data');
        $buffer->end();

        expect($buffer->eof())->toBeFalse();
        $buffer->read(4);
        expect($buffer->eof())->toBeTrue();
    });
});

test('end() throws if already ended', function () {
    phasync::run(function () {
        $buffer = new StringBuffer();
        $buffer->end();

        expect(fn () => $buffer->end())
            ->toThrow(LogicException::class, 'already ended');
    });
});

test('write after end throws', function () {
    phasync::run(function () {
        $buffer = new StringBuffer();
        $buffer->end();

        expect(fn () => $buffer->write('data'))
            ->toThrow(RuntimeException::class, 'ended');
    });
});

test('read with negative maxLength throws', function () {
    phasync::run(function () {
        $buffer = new StringBuffer();

        expect(fn () => $buffer->read(-1))
            ->toThrow(OutOfBoundsException::class);
    });
});

test('readFixed with negative length throws', function () {
    phasync::run(function () {
        $buffer = new StringBuffer();

        expect(fn () => $buffer->readFixed(-1))
            ->toThrow(OutOfBoundsException::class);
    });
});

test('maxSize limits buffer growth', function () {
    phasync::run(function () {
        $buffer = new StringBuffer(10);

        $buffer->write('12345');
        expect($buffer->isEmpty())->toBeFalse();

        $buffer->write('67890');
        expect($buffer->isEmpty())->toBeFalse();

        expect(fn () => $buffer->write('x', 0.001))
            ->toThrow(TimeoutException::class);
    });
});

test('maxSize enforced but timeout 0 writes past limit', function () {
    phasync::run(function () {
        $buffer = new StringBuffer(10);
        $buffer->write('12345');
        $buffer->write('67890');

        $buffer->write('x', 0); // timeout 0: never blocks
        expect($buffer->isEmpty())->toBeFalse();
    });
});

test('unread puts data back at front', function () {
    phasync::run(function () {
        $buffer = new StringBuffer();
        $buffer->write('hello world');
        $buffer->end();

        $part = $buffer->read(5);
        expect($part)->toBe('hello');

        $buffer->unread('hello');
        $data = $buffer->read(10);
        expect($data)->toBe('hello worl');
    });
});

test('unread throws on ended and empty buffer', function () {
    phasync::run(function () {
        $buffer = new StringBuffer();
        $buffer->end();

        expect(fn () => $buffer->unread('data'))
            ->toThrow(LogicException::class);
    });
});

test('isReady returns true when data is available', function () {
    phasync::run(function () {
        $buffer = new StringBuffer();
        expect($buffer->isReady())->toBeFalse();

        $buffer->write('data');
        expect($buffer->isReady())->toBeTrue();
    });
});

test('isReady returns true when ended', function () {
    phasync::run(function () {
        $buffer = new StringBuffer();
        $buffer->end();
        expect($buffer->isReady())->toBeTrue();
    });
});

test('await waits for data', function () {
    phasync::run(function () {
        $buffer = new StringBuffer();
        $ready  = false;

        phasync::go(function () use ($buffer, &$ready) {
            $buffer->await(0.5);
            $ready = true;
        });

        phasync::sleep(0.01);
        $buffer->write('data');
        phasync::sleep(0.05);

        expect($ready)->toBeTrue();
    });
});

test('await times out', function () {
    phasync::run(function () {
        $buffer = new StringBuffer();
        $start  = \microtime(true);
        $buffer->await(0.05);
        $elapsed = \microtime(true) - $start;

        expect($elapsed)->toBeGreaterThanOrEqual(0.04);
    });
});

test('maxSize null means unbounded', function () {
    phasync::run(function () {
        $buffer = new StringBuffer(null);

        for ($i = 0; $i < 100; ++$i) {
            $buffer->write(\str_repeat('x', 100));
        }
        expect($buffer->isEmpty())->toBeFalse();
    });
});

test('maxSize < 1 throws', function () {
    expect(fn () => new StringBuffer(0))
        ->toThrow(OutOfBoundsException::class, 'at least 1');
});

test('read timeout', function () {
    phasync::run(function () {
        $buffer = new StringBuffer();
        $start  = \microtime(true);

        expect(fn () => $buffer->read(10, 0.05))
            ->toThrow(TimeoutException::class);

        $elapsed = \microtime(true) - $start;
        expect($elapsed)->toBeGreaterThanOrEqual(0.04);
    });
});

test('readFixed timeout', function () {
    phasync::run(function () {
        $buffer = new StringBuffer();
        $start  = \microtime(true);

        expect(fn () => $buffer->readFixed(10, 0.05))
            ->toThrow(TimeoutException::class);

        $elapsed = \microtime(true) - $start;
        expect($elapsed)->toBeGreaterThanOrEqual(0.04);
    });
});

test('write with backpressure waits and then completes', function () {
    phasync::run(function () {
        $buffer = new StringBuffer(10);
        $buffer->write('1234567890');

        phasync::go(function () use ($buffer) {
            phasync::sleep(0.05);
            $buffer->read(5);
        });

        // The buffer is full: this waits for the reader
        $start = \microtime(true);
        $buffer->write('ABCDE', 0.5);
        expect(\microtime(true) - $start)->toBeGreaterThanOrEqual(0.04);

        $buffer->end();
        expect($buffer->read(100))->toBe('67890ABCDE');
    });
});

test('small reads from a long chunk split it past the waste threshold, and read on in order', function () {
    phasync::run(function () {
        $data   = \implode('', \array_map(static fn ($i) => \sprintf('%07d|', $i), \range(0, 99999))); // 800 000 bytes
        $buffer = new StringBuffer();
        $buffer->write($data);
        $buffer->end();

        $received = '';
        for ($i = 0; $i < 20000; ++$i) {
            $received .= $buffer->read(24); // past BUFFER_WASTE_THRESHOLD and half of it, with many reads left
        }
        // split: the chunk read now is a piece holding a whole number of 24-byte reads
        $length = (new ReflectionProperty(StringBuffer::class, 'length'))->getValue($buffer);
        expect($length % 24)->toBe(0)->and($length)->toBeLessThan(StringBuffer::BUFFER_WASTE_THRESHOLD + 24);
        $received .= $buffer->read(\strlen($data));
        expect($received)->toBe($data);
        expect($buffer->read(1))->toBe('');
    });
});

test('reads that never line up with the written chunks return the bytes in order', function () {
    phasync::run(function () {
        $data   = \implode('', \array_map(static fn ($i) => \sprintf('%07d|', $i), \range(0, 4095))); // 32 KB
        $buffer = new StringBuffer();
        foreach (\str_split($data, 4096) as $chunk) {
            $buffer->write($chunk);
        }
        $buffer->end();
        $received = $buffer->read(1024);
        while ('' !== $chunk = $buffer->read(4096)) {
            $received .= $chunk;
        }
        expect($received)->toBe($data);
    });
});

test('readFixed() of a frame spanning many chunks joins them in order and reads on from the last', function () {
    phasync::run(function () {
        $buffer = new StringBuffer();
        $buffer->write('ab');
        foreach (\range(1, 100) as $i) {
            $buffer->write(\sprintf('%03d', $i));
        }
        expect($buffer->read(1))->toBe('a');
        expect($buffer->readFixed(1 + 3 * 99 + 1))->toBe('b' . \implode('', \array_map(static fn ($i) => \sprintf('%03d', $i), \range(1, 99))) . '1');
        expect($buffer->read(10))->toBe('00');
    });
});

test('unread() of the bytes just read steps back; of other bytes, puts them in front of the rest', function () {
    phasync::run(function () {
        $buffer = new StringBuffer();
        $buffer->write('header:body');
        expect($buffer->read(7))->toBe('header:');
        $buffer->unread('header:');
        expect((new ReflectionProperty(StringBuffer::class, 'offset'))->getValue($buffer))->toBe(0);
        expect($buffer->read(7))->toBe('header:');
        $buffer->unread('NEW:');
        expect($buffer->read(100))->toBe('NEW:body');
    });
});

test('multiple chunks get merged correctly', function () {
    phasync::run(function () {
        $buffer = new StringBuffer();
        $buffer->write('a');
        $buffer->write('b');
        $buffer->write('c');
        $buffer->end();

        $data = $buffer->read(10);
        expect($data)->toBe('abc');
    });
});

test('read returns empty string when buffer is ended', function () {
    phasync::run(function () {
        $buffer = new StringBuffer();
        $buffer->write('data');
        $buffer->end();

        $buffer->read(4);
        $more = $buffer->read(100);
        expect($more)->toBe('');
    });
});

test('read with timeout 0 never blocks', function () {
    phasync::run(function () {
        $buffer = new StringBuffer();

        $data = $buffer->read(100, 0);
        expect($data)->toBe('');
    });
});

test('readFixed with timeout 0 returns null when not enough data', function () {
    phasync::run(function () {
        $buffer = new StringBuffer();
        $buffer->write('hi');

        $data = $buffer->readFixed(10, 0);
        expect($data)->toBeNull();
    });
});

test('write timeout occurs when maxSize blocks', function () {
    phasync::run(function () {
        $buffer = new StringBuffer(10);

        // Fill the buffer
        $buffer->write('0123456789', 0.5);

        // Try to write more with a short timeout
        expect(fn () => $buffer->write('x', 0.01))
            ->toThrow(TimeoutException::class);
    });
});

test('invalid resource to readFromResource throws', function () {
    $buffer = new StringBuffer();

    expect(fn () => $buffer->readFromResource('not a resource'))
        ->toThrow(InvalidArgumentException::class);
});

test('invalid resource to writeToResource throws', function () {
    $buffer = new StringBuffer();

    expect(fn () => $buffer->writeToResource('not a resource'))
        ->toThrow(InvalidArgumentException::class);
});

test('unread when buffer has offset', function () {
    phasync::run(function () {
        $buffer = new StringBuffer();
        $buffer->write(\str_repeat('x', 1000));
        $buffer->read(100);

        $buffer->unread('data');
        $result = $buffer->read(104);
        expect($result)->toBe('data' . \str_repeat('x', 100));
    });
});
