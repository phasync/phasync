<?php

use phasync\Psr\BufferedStream;
use phasync\Psr\ResourceStream;
use phasync\Psr\UploadedFile;
use phasync\TimeoutException;

// ==================== UploadedFile.php ====================

test('UploadedFile.moveTo with no source throws', function () {
    phasync::run(function () {
        $target = \tempnam(\sys_get_temp_dir(), 'target');
        \unlink($target);

        try {
            // Create an uploaded file with empty string source and UPLOAD_ERR_OK
            // This results in both stream and path being null
            $file = new UploadedFile('', 'file.txt', 'text/plain', null, \UPLOAD_ERR_OK);

            // The file has no stream and no path
            expect(function () use ($file, $target) {
                $file->moveTo($target);
            })->toThrow(RuntimeException::class, 'No upload source available');
        } finally {
            if (\file_exists($target)) {
                \unlink($target);
            }
        }
    });
});

test('UploadedFile.moveTo with rename failure throws', function () {
    phasync::run(function () {
        $tmpfile = \tempnam(\sys_get_temp_dir(), 'upload_test');
        \file_put_contents($tmpfile, 'content');
        $target = '/nonexistent-dir-' . \uniqid() . '/target.txt';

        try {
            $file = new UploadedFile($tmpfile, 'file.txt', 'text/plain', 7, \UPLOAD_ERR_OK, true);

            // Try to move to a location we can't write to
            expect(function () use ($file, $target) {
                $file->moveTo($target);
            })->toThrow(RuntimeException::class);
        } finally {
            if (\file_exists($tmpfile)) {
                \unlink($tmpfile);
            }
            if (\file_exists($target)) {
                \unlink($target);
            }
        }
    });
});

test('UploadedFile.getStream from unavailable file throws', function () {
    phasync::run(function () {
        // Create a temp file, construct UploadedFile, then delete the file
        $tmpfile = \tempnam(\sys_get_temp_dir(), 'upload_test');
        \file_put_contents($tmpfile, 'content');

        $file = new UploadedFile($tmpfile, 'file.txt', 'text/plain', 7, \UPLOAD_ERR_OK, true);
        \unlink($tmpfile);

        // Now try to get the stream - the file doesn't exist anymore
        expect(function () use ($file) {
            $file->getStream();
        })->toThrow(RuntimeException::class, 'unavailable');
    });
});

test('UploadedFile.moveStreamTo with resource stream fread failure', function () {
    phasync::run(function () {
        $target = \tempnam(\sys_get_temp_dir(), 'target');
        \unlink($target);

        try {
            $fp = \fopen('php://temp', 'w+');
            \fwrite($fp, 'content');
            \rewind($fp);

            $file = new UploadedFile($fp, 'file.txt', 'text/plain', 7, \UPLOAD_ERR_OK);
            // Close the stream to make rewind fail
            \fclose($fp);

            // Closing the resource before moveTo will cause an error when trying to rewind
            $threwError = false;
            try {
                $file->moveTo($target);
            } catch (Throwable $e) {
                $threwError = true;
            }
            expect($threwError)->toBeTrue();
        } finally {
            if (\file_exists($target)) {
                \unlink($target);
            }
        }
    });
});

// ==================== ResourceStream.php ====================

test('ResourceStream from StreamInterface detaches it', function () {
    phasync::run(function () {
        $buffered = new BufferedStream();

        phasync::go(function () use ($buffered) {
            $buffered->append('test content');
            $buffered->end();
        });

        // BufferedStream detaches to a tmpfile resource
        $stream = new ResourceStream($buffered);
        expect($stream->isReadable())->toBeTrue();
        $stream->close();
    });
});

test('ResourceStream with resource stream', function () {
    phasync::run(function () {
        $fp = \fopen('php://temp', 'w+');
        \fwrite($fp, 'test');
        \rewind($fp);

        $stream = new ResourceStream($fp);
        expect($stream->isReadable())->toBeTrue();
    });
});

test('ResourceStream.seek on non-seekable stream throws', function () {
    phasync::run(function () {
        // Create a pipe which is not seekable
        $proc = \proc_open('cat', [['pipe', 'r']], $pipes);
        if (!$proc) {
            $this->markTestSkipped('Cannot create process');
        }

        try {
            \stream_set_blocking($pipes[0], false);
            $stream = new ResourceStream($pipes[0]);

            // For pipes, fseek should fail
            expect(function () use ($stream) {
                $stream->seek(0);
            })->toThrow(RuntimeException::class);
        } finally {
            if (\is_resource($pipes[0])) {
                \fclose($pipes[0]);
            }
            \proc_close($proc);
        }
    });
});

test('ResourceStream.write on read-only stream throws', function () {
    phasync::run(function () {
        $fp     = \fopen('php://memory', 'r');
        $stream = new ResourceStream($fp);

        expect(function () use ($stream) {
            $stream->write('test');
        })->toThrow(RuntimeException::class, 'not writable');
    });
});

test('ResourceStream.__toString returns empty for closed stream', function () {
    phasync::run(function () {
        $fp = \fopen('php://temp', 'w+');
        \fwrite($fp, 'content');
        $stream = new ResourceStream($fp);
        $stream->close();

        expect((string) $stream)->toBe('');
    });
});

test('ResourceStream.__toString catches exception and returns empty', function () {
    phasync::run(function () {
        $fp = \fopen('php://temp', 'w+');
        \fwrite($fp, 'content');
        \rewind($fp);

        $stream = new ResourceStream($fp);
        $stream->close();

        // Closed stream, __toString catches the exception
        expect((string) $stream)->toBe('');
    });
});

test('ResourceStream.__toString yields when read returns empty', function () {
    phasync::run(function () {
        // Create a stream with content
        $fp = \fopen('php://temp', 'w+');
        \fwrite($fp, 'content');
        \rewind($fp);

        $stream = new ResourceStream($fp);

        // Call __toString which should read the content
        $result = (string) $stream;
        expect($result)->toBe('content');
    });
});

// ==================== BufferedStream.php ====================

test('BufferedStream.read returns empty at EOF', function () {
    phasync::run(function () {
        $stream = new BufferedStream();

        phasync::go(function () use ($stream) {
            $stream->append('test');
            $stream->end();
        });

        // Read until EOF
        $content = $stream->getContents();
        expect($content)->toBe('test');
        expect($stream->eof())->toBeTrue();
    });
});

test('BufferedStream.read from file backend', function () {
    phasync::run(function () {
        $stream = new BufferedStream(bufferSize: 10);

        phasync::go(function () use ($stream) {
            // Append more than buffer to trigger file transition
            $stream->append('x' . \str_repeat('a', 20));
            $stream->end();
        });

        $content = $stream->getContents();
        expect(\strlen($content))->toBe(21);
    });
});

test('BufferedStream.append after end throws', function () {
    phasync::run(function () {
        $stream = new BufferedStream();

        phasync::go(function () use ($stream) {
            $stream->append('data');
            $stream->end();
        });

        // Wait for end
        $stream->getSize();

        expect(function () use ($stream) {
            $stream->append('more');
        })->toThrow(LogicException::class);
    });
});

test('BufferedStream.end called twice throws', function () {
    phasync::run(function () {
        $stream = new BufferedStream();

        $stream->append('data');
        $stream->end();

        expect(function () use ($stream) {
            $stream->end();
        })->toThrow(LogicException::class, 'already ended');
    });
});

test('BufferedStream.getSize times out when end not called', function () {
    phasync::run(function () {
        $stream = new BufferedStream(bufferSize: 1024, deadlockTimeout: 0.05);

        // Don't call end(), just try to get size which calls blockUntilEnded internally
        expect(function () use ($stream) {
            $stream->getSize();
        })->toThrow(TimeoutException::class, 'within 0.05 seconds');
    });
});

test('BufferedStream.getContents times out when writer stuck', function () {
    phasync::run(function () {
        $stream = new BufferedStream(bufferSize: 1024, deadlockTimeout: 0.05);

        // Try to read without end() being called
        expect(function () use ($stream) {
            $stream->getContents();
        })->toThrow(TimeoutException::class, 'within 0.05 seconds');
    });
});

test('BufferedStream.detach transitions to file and returns resource', function () {
    phasync::run(function () {
        $stream = new BufferedStream();

        phasync::go(function () use ($stream) {
            $stream->append('test content for detach');
            $stream->end();
        });

        $detached = $stream->detach();
        expect(\is_resource($detached))->toBeTrue();
        expect($stream->isReadable())->toBeFalse();

        \fclose($detached);
    });
});

test('BufferedStream.close waits for end', function () {
    phasync::run(function () {
        $stream = new BufferedStream(deadlockTimeout: 0.05);

        expect(function () use ($stream) {
            $stream->close();
        })->toThrow(TimeoutException::class);
    });
});

test('BufferedStream.read after detach throws', function () {
    phasync::run(function () {
        $stream = new BufferedStream();

        phasync::go(function () use ($stream) {
            $stream->append('test');
            $stream->end();
        });

        $stream->detach();

        expect(function () use ($stream) {
            $stream->read(10);
        })->toThrow(RuntimeException::class, 'detached');
    });
});

test('BufferedStream.read when closed throws', function () {
    phasync::run(function () {
        $stream = new BufferedStream(deadlockTimeout: 0.001);

        phasync::go(function () use ($stream) {
            $stream->append('test');
            $stream->end();
        });

        phasync::sleep(0.01);
        $stream->close();

        expect(function () use ($stream) {
            $stream->read(10);
        })->toThrow(RuntimeException::class, 'closed');
    });
});

test('BufferedStream.seek with SEEK_CUR', function () {
    phasync::run(function () {
        $stream = new BufferedStream();

        phasync::go(function () use ($stream) {
            $stream->append('0123456789');
            $stream->end();
        });

        $stream->seek(5);
        $stream->seek(2, \SEEK_CUR);

        expect($stream->tell())->toBe(7);
    });
});

test('BufferedStream.seek with SEEK_END', function () {
    phasync::run(function () {
        $stream = new BufferedStream();

        phasync::go(function () use ($stream) {
            $stream->append('0123456789');
            $stream->end();
        });

        $stream->seek(-2, \SEEK_END);

        expect($stream->tell())->toBe(8);
    });
});

test('BufferedStream.getMetadata returns array for buffered stream', function () {
    phasync::run(function () {
        $stream = new BufferedStream();

        phasync::go(function () use ($stream) {
            $stream->append('test');
            $stream->end();
        });

        $meta = $stream->getMetadata();
        expect(\is_array($meta))->toBeTrue();
        expect($meta['mode'])->toBe('r');
    });
});

test('BufferedStream.getMetadata returns specific key', function () {
    phasync::run(function () {
        $stream = new BufferedStream();

        phasync::go(function () use ($stream) {
            $stream->append('test');
            $stream->end();
        });

        $mode = $stream->getMetadata('mode');
        expect($mode)->toBe('r');
    });
});

test('BufferedStream.lock contention with concurrent reads', function () {
    phasync::run(function () {
        $stream = new BufferedStream(bufferSize: 50);

        // Start writer with enough data to trigger file transition
        phasync::go(function () use ($stream) {
            $stream->append('chunk1');
            $stream->append('chunk2');
            $stream->append('chunk3');
            $stream->append('chunk4');
            $stream->append('chunk5');
            $stream->end();
        });

        // Start a read that will read from the file backend
        $started = false;
        phasync::go(function () use ($stream, &$started) {
            $started = true;
            $content = $stream->getContents();
        });

        phasync::sleep(0.01);

        // By now, started should be true
        expect($started)->toBeTrue();
    });
});

test('BufferedStream.seek with boundary checks', function () {
    phasync::run(function () {
        $stream = new BufferedStream();

        phasync::go(function () use ($stream) {
            $stream->append('0123456789');
            $stream->end();
        });

        // Seek beyond the content
        $stream->seek(100);
        expect($stream->tell())->toBeLessThanOrEqual(10);

        // Seek to negative position
        $stream->seek(-100);
        expect($stream->tell())->toBe(0);
    });
});

// ==================== I/O failure paths ====================

/**
 * Runs $fn with PHP notices and warnings swallowed, so a deliberately failing
 * fread()/fwrite() does not abort the test.
 */
function psrGapsQuietly(callable $fn): mixed
{
    \set_error_handler(static fn (): bool => true);
    try {
        return $fn();
    } finally {
        \restore_error_handler();
    }
}

/**
 * Creates a symlink in the temp dir pointing at /dev/full, so that opening it
 * for writing succeeds but every write fails with ENOSPC.
 */
function psrGapsFullTarget(): string
{
    $link = \sys_get_temp_dir() . '/psr_gaps_full_' . \uniqid();
    \symlink('/dev/full', $link);

    return $link;
}

test('UploadedFile.moveTo from a StreamInterface fails loudly when the target is full', function () {
    phasync::run(function () {
        $link = psrGapsFullTarget();

        try {
            $fp = \fopen('php://temp', 'w+');
            \fwrite($fp, 'content');
            $file = new UploadedFile(new ResourceStream($fp), 'file.txt', 'text/plain', 7, \UPLOAD_ERR_OK);

            expect(fn () => psrGapsQuietly(fn () => $file->moveTo($link)))
                ->toThrow(RuntimeException::class, 'Failed writing to target file');
            // The partial target is removed again
            expect(\file_exists($link) || \is_link($link))->toBeFalse();
        } finally {
            if (\is_link($link)) {
                \unlink($link);
            }
        }
    });
});

test('UploadedFile.moveTo from a resource fails loudly when the target is full', function () {
    phasync::run(function () {
        $link = psrGapsFullTarget();

        try {
            $fp = \fopen('php://temp', 'w+');
            \fwrite($fp, 'content');
            $file = new UploadedFile($fp, 'file.txt', 'text/plain', 7, \UPLOAD_ERR_OK);

            expect(fn () => psrGapsQuietly(fn () => $file->moveTo($link)))
                ->toThrow(RuntimeException::class, 'Failed writing to target file');
            expect(\is_link($link))->toBeFalse();
        } finally {
            if (\is_link($link)) {
                \unlink($link);
            }
        }
    });
});

test('UploadedFile.moveTo from an unreadable resource fails loudly and removes the target', function () {
    phasync::run(function () {
        $source = \tempnam(\sys_get_temp_dir(), 'source');
        $target = \tempnam(\sys_get_temp_dir(), 'target');

        try {
            // A write-only handle: rewind() works but fread() fails
            $fp   = \fopen($source, 'w');
            $file = new UploadedFile($fp, 'file.txt', 'text/plain', 7, \UPLOAD_ERR_OK);

            expect(fn () => psrGapsQuietly(fn () => $file->moveTo($target)))
                ->toThrow(RuntimeException::class, 'Failed reading from upload stream');
            expect(\file_exists($target))->toBeFalse();
        } finally {
            \unlink($source);
            if (\file_exists($target)) {
                \unlink($target);
            }
        }
    });
});

test('ResourceStream.write throws when the underlying write fails', function () {
    phasync::run(function () {
        $path = \tempnam(\sys_get_temp_dir(), 'ro');

        try {
            // Opened read-only, but declared writable
            $stream = new ResourceStream(\fopen($path, 'r'), 'w');
            expect($stream->isWritable())->toBeTrue();

            expect(fn () => psrGapsQuietly(fn () => $stream->write('data')))
                ->toThrow(RuntimeException::class, 'Failed writing to stream');
        } finally {
            \unlink($path);
        }
    });
});

test('ResourceStream.read throws when the stream is not readable', function () {
    phasync::run(function () {
        $path   = \tempnam(\sys_get_temp_dir(), 'wo');
        $stream = new ResourceStream(\fopen($path, 'w'));

        try {
            expect(fn () => $stream->read(10))
                ->toThrow(RuntimeException::class, 'Stream is not readable');
        } finally {
            $stream->close();
            \unlink($path);
        }
    });
});

test('ResourceStream.read throws when the underlying read fails', function () {
    phasync::run(function () {
        $path = \tempnam(\sys_get_temp_dir(), 'wo');

        try {
            // Opened write-only, but declared readable
            $stream = new ResourceStream(\fopen($path, 'w'), 'r');
            expect($stream->isReadable())->toBeTrue();

            expect(fn () => psrGapsQuietly(fn () => $stream->read(10)))
                ->toThrow(RuntimeException::class, 'Failed reading from stream');
        } finally {
            \unlink($path);
        }
    });
});

test('ResourceStream.__toString returns empty when reading fails', function () {
    phasync::run(function () {
        $path = \tempnam(\sys_get_temp_dir(), 'wo');

        try {
            // Write-only handle with content (so it has a size), declared readable
            $fp = \fopen($path, 'w');
            \fwrite($fp, 'content');
            \fflush($fp);
            $stream = new ResourceStream($fp, 'r');
            expect($stream->getSize())->toBe(7);

            expect(psrGapsQuietly(fn () => (string) $stream))->toBe('');
        } finally {
            \unlink($path);
        }
    });
});

test('ResourceStream.__toString yields when a read returns nothing before EOF', function () {
    phasync::run(function () {
        // Exactly one read chunk of content: feof() only turns true after the next, empty, read
        $content = \str_repeat('x', 32768);
        $fp      = \fopen('php://temp', 'w+');
        \fwrite($fp, $content);
        $stream = new ResourceStream($fp);

        $other = 0;
        phasync::go(function () use (&$other) {
            ++$other;
        });

        expect((string) $stream)->toBe($content);
        expect($other)->toBe(1);
    });
});

test('BufferedStream.read from the file backend throws when the file read fails', function () {
    phasync::run(function () {
        $stream = new BufferedStream(bufferSize: 4);
        $stream->append('0123456789');
        $stream->end();

        // Replace the temporary file by a write-only handle so that fread() fails
        $path = \tempnam(\sys_get_temp_dir(), 'wo');
        $prop = new ReflectionProperty($stream, 'file');
        $prop->setValue($stream, \fopen($path, 'w'));

        try {
            expect(fn () => psrGapsQuietly(fn () => $stream->read(5)))
                ->toThrow(RuntimeException::class, 'Read operation failed');
        } finally {
            \fclose($prop->getValue($stream));
            $prop->setValue($stream, null);
            \unlink($path);
        }
    });
});

test('BufferedStream keeps the content in memory when the temporary file cannot be written', function () {
    if (!\function_exists('pcntl_signal') || '' === (string) \shell_exec('command -v prlimit')) {
        test()->markTestSkipped('Needs pcntl and prlimit to make file writes fail');
    }

    phasync::run(function () {
        $data   = \str_repeat('abcdefgh', 512); // 4096 bytes
        $stream = new BufferedStream(bufferSize: 5000);
        $stream->append($data);

        // Limit the size of files this process may write, so that the move to a temp file fails with EFBIG
        $pid = \getmypid();
        \pcntl_async_signals(true);
        \pcntl_signal(\SIGXFSZ, \SIG_IGN);
        \shell_exec("prlimit --pid=$pid --fsize=1024:unlimited");
        try {
            psrGapsQuietly(fn () => $stream->append($data)); // 8192 bytes > bufferSize: tries the file
        } finally {
            \shell_exec("prlimit --pid=$pid --fsize=unlimited:unlimited");
            \pcntl_signal(\SIGXFSZ, \SIG_DFL);
        }
        $stream->end();

        expect($stream->getSize())->toBe(8192);
        expect($stream->getContents())->toBe($data . $data);
        // Still memory backed
        expect($stream->getMetadata('seekable'))->toBeFalse();
    });
});

test('BufferedStream writers wait for each other when the file backend is locked', function () {
    phasync::run(function () {
        $stream = new BufferedStream(bufferSize: 4);
        $stream->append('0123456789'); // file backed from here on

        $order = [];
        $a     = phasync::go(function () use ($stream, &$order) {
            $stream->append('A');
            $order[] = 'A';
        });
        $b = phasync::go(function () use ($stream, &$order) {
            $stream->append('B');
            $order[] = 'B';
        });
        phasync::await($a);
        phasync::await($b);
        $stream->end();

        expect($order)->toHaveCount(2);
        expect($stream->getContents())->toBe('0123456789' . $order[0] . $order[1]);
    });
});

test('BufferedStream.detach returns null the second time', function () {
    phasync::run(function () {
        $stream = new BufferedStream();

        phasync::go(function () use ($stream) {
            $stream->append('abc');
            $stream->end();
        });

        $detached = $stream->detach();
        expect(\is_resource($detached))->toBeTrue();
        expect($stream->detach())->toBeNull();

        \fclose($detached);
    });
});
