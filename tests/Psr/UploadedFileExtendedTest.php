<?php

use phasync\Psr\ResourceStream;
use phasync\Psr\StringStream;
use phasync\Psr\UploadedFile;

test('UploadedFile from stream resource', function () {
    phasync::run(function () {
        $fp = \fopen('php://temp', 'w+');
        \fwrite($fp, 'Stream content');
        \rewind($fp);

        $file = new UploadedFile($fp, 'file.txt', 'text/plain');

        expect($file->getClientFilename())->toBe('file.txt');
        expect($file->getClientMediaType())->toBe('text/plain');
        expect($file->getError())->toBe(\UPLOAD_ERR_OK);
    });
});

test('UploadedFile from StreamInterface', function () {
    phasync::run(function () {
        $stream = new StringStream('Stream content');
        $file   = new UploadedFile($stream, 'file.txt');

        expect($file->getClientFilename())->toBe('file.txt');
    });
});

test('UploadedFile from file path', function () {
    phasync::run(function () {
        $tmpfile = \tempnam(\sys_get_temp_dir(), 'upload_test');
        \file_put_contents($tmpfile, 'File content');

        try {
            $file = new UploadedFile($tmpfile, 'upload.txt', 'text/plain', 12, \UPLOAD_ERR_OK);

            expect($file->getClientFilename())->toBe('upload.txt');
            expect($file->getClientMediaType())->toBe('text/plain');
            expect($file->getSize())->toBe(12);
        } finally {
            \unlink($tmpfile);
        }
    });
});

test('UploadedFile from non-existent path with UPLOAD_ERR_OK throws', function () {
    expect(function () {
        new UploadedFile('/nonexistent/path/file.txt', 'file.txt', 'text/plain', null, \UPLOAD_ERR_OK);
    })->toThrow(InvalidArgumentException::class, 'does not exist');
});

test('UploadedFile from non-existent path with error code allows it', function () {
    $file = new UploadedFile('/nonexistent/path', 'file.txt', 'text/plain', null, \UPLOAD_ERR_NO_FILE);
    expect($file->getError())->toBe(\UPLOAD_ERR_NO_FILE);
});

test('UploadedFile default error is UPLOAD_ERR_OK', function () {
    phasync::run(function () {
        $stream = new StringStream('test');
        $file   = new UploadedFile($stream);

        expect($file->getError())->toBe(\UPLOAD_ERR_OK);
    });
});

test('UploadedFile getStream() returns StreamInterface', function () {
    phasync::run(function () {
        $stream = new StringStream('content');
        $file   = new UploadedFile($stream, 'file.txt');

        $retrieved = $file->getStream();
        expect($retrieved)->toBeInstanceOf(Psr\Http\Message\StreamInterface::class);
    });
});

test('UploadedFile getStream() throws after moveTo()', function () {
    phasync::run(function () {
        $tmpfile = \tempnam(\sys_get_temp_dir(), 'upload_test');
        \file_put_contents($tmpfile, 'content');
        $target = \tempnam(\sys_get_temp_dir(), 'target');
        \unlink($target);

        try {
            $file = new UploadedFile($tmpfile, 'file.txt', 'text/plain', 7, \UPLOAD_ERR_OK);
            $file->moveTo($target);

            expect(function () use ($file) {
                $file->getStream();
            })->toThrow(RuntimeException::class, 'after moveTo()');
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

test('UploadedFile getStream() throws on upload error', function () {
    phasync::run(function () {
        $stream = new StringStream('content');
        $file   = new UploadedFile($stream, 'file.txt', 'text/plain', 7, \UPLOAD_ERR_NO_FILE);

        expect(function () use ($file) {
            $file->getStream();
        })->toThrow(RuntimeException::class);
    });
});

test('UploadedFile getStream() from seekable stream', function () {
    phasync::run(function () {
        $stream = new StringStream('content');
        $file   = new UploadedFile($stream, 'file.txt');

        $retrieved = $file->getStream();
        expect($retrieved->isReadable())->toBeTrue();
    });
});

test('UploadedFile getStream() from resource', function () {
    phasync::run(function () {
        $fp = \fopen('php://temp', 'w+');
        \fwrite($fp, 'content');
        \rewind($fp);

        $file   = new UploadedFile($fp, 'file.txt');
        $stream = $file->getStream();

        expect($stream)->toBeInstanceOf(ResourceStream::class);
    });
});

test('UploadedFile getStream() from file path', function () {
    phasync::run(function () {
        $tmpfile = \tempnam(\sys_get_temp_dir(), 'upload_test');
        \file_put_contents($tmpfile, 'file content');

        try {
            $file   = new UploadedFile($tmpfile, 'file.txt', 'text/plain', 12, \UPLOAD_ERR_OK);
            $stream = $file->getStream();

            expect($stream)->toBeInstanceOf(ResourceStream::class);
        } finally {
            \unlink($tmpfile);
        }
    });
});

test('UploadedFile getStream() non-existent file throws', function () {
    phasync::run(function () {
        // The file exists when the UploadedFile is made, and is gone when the stream is asked for
        $tmpfile = \tempnam(\sys_get_temp_dir(), 'upload_test');
        $file    = new UploadedFile($tmpfile, 'file.txt', 'text/plain', null, \UPLOAD_ERR_OK, true);
        \unlink($tmpfile);

        expect(function () use ($file) {
            $file->getStream();
        })->toThrow(RuntimeException::class, 'unavailable');
    });
});

test('UploadedFile moveTo() can only be called once', function () {
    phasync::run(function () {
        $tmpfile = \tempnam(\sys_get_temp_dir(), 'upload_test');
        \file_put_contents($tmpfile, 'content');
        $target1 = \tempnam(\sys_get_temp_dir(), 'target1');
        $target2 = \tempnam(\sys_get_temp_dir(), 'target2');
        \unlink($target1);
        \unlink($target2);

        try {
            $file = new UploadedFile($tmpfile, 'file.txt', 'text/plain', 7, \UPLOAD_ERR_OK);
            $file->moveTo($target1);

            expect(function () use ($file, $target2) {
                $file->moveTo($target2);
            })->toThrow(RuntimeException::class, 'already been moved');
        } finally {
            if (\file_exists($tmpfile)) {
                \unlink($tmpfile);
            }
            if (\file_exists($target1)) {
                \unlink($target1);
            }
            if (\file_exists($target2)) {
                \unlink($target2);
            }
        }
    });
});

test('UploadedFile moveTo() throws on upload error', function () {
    phasync::run(function () {
        $stream = new StringStream('content');
        $file   = new UploadedFile($stream, 'file.txt', 'text/plain', 7, \UPLOAD_ERR_NO_FILE);

        expect(function () use ($file) {
            $file->moveTo('/tmp/target');
        })->toThrow(RuntimeException::class, 'error code');
    });
});

test('UploadedFile moveTo() with StreamInterface copies content', function () {
    phasync::run(function () {
        $target = \tempnam(\sys_get_temp_dir(), 'target');
        \unlink($target);

        try {
            $stream = new StringStream('Stream content');
            $file   = new UploadedFile($stream, 'file.txt');

            $file->moveTo($target);
            expect(\file_get_contents($target))->toBe('Stream content');
        } finally {
            if (\file_exists($target)) {
                \unlink($target);
            }
        }
    });
});

test('UploadedFile moveTo() replaces existing target', function () {
    phasync::run(function () {
        $target = \tempnam(\sys_get_temp_dir(), 'target');
        \file_put_contents($target, 'old content');

        try {
            $stream = new StringStream('new content');
            $file   = new UploadedFile($stream, 'file.txt');

            $file->moveTo($target);
            expect(\file_get_contents($target))->toBe('new content');
        } finally {
            if (\file_exists($target)) {
                \unlink($target);
            }
        }
    });
});

test('UploadedFile moveTo() with non-seekable stream', function () {
    phasync::run(function () {
        $target = \tempnam(\sys_get_temp_dir(), 'target');
        \unlink($target);

        try {
            $fp = \fopen('php://temp', 'w+');
            \fwrite($fp, 'Resource content');
            \rewind($fp);

            $file = new UploadedFile($fp, 'file.txt');
            $file->moveTo($target);

            expect(\file_get_contents($target))->toBe('Resource content');
        } finally {
            if (\file_exists($target)) {
                \unlink($target);
            }
        }
    });
});

test('UploadedFile moveTo() in CLI uses rename', function () {
    // This test only works in CLI mode
    if (\PHP_SAPI !== 'cli') {
        $this->markTestSkipped('Test only runs in CLI mode');
    }

    $tmpfile = \tempnam(\sys_get_temp_dir(), 'upload_test');
    \file_put_contents($tmpfile, 'CLI content');
    $target = \tempnam(\sys_get_temp_dir(), 'target');
    \unlink($target);

    try {
        $file = new UploadedFile($tmpfile, 'file.txt');
        $file->moveTo($target);

        expect(\file_exists($target))->toBeTrue();
        expect(\file_get_contents($target))->toBe('CLI content');
        expect(\file_exists($tmpfile))->toBeFalse();
    } finally {
        if (\file_exists($tmpfile)) {
            \unlink($tmpfile);
        }
        if (\file_exists($target)) {
            \unlink($target);
        }
    }
});

test('UploadedFile moveTo() with isUploadedFile override uses rename', function () {
    $tmpfile = \tempnam(\sys_get_temp_dir(), 'upload_test');
    \file_put_contents($tmpfile, 'Override content');
    $target = \tempnam(\sys_get_temp_dir(), 'target');
    \unlink($target);

    try {
        $file = new UploadedFile($tmpfile, 'file.txt', 'text/plain', 16, \UPLOAD_ERR_OK, true);
        $file->moveTo($target);

        expect(\file_exists($target))->toBeTrue();
        expect(\file_get_contents($target))->toBe('Override content');
    } finally {
        if (\file_exists($tmpfile)) {
            \unlink($tmpfile);
        }
        if (\file_exists($target)) {
            \unlink($target);
        }
    }
});

test('UploadedFile moveTo() writes in chunks for large content', function () {
    phasync::run(function () {
        $target = \tempnam(\sys_get_temp_dir(), 'target');
        \unlink($target);

        try {
            // Create content larger than the 8192 byte chunks
            $largeContent = \str_repeat('x', 20000);
            $stream       = new StringStream($largeContent);
            $file         = new UploadedFile($stream, 'large.bin');

            $file->moveTo($target);
            expect(\file_get_contents($target))->toBe($largeContent);
            expect(\filesize($target))->toBe(20000);
        } finally {
            if (\file_exists($target)) {
                \unlink($target);
            }
        }
    });
});

test('UploadedFile moveTo() cleans up target on exception', function () {
    phasync::run(function () {
        $target = \tempnam(\sys_get_temp_dir(), 'target');

        try {
            // Create a mock stream that fails on read
            $failStream = new class(\str_repeat('x', 20000)) extends StringStream {
                private int $callCount = 0;

                public function read($length): string
                {
                    if (++$this->callCount > 1) {
                        throw new RuntimeException('Read failed');
                    }

                    return parent::read($length);
                }
            };

            $file = new UploadedFile($failStream, 'file.txt');

            expect(function () use ($file, $target) {
                $file->moveTo($target);
            })->toThrow(RuntimeException::class);

            // Target should be cleaned up
            expect(\file_exists($target))->toBeFalse();
        } finally {
            if (\file_exists($target)) {
                \unlink($target);
            }
        }
    });
});

test('UploadedFile getSize() returns configured size', function () {
    phasync::run(function () {
        $stream = new StringStream('content');
        $file   = new UploadedFile($stream, 'file.txt', 'text/plain', 42);

        expect($file->getSize())->toBe(42);
    });
});

test('UploadedFile getSize() from StreamInterface', function () {
    phasync::run(function () {
        $stream = new StringStream('test content');
        $file   = new UploadedFile($stream, 'file.txt');

        expect($file->getSize())->toBe(12);
    });
});

test('UploadedFile getSize() from resource', function () {
    phasync::run(function () {
        $fp = \fopen('php://temp', 'w+');
        \fwrite($fp, '123456789');
        \rewind($fp);

        $file = new UploadedFile($fp, 'file.txt');
        expect($file->getSize())->toBe(9);
    });
});

test('UploadedFile getSize() from file path', function () {
    phasync::run(function () {
        $tmpfile = \tempnam(\sys_get_temp_dir(), 'upload_test');
        \file_put_contents($tmpfile, 'file content here');

        try {
            $file = new UploadedFile($tmpfile, 'file.txt');
            expect($file->getSize())->toBe(17);
        } finally {
            \unlink($tmpfile);
        }
    });
});

test('UploadedFile getSize() returns null for missing file', function () {
    phasync::run(function () {
        $tmpfile = \tempnam(\sys_get_temp_dir(), 'upload_test');
        $file    = new UploadedFile($tmpfile, 'file.txt', null, null, \UPLOAD_ERR_OK, true);
        \unlink($tmpfile);

        expect($file->getSize())->toBeNull();
    });
});

test('UploadedFile getSize() caches the result', function () {
    phasync::run(function () {
        $tmpfile = \tempnam(\sys_get_temp_dir(), 'upload_test');
        \file_put_contents($tmpfile, 'initial');

        try {
            $file  = new UploadedFile($tmpfile, 'file.txt');
            $size1 = $file->getSize();

            // Modify the file
            \file_put_contents($tmpfile, 'much longer content');
            $size2 = $file->getSize();

            expect($size1)->toBe(7);
            expect($size2)->toBe(7); // Still cached
        } finally {
            \unlink($tmpfile);
        }
    });
});

test('UploadedFile getError() returns error code', function () {
    phasync::run(function () {
        $stream = new StringStream('test');
        $file   = new UploadedFile($stream, 'file.txt', null, null, \UPLOAD_ERR_NO_TMP_DIR);

        expect($file->getError())->toBe(\UPLOAD_ERR_NO_TMP_DIR);
    });
});

test('UploadedFile getClientFilename() returns filename', function () {
    phasync::run(function () {
        $stream = new StringStream('test');
        $file   = new UploadedFile($stream, 'myfile.pdf');

        expect($file->getClientFilename())->toBe('myfile.pdf');
    });
});

test('UploadedFile getClientFilename() returns null', function () {
    phasync::run(function () {
        $stream = new StringStream('test');
        $file   = new UploadedFile($stream);

        expect($file->getClientFilename())->toBeNull();
    });
});

test('UploadedFile getClientMediaType() returns media type', function () {
    phasync::run(function () {
        $stream = new StringStream('test');
        $file   = new UploadedFile($stream, 'file.txt', 'application/json');

        expect($file->getClientMediaType())->toBe('application/json');
    });
});

test('UploadedFile getClientMediaType() returns null', function () {
    phasync::run(function () {
        $stream = new StringStream('test');
        $file   = new UploadedFile($stream);

        expect($file->getClientMediaType())->toBeNull();
    });
});

test('UploadedFile moveTo() with resource that cannot rewind throws', function () {
    phasync::run(function () {
        $target = \tempnam(\sys_get_temp_dir(), 'target');
        \unlink($target);

        try {
            // Create a pipe (non-seekable, cannot rewind)
            $pipes  = [];
            $proc   = \proc_open('true', [1 => ['pipe', 'w']], $pipes);
            $stream = $pipes[1];

            $file = new UploadedFile($stream, 'file.txt');

            // rewind() on a pipe also warns; the exception is what matters
            \set_error_handler(fn () => true);
            try {
                expect(function () use ($file, $target) {
                    $file->moveTo($target);
                })->toThrow(RuntimeException::class, 'Unable to rewind');
            } finally {
                \restore_error_handler();
            }

            \proc_close($proc);
        } finally {
            if (\file_exists($target)) {
                \unlink($target);
            }
        }
    });
});
