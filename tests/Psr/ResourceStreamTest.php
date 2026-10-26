<?php

use phasync\Psr\ResourceStream;

test('ResourceStream wraps a stream resource', function () {
    phasync::run(function () {
        $fp = \fopen('php://temp', 'w+');
        \fwrite($fp, 'Hello World');
        \rewind($fp);

        $stream = new ResourceStream($fp);
        expect($stream->isReadable())->toBeTrue();
        expect($stream->read(5))->toBe('Hello');
    });
});

test('ResourceStream wraps a StreamInterface', function () {
    phasync::run(function () {
        $fp = \fopen('php://temp', 'w+');
        \fwrite($fp, 'Test');
        \rewind($fp);

        $stream = new ResourceStream($fp);
        expect($stream->isReadable())->toBeTrue();
    });
});

test('ResourceStream constructor throws on invalid resource', function () {
    expect(function () {
        new ResourceStream('not a resource');
    })->toThrow(InvalidArgumentException::class, 'Not a stream resource');
});

test('ResourceStream constructor throws on non-stream resource', function () {
    $handle = \curl_init();
    expect(function () use ($handle) {
        new ResourceStream($handle);
    })->toThrow(InvalidArgumentException::class, 'Not a stream resource');
    \curl_close($handle);
});

test('ResourceStream __toString reads content', function () {
    phasync::run(function () {
        $fp = \fopen('php://temp', 'w+');
        \fwrite($fp, 'Content here');
        \rewind($fp);

        $stream = new ResourceStream($fp);
        expect((string) $stream)->toBe('Content here');
    });
});

test('ResourceStream __toString returns empty for closed stream', function () {
    phasync::run(function () {
        $fp     = \fopen('php://temp', 'w+');
        $stream = new ResourceStream($fp);
        $stream->close();

        expect((string) $stream)->toBe('');
    });
});

test('ResourceStream __toString returns empty for zero-size stream', function () {
    phasync::run(function () {
        $fp     = \fopen('php://temp', 'w+');
        $stream = new ResourceStream($fp);

        expect((string) $stream)->toBe('');
    });
});

test('ResourceStream close() closes the resource', function () {
    phasync::run(function () {
        $fp     = \fopen('php://temp', 'w+');
        $stream = new ResourceStream($fp);

        expect($stream->isReadable())->toBeTrue();
        $stream->close();
        expect($stream->isReadable())->toBeFalse();
    });
});

test('ResourceStream close() on already closed is idempotent', function () {
    phasync::run(function () {
        $fp     = \fopen('php://temp', 'w+');
        $stream = new ResourceStream($fp);

        $stream->close();
        $stream->close();
        expect($stream->isReadable())->toBeFalse();
    });
});

test('ResourceStream detach() returns the resource', function () {
    phasync::run(function () {
        $fp     = \fopen('php://temp', 'w+');
        $stream = new ResourceStream($fp);

        $result = $stream->detach();
        expect(\is_resource($result))->toBeTrue();
        expect($stream->isReadable())->toBeFalse();

        \fclose($result);
    });
});

test('ResourceStream detach() on already detached returns null', function () {
    phasync::run(function () {
        $fp     = \fopen('php://temp', 'w+');
        $stream = new ResourceStream($fp);

        $stream->detach();
        $result = $stream->detach();
        expect($result)->toBeNull();
    });
});

test('ResourceStream getSize() returns file size', function () {
    phasync::run(function () {
        $fp = \fopen('php://temp', 'w+');
        \fwrite($fp, '123456789');
        \rewind($fp);

        $stream = new ResourceStream($fp);
        expect($stream->getSize())->toBe(9);
    });
});

test('ResourceStream getSize() returns null when closed', function () {
    phasync::run(function () {
        $fp     = \fopen('php://temp', 'w+');
        $stream = new ResourceStream($fp);
        $stream->close();

        expect($stream->getSize())->toBeNull();
    });
});

test('ResourceStream tell() returns current position', function () {
    phasync::run(function () {
        $fp = \fopen('php://temp', 'w+');
        \fwrite($fp, '0123456789');
        \rewind($fp);

        $stream = new ResourceStream($fp);
        expect($stream->tell())->toBe(0);
        $stream->read(5);
        expect($stream->tell())->toBe(5);
    });
});

test('ResourceStream tell() throws when closed', function () {
    phasync::run(function () {
        $fp     = \fopen('php://temp', 'w+');
        $stream = new ResourceStream($fp);
        $stream->close();

        expect(function () use ($stream) {
            $stream->tell();
        })->toThrow(RuntimeException::class, 'Stream is closed or detached');
    });
});

test('ResourceStream eof() returns true at end', function () {
    phasync::run(function () {
        $fp = \fopen('php://temp', 'w+');
        \fwrite($fp, 'short');
        \rewind($fp);

        $stream = new ResourceStream($fp);
        expect($stream->eof())->toBeFalse();
        $stream->read(100);
        expect($stream->eof())->toBeTrue();
    });
});

test('ResourceStream eof() returns true when closed', function () {
    phasync::run(function () {
        $fp     = \fopen('php://temp', 'w+');
        $stream = new ResourceStream($fp);
        $stream->close();

        expect($stream->eof())->toBeTrue();
    });
});

test('ResourceStream isSeekable() returns seekable status', function () {
    phasync::run(function () {
        $fp     = \fopen('php://temp', 'w+');
        $stream = new ResourceStream($fp);

        expect($stream->isSeekable())->toBeTrue();
        $stream->close();
        expect($stream->isSeekable())->toBeFalse();
    });
});

test('ResourceStream seek() seeks to position', function () {
    phasync::run(function () {
        $fp = \fopen('php://temp', 'w+');
        \fwrite($fp, '0123456789');
        \rewind($fp);

        $stream = new ResourceStream($fp);
        $stream->seek(5);
        expect($stream->tell())->toBe(5);
        expect($stream->read(3))->toBe('567');
    });
});

test('ResourceStream seek() with SEEK_CUR', function () {
    phasync::run(function () {
        $fp = \fopen('php://temp', 'w+');
        \fwrite($fp, '0123456789');
        \rewind($fp);

        $stream = new ResourceStream($fp);
        $stream->read(3);
        $stream->seek(2, \SEEK_CUR);
        expect($stream->tell())->toBe(5);
    });
});

test('ResourceStream seek() with SEEK_END', function () {
    phasync::run(function () {
        $fp = \fopen('php://temp', 'w+');
        \fwrite($fp, '0123456789');
        \rewind($fp);

        $stream = new ResourceStream($fp);
        $stream->seek(-3, \SEEK_END);
        expect($stream->tell())->toBe(7);
    });
});

test('ResourceStream seek() throws when closed', function () {
    phasync::run(function () {
        $fp     = \fopen('php://temp', 'w+');
        $stream = new ResourceStream($fp);
        $stream->close();

        expect(function () use ($stream) {
            $stream->seek(0);
        })->toThrow(RuntimeException::class, 'Stream is closed or detached');
    });
});

test('ResourceStream rewind() seeks to beginning', function () {
    phasync::run(function () {
        $fp = \fopen('php://temp', 'w+');
        \fwrite($fp, '0123456789');
        \rewind($fp);

        $stream = new ResourceStream($fp);
        $stream->read(5);
        $stream->rewind();
        expect($stream->tell())->toBe(0);
        expect($stream->read(3))->toBe('012');
    });
});

test('ResourceStream rewind() throws when closed', function () {
    phasync::run(function () {
        $fp     = \fopen('php://temp', 'w+');
        $stream = new ResourceStream($fp);
        $stream->close();

        expect(function () use ($stream) {
            $stream->rewind();
        })->toThrow(RuntimeException::class, 'Stream is closed or detached');
    });
});

test('ResourceStream isWritable() for read-only stream', function () {
    phasync::run(function () {
        $fp     = \fopen('php://memory', 'r');
        $stream = new ResourceStream($fp);

        expect($stream->isWritable())->toBeFalse();
    });
});

test('ResourceStream isWritable() for read-write stream', function () {
    phasync::run(function () {
        $fp     = \fopen('php://temp', 'w+');
        $stream = new ResourceStream($fp);

        expect($stream->isWritable())->toBeTrue();
    });
});

test('ResourceStream isWritable() respects mode override', function () {
    phasync::run(function () {
        $fp     = \fopen('php://temp', 'w+');
        $stream = new ResourceStream($fp, 'r');

        expect($stream->isWritable())->toBeFalse();
    });
});

test('ResourceStream isWritable() returns false when closed', function () {
    phasync::run(function () {
        $fp     = \fopen('php://temp', 'w+');
        $stream = new ResourceStream($fp);
        $stream->close();

        expect($stream->isWritable())->toBeFalse();
    });
});

test('ResourceStream write() writes data', function () {
    phasync::run(function () {
        $fp     = \fopen('php://temp', 'w+');
        $stream = new ResourceStream($fp);

        $written = $stream->write('Hello');
        expect($written)->toBe(5);

        $stream->rewind();
        expect($stream->read(5))->toBe('Hello');
    });
});

test('ResourceStream write() throws when not writable', function () {
    phasync::run(function () {
        $fp     = \fopen('php://memory', 'r');
        $stream = new ResourceStream($fp);

        expect(function () use ($stream) {
            $stream->write('data');
        })->toThrow(RuntimeException::class, 'Stream is not writable');
    });
});

test('ResourceStream write() throws when closed', function () {
    phasync::run(function () {
        $fp     = \fopen('php://temp', 'w+');
        $stream = new ResourceStream($fp);
        $stream->close();

        expect(function () use ($stream) {
            $stream->write('data');
        })->toThrow(RuntimeException::class, 'Stream is closed or detached');
    });
});

test('ResourceStream isReadable() for read-only stream', function () {
    phasync::run(function () {
        $fp     = \fopen('php://memory', 'r');
        $stream = new ResourceStream($fp);

        expect($stream->isReadable())->toBeTrue();
    });
});

test('ResourceStream isReadable() respects mode override', function () {
    phasync::run(function () {
        $fp     = \fopen('php://temp', 'w+');
        $stream = new ResourceStream($fp, 'w');

        expect($stream->isReadable())->toBeFalse();
    });
});

test('ResourceStream isReadable() returns false when closed', function () {
    phasync::run(function () {
        $fp     = \fopen('php://temp', 'w+');
        $stream = new ResourceStream($fp);
        $stream->close();

        expect($stream->isReadable())->toBeFalse();
    });
});

test('ResourceStream read() reads data', function () {
    phasync::run(function () {
        $fp = \fopen('php://temp', 'w+');
        \fwrite($fp, '0123456789');
        \rewind($fp);

        $stream = new ResourceStream($fp);
        expect($stream->read(5))->toBe('01234');
        expect($stream->read(100))->toBe('56789');
    });
});

test('ResourceStream read() throws on negative length', function () {
    phasync::run(function () {
        $fp     = \fopen('php://temp', 'w+');
        $stream = new ResourceStream($fp);

        expect(function () use ($stream) {
            $stream->read(-1);
        })->toThrow(RuntimeException::class, "Can't read a negative amount");
    });
});

test('ResourceStream read() throws when closed', function () {
    phasync::run(function () {
        $fp     = \fopen('php://temp', 'w+');
        $stream = new ResourceStream($fp);
        $stream->close();

        expect(function () use ($stream) {
            $stream->read(5);
        })->toThrow(RuntimeException::class, 'Stream is closed or detached');
    });
});

test('ResourceStream getContents() reads all remaining', function () {
    phasync::run(function () {
        $fp = \fopen('php://temp', 'w+');
        \fwrite($fp, 'Hello World');
        \rewind($fp);

        $stream = new ResourceStream($fp);
        $stream->read(6);
        expect($stream->getContents())->toBe('World');
    });
});

test('ResourceStream getContents() throws when closed', function () {
    phasync::run(function () {
        $fp     = \fopen('php://temp', 'w+');
        $stream = new ResourceStream($fp);
        $stream->close();

        expect(function () use ($stream) {
            $stream->getContents();
        })->toThrow(RuntimeException::class, 'Stream is closed or detached');
    });
});

test('ResourceStream getMetadata() returns metadata', function () {
    phasync::run(function () {
        $fp     = \fopen('php://temp', 'w+');
        $stream = new ResourceStream($fp);

        $meta = $stream->getMetadata();
        expect($meta)->toHaveKey('mode');
        expect($meta)->toHaveKey('seekable');
    });
});

test('ResourceStream getMetadata() returns null when closed', function () {
    phasync::run(function () {
        $fp     = \fopen('php://temp', 'w+');
        $stream = new ResourceStream($fp);
        $stream->close();

        expect($stream->getMetadata())->toBeNull();
        expect($stream->getMetadata('mode'))->toBeNull();
    });
});

test('ResourceStream throws for a non-seekable resource in blocking mode, and leaves it alone', function () {
    [$fp] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);

    expect(fn () => new ResourceStream($fp))->toThrow(InvalidArgumentException::class, 'stream_set_blocking($resource, false)');
    expect(\stream_get_meta_data($fp)['blocked'])->toBeTrue();
})->skip(\PHP_OS_FAMILY === 'Windows', 'stream_socket_pair(STREAM_PF_UNIX) is not implemented on Windows');

test('ResourceStream accepts a non-seekable resource in non-blocking mode', function () {
    [$fp] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
    \stream_set_blocking($fp, false);

    expect((new ResourceStream($fp))->isSeekable())->toBeFalse();
})->skip(\PHP_OS_FAMILY === 'Windows', 'stream_socket_pair(STREAM_PF_UNIX) is not implemented on Windows');

test('ResourceStream accepts a seekable resource in blocking mode', function () {
    $fp = \fopen('php://temp', 'w+');

    expect((new ResourceStream($fp))->isSeekable())->toBeTrue();
});

test('ResourceStream getSize() is null for a socket, and casting it to string still reads it', function () {
    phasync::run(function () {
        [$a, $b] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, 0);
        \fwrite($b, 'over the socket');
        \fclose($b);
        \stream_set_blocking($a, false);
        $stream = new ResourceStream($a);
        expect($stream->getSize())->toBeNull()
            ->and((string) $stream)->toBe('over the socket');
    });
});
