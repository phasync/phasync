<?php

use phasync\Psr\BufferedStream;
use phasync\TimeoutException;

test('BufferedStream __toString returns content successfully', function () {
    phasync::run(function () {
        $stream = new BufferedStream(1024, 10);
        phasync::go(function () use ($stream) {
            $stream->append('Hello ');
            $stream->append('World');
            $stream->end();
        });

        phasync::sleep(0.05);
        expect((string) $stream)->toBe('Hello World');
    });
});

test('BufferedStream __toString returns error message on exception', function () {
    phasync::run(function () {
        $stream = new BufferedStream(1024, 0.05);

        // Don't call end(), so getContents() will timeout
        $result = (string) $stream;
        expect($result)->toContain('Stream Error');
    });
});

test('BufferedStream close() waits for writer and closes', function () {
    phasync::run(function () {
        $stream = new BufferedStream(1024, 10);
        phasync::go(function () use ($stream) {
            phasync::sleep(0.05);
            $stream->append('data');
            $stream->end();
        });

        $stream->close();
        expect($stream->isReadable())->toBeFalse();
    });
});

test('BufferedStream close() clears buffer and file', function () {
    phasync::run(function () {
        $stream = new BufferedStream(16, 10);
        $stream->append('0123456789');
        $stream->append('abcdefghij');
        phasync::go(function () use ($stream) {
            phasync::sleep(0.01);
            $stream->end();
        });

        $stream->close();
        expect($stream->isReadable())->toBeFalse();
    });
});

test('BufferedStream detach() waits for writer and returns file', function () {
    phasync::run(function () {
        $stream = new BufferedStream(16, 10);
        phasync::go(function () use ($stream) {
            $stream->append('0123456789');
            $stream->append('abcdefghij');
            phasync::sleep(0.05);
            $stream->end();
        });

        $fp = $stream->detach();
        expect(\is_resource($fp))->toBeTrue();
        expect($stream->isReadable())->toBeFalse();

        \fclose($fp);
    });
});

test('BufferedStream detach() creates file if needed', function () {
    phasync::run(function () {
        $stream = new BufferedStream(1024, 10);
        phasync::go(function () use ($stream) {
            $stream->append('small');
            phasync::sleep(0.01);
            $stream->end();
        });

        $fp = $stream->detach();
        expect(\is_resource($fp))->toBeTrue();

        \fclose($fp);
    });
});

test('BufferedStream tell() returns read offset', function () {
    phasync::run(function () {
        $stream = new BufferedStream(1024, 10);
        phasync::go(function () use ($stream) {
            $stream->append('Hello World');
            $stream->end();
        });

        expect($stream->tell())->toBe(0);
        $stream->read(5);
        expect($stream->tell())->toBe(5);
    });
});

test('BufferedStream eof() checks offset and end offset', function () {
    phasync::run(function () {
        $stream = new BufferedStream(1024, 10);
        phasync::go(function () use ($stream) {
            $stream->append('Hello');
            $stream->end();
        });

        phasync::sleep(0.01);
        expect($stream->eof())->toBeFalse();
        $stream->read(10);
        expect($stream->eof())->toBeTrue();
    });
});

test('BufferedStream seek() constrains offset with SEEK_SET', function () {
    phasync::run(function () {
        $stream = new BufferedStream(1024, 10);
        $stream->append('0123456789');
        $stream->append('abcdefghij');
        phasync::go(function () use ($stream) {
            phasync::sleep(0.01);
            $stream->end();
        });

        $stream->seek(5);
        expect($stream->tell())->toBe(5);
    });
});

test('BufferedStream seek() with SEEK_CUR adds offset', function () {
    phasync::run(function () {
        $stream = new BufferedStream(1024, 10);
        $stream->append('0123456789');
        phasync::go(function () use ($stream) {
            phasync::sleep(0.01);
            $stream->end();
        });

        $stream->read(3);
        $stream->seek(2, \SEEK_CUR);
        expect($stream->tell())->toBe(5);
    });
});

test('BufferedStream seek() with SEEK_END positions from end', function () {
    phasync::run(function () {
        $stream = new BufferedStream(1024, 10);
        $stream->append('0123456789');
        phasync::go(function () use ($stream) {
            phasync::sleep(0.01);
            $stream->end();
        });

        $stream->seek(-3, \SEEK_END);
        expect($stream->tell())->toBe(7);
    });
});

test('BufferedStream seek() constrains to writeOffset', function () {
    phasync::run(function () {
        $stream = new BufferedStream(1024, 10);
        $stream->append('01234');
        phasync::go(function () use ($stream) {
            phasync::sleep(0.01);
            $stream->end();
        });

        $stream->seek(100);
        expect($stream->tell())->toBe(5); // Limited to writeOffset
    });
});

test('BufferedStream seek() clamps negative offset to zero', function () {
    phasync::run(function () {
        $stream = new BufferedStream(1024, 10);
        $stream->append('01234');
        phasync::go(function () use ($stream) {
            phasync::sleep(0.01);
            $stream->end();
        });

        $stream->seek(-10);
        expect($stream->tell())->toBe(0);
    });
});

test('BufferedStream rewind() calls seek(0)', function () {
    phasync::run(function () {
        $stream = new BufferedStream(1024, 10);
        $stream->append('0123456789');
        phasync::go(function () use ($stream) {
            phasync::sleep(0.01);
            $stream->end();
        });

        $stream->read(5);
        $stream->rewind();
        expect($stream->tell())->toBe(0);
    });
});

test('BufferedStream isWritable() always returns false', function () {
    phasync::run(function () {
        $stream = new BufferedStream();
        expect($stream->isWritable())->toBeFalse();
    });
});

test('BufferedStream write() always throws', function () {
    phasync::run(function () {
        $stream = new BufferedStream();
        expect(function () use ($stream) {
            $stream->write('data');
        })->toThrow(RuntimeException::class, 'not writable');
    });
});

test('BufferedStream isReadable() returns true until closed or detached', function () {
    phasync::run(function () {
        $stream = new BufferedStream();
        expect($stream->isReadable())->toBeTrue();

        phasync::go(function () use ($stream) {
            phasync::sleep(0.01);
            $stream->end();
        });

        $stream->close();
        expect($stream->isReadable())->toBeFalse();
    });
});

test('BufferedStream isReadable() returns false when detached', function () {
    phasync::run(function () {
        $stream = new BufferedStream();
        phasync::go(function () use ($stream) {
            phasync::sleep(0.01);
            $stream->end();
        });

        $stream->detach();
        expect($stream->isReadable())->toBeFalse();
    });
});

test('BufferedStream read() throws when detached', function () {
    phasync::run(function () {
        $stream = new BufferedStream();
        phasync::go(function () use ($stream) {
            phasync::sleep(0.01);
            $stream->end();
        });

        $stream->detach();
        expect(function () use ($stream) {
            $stream->read(5);
        })->toThrow(RuntimeException::class, 'detached');
    });
});

test('BufferedStream read() throws when closed', function () {
    phasync::run(function () {
        $stream = new BufferedStream();
        phasync::go(function () use ($stream) {
            phasync::sleep(0.01);
            $stream->end();
        });

        $stream->close();
        expect(function () use ($stream) {
            $stream->read(5);
        })->toThrow(RuntimeException::class, 'closed');
    });
});

test('BufferedStream read() waits for content', function () {
    phasync::run(function () {
        $stream  = new BufferedStream(1024, 10);
        $results = [];

        phasync::go(function () use ($stream, &$results) {
            phasync::sleep(0.05);
            $results[] = 'before';
            $stream->append('delayed');
            $stream->end();
            $results[] = 'after';
        });

        $content   = $stream->read(10);
        $results[] = 'read';

        expect($content)->toBe('delayed');
        expect($results)->toBe(['before', 'after', 'read']);
    });
});

test('BufferedStream read() from file', function () {
    phasync::run(function () {
        $stream = new BufferedStream(16, 10);
        phasync::go(function () use ($stream) {
            $stream->append('0123456789');
            $stream->append('abcdefghij');
            phasync::sleep(0.01);
            $stream->end();
        });

        phasync::sleep(0.05);
        $content = $stream->read(100);
        expect($content)->toBe('0123456789abcdefghij');
    });
});

test('BufferedStream append() throws after end()', function () {
    phasync::run(function () {
        $stream = new BufferedStream(1024, 10);
        $stream->append('data');
        $stream->end();

        expect(function () use ($stream) {
            $stream->append('more');
        })->toThrow(LogicException::class, "can't be appended to after ending");
    });
});

test('BufferedStream append() transitions to file when exceeding buffer', function () {
    phasync::run(function () {
        $stream = new BufferedStream(16, 10);
        $stream->append('0123456789');
        $stream->append('abcdefghij');
        phasync::go(function () use ($stream) {
            phasync::sleep(0.01);
            $stream->end();
        });

        phasync::sleep(0.05);
        expect($stream->getContents())->toBe('0123456789abcdefghij');
    });
});

test('BufferedStream end() throws when called twice', function () {
    phasync::run(function () {
        $stream = new BufferedStream(1024, 10);
        $stream->end();

        expect(function () use ($stream) {
            $stream->end();
        })->toThrow(LogicException::class, 'already ended');
    });
});

test('BufferedStream getMetadata() returns metadata from buffer', function () {
    phasync::run(function () {
        $stream = new BufferedStream(1024, 10);
        $stream->append('test');

        $meta = $stream->getMetadata();
        expect($meta)->toHaveKey('mode');
        expect($meta['mode'])->toBe('r');
        expect($meta['seekable'])->toBeFalse();
    });
});

test('BufferedStream getMetadata() returns metadata from file', function () {
    phasync::run(function () {
        $stream = new BufferedStream(16, 10);
        $stream->append('0123456789');
        $stream->append('abcdefghij');
        phasync::go(function () use ($stream) {
            phasync::sleep(0.01);
            $stream->end();
        });

        phasync::sleep(0.05);
        $meta = $stream->getMetadata();
        expect($meta)->toHaveKey('mode');
    });
});

test('BufferedStream getMetadata() specific key', function () {
    phasync::run(function () {
        $stream = new BufferedStream();
        $stream->append('data');
        $stream->end();

        expect($stream->getMetadata('mode'))->toBe('r');
        expect($stream->getMetadata('nonexistent'))->toBeNull();
    });
});

test('BufferedStream getSize() waits for end', function () {
    phasync::run(function () {
        $stream  = new BufferedStream(1024, 10);
        $results = [];

        phasync::go(function () use ($stream, &$results) {
            phasync::sleep(0.05);
            $stream->append('Hello');
            $results[] = 'appended';
            $stream->end();
            $results[] = 'ended';
        });

        $size      = $stream->getSize();
        $results[] = 'got_size';

        expect($size)->toBe(5);
        expect($results)->toContain('appended');
    });
});

test('BufferedStream blockUntilEnded throws TimeoutException on timeout', function () {
    phasync::run(function () {
        $stream = new BufferedStream(1024, 0.05);
        $stream->append('data');

        expect(function () use ($stream) {
            $stream->getSize();
        })->toThrow(TimeoutException::class);
    });
});

test('BufferedStream append() handles write to file', function () {
    phasync::run(function () {
        $stream = new BufferedStream(16, 10);
        $stream->append('0123456789');
        $stream->append('abcdefghij');
        $stream->append('KLMNOPQRST');

        phasync::go(function () use ($stream) {
            phasync::sleep(0.01);
            $stream->end();
        });

        phasync::sleep(0.05);
        expect($stream->getContents())->toBe('0123456789abcdefghijKLMNOPQRST');
    });
});

test('BufferedStream multiple readers wait correctly', function () {
    phasync::run(function () {
        $stream  = new BufferedStream(1024, 10);
        $results = [];

        phasync::go(function () use ($stream, &$results) {
            phasync::sleep(0.01);
            $stream->append('A');
            phasync::sleep(0.01);
            $stream->append('B');
            phasync::sleep(0.01);
            $stream->append('C');
            $stream->end();
        });

        $a = phasync::go(function () use ($stream, &$results) {
            $results[] = $stream->read(1);
        });
        $b = phasync::go(function () use ($stream, &$results) {
            phasync::sleep(0.025);
            $results[] = $stream->read(1);
        });

        phasync::await($a);
        phasync::await($b);

        expect(\count($results))->toBe(2);
    });
});

test('BufferedStream getContents() before end waits', function () {
    phasync::run(function () {
        $stream  = new BufferedStream(1024, 10);
        $results = [];

        phasync::go(function () use ($stream, &$results) {
            phasync::sleep(0.05);
            $stream->append('Delayed');
            $results[] = 'written';
            $stream->end();
        });

        $contents = $stream->getContents();
        expect($contents)->toBe('Delayed');
        expect($results[0])->toBe('written');
    });
});
