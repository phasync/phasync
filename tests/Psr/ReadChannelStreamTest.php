<?php

use phasync\Psr\ReadChannelStream;

test('ReadChannelStream reads from a channel', function () {
    phasync::run(function () {
        phasync::channel($read, $write);
        $stream = new ReadChannelStream($read);

        phasync::go(function () use ($write) {
            $write->write('Hello World');
            $write->close();
        });

        $data = $stream->read(5);
        expect($data)->toBe('Hello');

        $rest = $stream->read(100);
        expect($rest)->toBe(' World');
    });
});

test('ReadChannelStream __toString returns the entire content', function () {
    phasync::run(function () {
        phasync::channel($read, $write);
        $stream = new ReadChannelStream($read);

        phasync::go(function () use ($write) {
            $write->write('Test content');
            $write->close();
        });

        expect((string) $stream)->toBe('Test content');
    });
});

test('ReadChannelStream __toString returns error message on exception', function () {
    phasync::run(function () {
        phasync::channel($read, $write);
        $stream = new ReadChannelStream($read);

        phasync::go(function () use ($write) {
            $write->close();
        });

        expect((string) $stream)->toBe('');
    });
});

test('ReadChannelStream close() closes the source', function () {
    phasync::run(function () {
        phasync::channel($read, $write);
        $stream = new ReadChannelStream($read);

        expect($stream->isReadable())->toBeTrue();
        $stream->close();
        expect($stream->isReadable())->toBeFalse();
    });
});

test('ReadChannelStream close() can be called multiple times', function () {
    phasync::run(function () {
        phasync::channel($read, $write);
        $stream = new ReadChannelStream($read);

        $stream->close();
        $stream->close();
        expect($stream->isReadable())->toBeFalse();
    });
});

test('ReadChannelStream detach() closes and returns null', function () {
    phasync::run(function () {
        phasync::channel($read, $write);
        $stream = new ReadChannelStream($read);

        $result = $stream->detach();
        expect($result)->toBeNull();
        expect($stream->isReadable())->toBeFalse();
    });
});

test('ReadChannelStream getSize() returns the configured size or null', function () {
    phasync::run(function () {
        phasync::channel($read, $write);
        $stream = new ReadChannelStream($read, 1024);

        expect($stream->getSize())->toBe(1024);

        phasync::channel($read2, $write2);
        $stream2 = new ReadChannelStream($read2);
        expect($stream2->getSize())->toBeNull();
    });
});

test('ReadChannelStream tell() returns current offset', function () {
    phasync::run(function () {
        phasync::channel($read, $write);
        $stream = new ReadChannelStream($read);

        phasync::go(function () use ($write) {
            $write->write('0123456789');
            $write->close();
        });

        expect($stream->tell())->toBe(0);
        $stream->read(5);
        expect($stream->tell())->toBe(5);
    });
});

test('ReadChannelStream eof() returns true at end', function () {
    phasync::run(function () {
        phasync::channel($read, $write);
        $stream = new ReadChannelStream($read);

        phasync::go(function () use ($write) {
            $write->write('short');
            $write->close();
        });

        expect($stream->eof())->toBeFalse();
        expect($stream->read(10))->toBe('short');
        // The channel's end is only discovered by reading past the last chunk
        expect($stream->eof())->toBeFalse();
        expect($stream->read(10))->toBe('');
        expect($stream->eof())->toBeTrue();
    });
});

test('ReadChannelStream eof() considers size limit', function () {
    phasync::run(function () {
        phasync::channel($read, $write);
        $stream = new ReadChannelStream($read, 5);

        phasync::go(function () use ($write) {
            $write->write('0123456789');
            $write->close();
        });

        $stream->read(5);
        expect($stream->eof())->toBeTrue();
    });
});

test('ReadChannelStream isSeekable() returns false', function () {
    phasync::run(function () {
        phasync::channel($read, $write);
        $stream = new ReadChannelStream($read);

        expect($stream->isSeekable())->toBeFalse();
    });
});

test('ReadChannelStream seek() throws RuntimeException', function () {
    phasync::run(function () {
        phasync::channel($read, $write);
        $stream = new ReadChannelStream($read);

        expect(function () use ($stream) {
            $stream->seek(0);
        })->toThrow(RuntimeException::class, 'Not a seekable stream');
    });
});

test('ReadChannelStream rewind() throws RuntimeException', function () {
    phasync::run(function () {
        phasync::channel($read, $write);
        $stream = new ReadChannelStream($read);

        expect(function () use ($stream) {
            $stream->rewind();
        })->toThrow(RuntimeException::class, 'Not a seekable stream');
    });
});

test('ReadChannelStream isWritable() returns false', function () {
    phasync::run(function () {
        phasync::channel($read, $write);
        $stream = new ReadChannelStream($read);

        expect($stream->isWritable())->toBeFalse();
    });
});

test('ReadChannelStream write() throws RuntimeException', function () {
    phasync::run(function () {
        phasync::channel($read, $write);
        $stream = new ReadChannelStream($read);

        expect(function () use ($stream) {
            $stream->write('data');
        })->toThrow(RuntimeException::class, 'Not writable');
    });
});

test('ReadChannelStream isReadable() returns true before close', function () {
    phasync::run(function () {
        phasync::channel($read, $write);
        $stream = new ReadChannelStream($read);

        expect($stream->isReadable())->toBeTrue();
    });
});

test('ReadChannelStream isReadable() returns false after close', function () {
    phasync::run(function () {
        phasync::channel($read, $write);
        $stream = new ReadChannelStream($read);

        $stream->close();
        expect($stream->isReadable())->toBeFalse();
    });
});

test('ReadChannelStream read() throws when closed', function () {
    phasync::run(function () {
        phasync::channel($read, $write);
        $stream = new ReadChannelStream($read);

        $stream->close();
        expect(function () use ($stream) {
            $stream->read(5);
        })->toThrow(RuntimeException::class, 'Not readable');
    });
});

test('ReadChannelStream read() throws when channel sends non-string', function () {
    phasync::run(function () {
        phasync::channel($read, $write);
        $stream = new ReadChannelStream($read);

        phasync::go(function () use ($write) {
            $write->write(123);
            $write->close();
        });

        expect(function () use ($stream) {
            $stream->read(5);
        })->toThrow(RuntimeException::class, 'not a string');
    });
});

test('ReadChannelStream read() respects length limit', function () {
    phasync::run(function () {
        phasync::channel($read, $write);
        $stream = new ReadChannelStream($read);

        phasync::go(function () use ($write) {
            $write->write('0123456789');
            $write->close();
        });

        $data = $stream->read(3);
        expect($data)->toBe('012');
        expect(\strlen($data))->toBe(3);
    });
});

test('ReadChannelStream read() handles pending buffer', function () {
    phasync::run(function () {
        phasync::channel($read, $write);
        $stream = new ReadChannelStream($read);

        phasync::go(function () use ($write) {
            $write->write('abcdefghij');
            $write->close();
        });

        // First read takes 5, leaving "fghij" pending
        $first = $stream->read(5);
        expect($first)->toBe('abcde');

        // Second read takes 3 from pending, leaving "ij"
        $second = $stream->read(3);
        expect($second)->toBe('fgh');

        // Third read takes remaining 2
        $third = $stream->read(10);
        expect($third)->toBe('ij');
    });
});

test('ReadChannelStream read() returns empty string when eof reached', function () {
    phasync::run(function () {
        phasync::channel($read, $write);
        $stream = new ReadChannelStream($read);

        phasync::go(function () use ($write) {
            $write->write('short');
            $write->close();
        });

        $stream->read(10);
        $empty = $stream->read(10);
        expect($empty)->toBe('');
    });
});

test('ReadChannelStream getContents() reads all remaining', function () {
    phasync::run(function () {
        phasync::channel($read, $write);
        $stream = new ReadChannelStream($read);

        phasync::go(function () use ($write) {
            $write->write('Hello ');
            $write->write('World');
            $write->close();
        });

        $contents = $stream->getContents();
        expect($contents)->toBe('Hello World');
    });
});

test('ReadChannelStream getMetadata() returns full metadata', function () {
    phasync::run(function () {
        phasync::channel($read, $write);
        $stream = new ReadChannelStream($read);

        $meta = $stream->getMetadata();
        expect($meta)->toHaveKey('timed_out');
        expect($meta)->toHaveKey('blocked');
        expect($meta)->toHaveKey('eof');
        expect($meta)->toHaveKey('stream_type');
        expect($meta['stream_type'])->toBe('phasync-channel');
        expect($meta['mode'])->toBe('r');
        expect($meta['seekable'])->toBeFalse();
    });
});

test('ReadChannelStream getMetadata() returns specific key', function () {
    phasync::run(function () {
        phasync::channel($read, $write);
        $stream = new ReadChannelStream($read);

        expect($stream->getMetadata('mode'))->toBe('r');
        expect($stream->getMetadata('seekable'))->toBeFalse();
        expect($stream->getMetadata('nonexistent'))->toBeNull();
    });
});

test('ReadChannelStream handles multiple chunks from channel', function () {
    phasync::run(function () {
        phasync::channel($read, $write);
        $stream = new ReadChannelStream($read);

        phasync::go(function () use ($write) {
            for ($i = 0; $i < 5; ++$i) {
                $write->write("chunk$i");
            }
            $write->close();
        });

        $content = $stream->getContents();
        expect($content)->toBe('chunk0chunk1chunk2chunk3chunk4');
    });
});

test('ReadChannelStream __toString catches and formats RuntimeException', function () {
    phasync::run(function () {
        phasync::channel($read, $write);
        $stream = new ReadChannelStream($read);

        // Close the source immediately to make reads fail
        $stream->close();

        // Now __toString should catch the RuntimeException
        $result = (string) $stream;
        expect($result)->toContain('Error:');
    });
});

test('ReadChannelStream read() returns empty when already at EOF with size limit', function () {
    phasync::run(function () {
        phasync::channel($read, $write);
        $stream = new ReadChannelStream($read, 5);

        phasync::go(function () use ($write) {
            $write->write('abcde');
            $write->close();
        });

        // Read exactly to the size limit
        $stream->read(5);

        // Now we're at EOF, reading again should return empty string
        $empty = $stream->read(10);
        expect($empty)->toBe('');
    });
});
