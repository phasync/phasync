<?php

use phasync\Psr\UnbufferedStream;

test('constructor stores buffer size and deadlock timeout', function () {
    phasync::run(function () {
        $stream = new UnbufferedStream(128, 5.0);
        expect($stream->getMetadata('stream_type'))->toBe('custom');
    });
});

test('constructor defaults buffer size and timeout', function () {
    phasync::run(function () {
        $stream = new UnbufferedStream();
        expect($stream->getMetadata('stream_type'))->toBe('custom');
    });
});

test('append adds data to buffer', function () {
    phasync::run(function () {
        $stream = new UnbufferedStream(1024);
        $stream->append('Hello');
        expect($stream->getMetadata('unread_bytes'))->toBe(5);
    });
});

test('append throws after end()', function () {
    phasync::run(function () {
        $stream = new UnbufferedStream(1024);
        $stream->end();
        expect(fn () => $stream->append('data'))->toThrow(RuntimeException::class);
    });
});

test('end() prevents further appends', function () {
    phasync::run(function () {
        $stream = new UnbufferedStream(1024);
        $stream->append('data');
        $stream->end();
        expect(fn () => $stream->append('more'))->toThrow(RuntimeException::class, "Can't append");
    });
});

test('end() called twice throws', function () {
    phasync::run(function () {
        $stream = new UnbufferedStream(1024);
        $stream->end();
        expect(fn () => $stream->end())->toThrow(RuntimeException::class, 'already been ended');
    });
});

test('read returns data from buffer', function () {
    phasync::run(function () {
        $stream = new UnbufferedStream(1024);
        $stream->append('Hello World');
        $stream->end();
        expect($stream->read(5))->toBe('Hello');
        expect($stream->read(6))->toBe(' World');
    });
});

test('read respects partial chunk length', function () {
    phasync::run(function () {
        $stream = new UnbufferedStream(1024);
        $stream->append('Hello World');
        $stream->end();
        expect($stream->read(3))->toBe('Hel');
        expect($stream->read(2))->toBe('lo');
    });
});

test('read from closed stream throws', function () {
    phasync::run(function () {
        $stream = new UnbufferedStream(1024);
        phasync::go(function () use ($stream) {
            $stream->append('test');
            $stream->end();
        });
        $stream->close();
        expect(fn () => $stream->read(10))->toThrow(RuntimeException::class, 'not valid');
    });
});

test('read from detached stream throws', function () {
    phasync::run(function () {
        $stream = new UnbufferedStream(1024);
        phasync::go(function () use ($stream) {
            $stream->append('test');
            $stream->end();
        });
        $stream->detach();
        expect(fn () => $stream->read(10))->toThrow(RuntimeException::class, 'not valid');
    });
});

test('detach closes the stream', function () {
    phasync::run(function () {
        $stream = new UnbufferedStream(1024);
        phasync::go(function () use ($stream) {
            $stream->append('data');
            $stream->end();
        });
        $stream->detach();
        expect(fn () => $stream->read(10))->toThrow(RuntimeException::class);
    });
});

test('tell returns bytes read', function () {
    phasync::run(function () {
        $stream = new UnbufferedStream(1024);
        $stream->append('Hello World');
        $stream->end();
        expect($stream->tell())->toBe(0);
        $stream->read(5);
        expect($stream->tell())->toBe(5);
        $stream->read(6);
        expect($stream->tell())->toBe(11);
    });
});

test('eof returns false when data in buffer', function () {
    phasync::run(function () {
        $stream = new UnbufferedStream(1024);
        $stream->append('data');
        expect($stream->eof())->toBeFalse();
    });
});

test('eof returns false when not ended', function () {
    phasync::run(function () {
        $stream = new UnbufferedStream(1024);
        expect($stream->eof())->toBeFalse();
    });
});

test('eof returns true when ended and buffer empty', function () {
    phasync::run(function () {
        $stream = new UnbufferedStream(1024);
        $stream->append('data');
        $stream->end();
        $stream->read(10);
        expect($stream->eof())->toBeTrue();
    });
});

test('getSize returns null', function () {
    phasync::run(function () {
        $stream = new UnbufferedStream(1024);
        expect($stream->getSize())->toBeNull();
    });
});

test('isSeekable returns false', function () {
    phasync::run(function () {
        $stream = new UnbufferedStream(1024);
        expect($stream->isSeekable())->toBeFalse();
    });
});

test('seek throws', function () {
    phasync::run(function () {
        $stream = new UnbufferedStream(1024);
        expect(fn () => $stream->seek(0))->toThrow(RuntimeException::class, 'not seekable');
    });
});

test('rewind throws', function () {
    phasync::run(function () {
        $stream = new UnbufferedStream(1024);
        expect(fn () => $stream->rewind())->toThrow(RuntimeException::class, 'not seekable');
    });
});

test('isWritable returns false', function () {
    phasync::run(function () {
        $stream = new UnbufferedStream(1024);
        expect($stream->isWritable())->toBeFalse();
    });
});

test('write throws', function () {
    phasync::run(function () {
        $stream = new UnbufferedStream(1024);
        expect(fn () => $stream->write('test'))->toThrow(RuntimeException::class, 'not writable');
    });
});

test('isReadable returns true when open', function () {
    phasync::run(function () {
        $stream = new UnbufferedStream(1024);
        expect($stream->isReadable())->toBeTrue();
    });
});

test('isReadable returns false when closed', function () {
    phasync::run(function () {
        $stream = new UnbufferedStream(1024);
        phasync::go(function () use ($stream) {
            $stream->append('data');
            $stream->end();
        });
        $stream->close();
        expect($stream->isReadable())->toBeFalse();
    });
});

test('isReadable returns false when detached', function () {
    phasync::run(function () {
        $stream = new UnbufferedStream(1024);
        phasync::go(function () use ($stream) {
            $stream->append('data');
            $stream->end();
        });
        $stream->detach();
        expect($stream->isReadable())->toBeFalse();
    });
});

test('getContents reads all remaining data', function () {
    phasync::run(function () {
        $stream = new UnbufferedStream(1024);
        $stream->append('Hello');
        $stream->append(' ');
        $stream->append('World');
        $stream->end();
        expect($stream->getContents())->toBe('Hello World');
    });
});

test('the coroutine that created the stream may convert it to a string, waiting for the writer', function () {
    phasync::run(function () {
        $stream = new UnbufferedStream(4);
        phasync::go(function () use ($stream) {
            $stream->append('hello ');
            phasync::sleep(0.01);
            $stream->append('world');
            $stream->end();
        });
        expect((string) $stream)->toBe('hello world');
    });
});

test('a creator that converts a stream nothing writes to fails run() with a DeadlockException', function () {
    expect(fn () => phasync::run(function () {
        return (string) new UnbufferedStream();
    }))->toThrow(phasync\DeadlockException::class);
});

test('getMetadata returns correct keys', function () {
    phasync::run(function () {
        $stream = new UnbufferedStream(1024);
        $stream->append('test');
        $meta = $stream->getMetadata();

        expect($meta)->toHaveKey('timed_out');
        expect($meta)->toHaveKey('blocked');
        expect($meta)->toHaveKey('unread_bytes');
        expect($meta)->toHaveKey('stream_type');
        expect($meta)->toHaveKey('wrapper_type');
        expect($meta)->toHaveKey('wrapper_data');
        expect($meta)->toHaveKey('mode');
        expect($meta)->toHaveKey('seekable');
        expect($meta)->toHaveKey('uri');
        expect($meta)->toHaveKey('eof');
    });
});

test('getMetadata with key returns specific value', function () {
    phasync::run(function () {
        $stream = new UnbufferedStream(1024);
        $stream->append('12345');
        $stream->end();

        expect($stream->getMetadata('mode'))->toBe('r');
        expect($stream->getMetadata('seekable'))->toBeFalse();
        expect($stream->getMetadata('unread_bytes'))->toBe(5);
    });
});

test('__toString returns data when called from different coroutine', function () {
    phasync::run(function () {
        $stream = new UnbufferedStream(1024);
        $result = '';

        phasync::go(function () use ($stream) {
            $stream->append('Hello');
            $stream->append(' ');
            $stream->append('World');
            $stream->end();
        });

        phasync::go(function () use ($stream, &$result) {
            $result = (string) $stream;
        });

        phasync::sleep(0.05);
        expect($result)->toBe('Hello World');
    });
});

test('__toString returns error after detach from different coroutine', function () {
    phasync::run(function () {
        $stream = new UnbufferedStream(1024);
        $result = '';

        phasync::go(function () use ($stream) {
            $stream->append('data');
            $stream->end();
        });

        phasync::go(function () use ($stream, &$result) {
            $stream->detach();
            $result = (string) $stream;
        });

        phasync::sleep(0.02);
        expect($result)->toContain('Stream Error');
        expect($result)->toContain('Detached');
    });
});

test('__toString returns error after close from different coroutine', function () {
    phasync::run(function () {
        $stream = new UnbufferedStream(1024);
        $result = '';

        phasync::go(function () use ($stream) {
            $stream->append('data');
            $stream->end();
        });

        phasync::go(function () use ($stream, &$result) {
            $stream->close();
            $result = (string) $stream;
        });

        phasync::sleep(0.02);
        expect($result)->toContain('Stream Error');
        expect($result)->toContain('Closed');
    });
});

test('close reads and discards remaining data', function () {
    phasync::run(function () {
        $stream = new UnbufferedStream(1024);

        phasync::go(function () use ($stream) {
            $stream->append('Part1');
            phasync::sleep(0.005);
            $stream->append('Part2');
            phasync::sleep(0.005);
            $stream->append('Part3');
            $stream->end();
        });

        $stream->close();
        expect($stream->isReadable())->toBeFalse();
    });
});

test('read waits when buffer is empty and stream not ended', function () {
    phasync::run(function () {
        $stream = new UnbufferedStream(1024);

        phasync::go(function () use ($stream) {
            phasync::sleep(0.01);
            $stream->append('Delayed data');
            $stream->end();
        });

        $result = $stream->read(12);
        expect($result)->toBe('Delayed data');
    });
});

test('append waits when buffer exceeds size limit', function () {
    phasync::run(function () {
        $stream = new UnbufferedStream(10);

        phasync::go(function () use ($stream) {
            $stream->append('12345');
            phasync::sleep(0.01);
            $stream->read(3);
            phasync::sleep(0.01);
            $stream->end();
        });

        $stream->append('67890AB');
        expect($stream->getMetadata('unread_bytes'))->toBeGreaterThanOrEqual(0);
    });
});
