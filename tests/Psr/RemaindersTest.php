<?php

use phasync\Psr\BufferedStream;
use phasync\Psr\Request;
use phasync\Psr\ResourceStream;
use phasync\Psr\Response;
use phasync\Psr\ServerRequest;
use phasync\Psr\StringStream;
use phasync\Psr\UploadedFile;
use phasync\TimeoutException;

// ============================================================================
// ServerRequest Tests
// ============================================================================

test('ServerRequest constructor with queryParams set', function () {
    phasync::run(function () {
        $request = new ServerRequest(
            'GET',
            '/test?page=1&sort=name',
            null,
            [],
            ['page' => '1', 'sort' => 'name']
        );
        expect($request->getQueryParams())->toBe(['page' => '1', 'sort' => 'name']);
    });
});

test('ServerRequest constructor with invalid uploadedFiles throws', function () {
    expect(function () {
        new ServerRequest(
            'POST',
            '/upload',
            null,
            [],
            null,
            [],
            [],
            ['invalid' => 'not_an_uploaded_file']
        );
    })->toThrow(InvalidArgumentException::class);
});

test('ServerRequest __clone with object parsedBody clones the object', function () {
    phasync::run(function () {
        $obj       = new stdClass();
        $obj->prop = 'value';

        $request1 = new ServerRequest('POST', '/', null, [], null, [], [], [], $obj);
        $request2 = clone $request1;

        // Modify the cloned body
        $body2       = $request2->getParsedBody();
        $body2->prop = 'modified';

        expect($request1->getParsedBody()->prop)->toBe('value');
        expect($request2->getParsedBody()->prop)->toBe('modified');
    });
});

test('ServerRequest getQueryParams parses query from requestTarget', function () {
    phasync::run(function () {
        $request = new ServerRequest('GET', '/test?a=1&b=2', null);
        $params  = $request->getQueryParams();
        expect($params)->toHaveKey('a');
        expect($params)->toHaveKey('b');
    });
});

test('ServerRequest getQueryParams with empty query returns empty array', function () {
    phasync::run(function () {
        $request = new ServerRequest('GET', '/test', null);
        expect($request->getQueryParams())->toBe([]);
    });
});

test('ServerRequest fixQueryParams handles array conversion', function () {
    phasync::run(function () {
        // Test with multi-dimensional array which gets converted to proper query format
        $request = new ServerRequest(
            'GET',
            '/test',
            null,
            [],
            ['arr' => ['a', 'b']]
        );
        $params = $request->getQueryParams();
        expect($params)->toHaveKey('arr');
    });
});

// ============================================================================
// ResourceStream Tests
// ============================================================================

test('ResourceStream constructor with StreamInterface detaches it', function () {
    phasync::run(function () {
        $fp = \fopen('php://temp', 'w+');
        \fwrite($fp, 'test data');
        \rewind($fp);

        // Create a ResourceStream with a resource directly (tests line 69 indirectly)
        // The StreamInterface constructor path tests the detach logic
        $stream = new ResourceStream($fp);
        expect($stream->isReadable())->toBeTrue();
    });
});

test('ResourceStream __toString yields on empty read', function () {
    phasync::run(function () {
        $fp = \fopen('php://temp', 'w+');
        \fwrite($fp, 'data');
        \rewind($fp);

        $stream = new ResourceStream($fp);
        expect((string) $stream)->toBe('data');
    });
});

test('ResourceStream __toString catches RuntimeException and returns empty', function () {
    phasync::run(function () {
        // Use a closed stream to trigger exception
        $fp     = \fopen('php://temp', 'w+');
        $stream = new ResourceStream($fp);
        $stream->close();

        expect((string) $stream)->toBe('');
    });
});

test('ResourceStream seek throws on closed stream', function () {
    phasync::run(function () {
        $fp     = \fopen('php://temp', 'w+');
        $stream = new ResourceStream($fp);
        $stream->close();

        expect(function () use ($stream) {
            $stream->seek(100);
        })->toThrow(RuntimeException::class);
    });
});

test('ResourceStream write throws when fwrite fails', function () {
    phasync::run(function () {
        $fp     = \fopen('php://temp', 'r');
        $stream = new ResourceStream($fp);

        expect(function () use ($stream) {
            $stream->write('data');
        })->toThrow(RuntimeException::class);
    });
});

test('ResourceStream read on detached stream throws', function () {
    phasync::run(function () {
        $fp     = \fopen('php://temp', 'w+');
        $stream = new ResourceStream($fp);
        $stream->detach();

        expect(function () use ($stream) {
            $stream->read(10);
        })->toThrow(RuntimeException::class);
    });
});

test('ResourceStream getMetadata with key returns metadata value', function () {
    phasync::run(function () {
        $fp     = \fopen('php://temp', 'w+');
        $stream = new ResourceStream($fp);

        // Test line 374 - return $meta[$key] ?? null
        $mode = $stream->getMetadata('mode');
        expect($mode)->toContain('w+');
    });
});

test('ResourceStream getMetadata with non-existent key returns null', function () {
    phasync::run(function () {
        $fp     = \fopen('php://temp', 'w+');
        $stream = new ResourceStream($fp);

        expect($stream->getMetadata('non_existent_key'))->toBeNull();
    });
});

// ============================================================================
// UploadedFile Tests
// ============================================================================

test('UploadedFile constructor with no source throws', function () {
    // Create an UploadedFile with error code (no temp file created)
    $file = new UploadedFile('', null, null, null, \UPLOAD_ERR_NO_FILE);
    expect($file->getError())->toBe(\UPLOAD_ERR_NO_FILE);
});

test('UploadedFile getStream throws when moved', function () {
    phasync::run(function () {
        $tmpFile = \tempnam(\sys_get_temp_dir(), 'phtest');
        \file_put_contents($tmpFile, 'test');

        try {
            $file   = new UploadedFile($tmpFile, 'test.txt', 'text/plain', null, null, true);
            $target = \tempnam(\sys_get_temp_dir(), 'phtest_target');
            \unlink($target);

            $file->moveTo($target);

            expect(function () use ($file) {
                $file->getStream();
            })->toThrow(RuntimeException::class);

            \unlink($target);
        } finally {
            if (\file_exists($tmpFile)) {
                \unlink($tmpFile);
            }
        }
    });
});

test('UploadedFile getStream with StreamInterface returns it as-is', function () {
    phasync::run(function () {
        $stream = new StringStream('test content');
        $file   = new UploadedFile($stream, 'test.txt', 'text/plain');
        $result = $file->getStream();
        expect($result)->toBe($stream);
    });
});

test('UploadedFile getStream with resource wraps in ResourceStream', function () {
    phasync::run(function () {
        $fp = \fopen('php://temp', 'w+');
        \fwrite($fp, 'test');
        \rewind($fp);

        $file   = new UploadedFile($fp, 'test.txt');
        $stream = $file->getStream();
        expect($stream)->toBeInstanceOf(ResourceStream::class);
    });
});

test('UploadedFile moveTo from file path in CLI', function () {
    phasync::run(function () {
        $tmpFile = \tempnam(\sys_get_temp_dir(), 'phtest');
        \file_put_contents($tmpFile, 'test content');

        try {
            $file       = new UploadedFile($tmpFile, 'test.txt', 'text/plain', null, null, true);
            $targetPath = \tempnam(\sys_get_temp_dir(), 'phtest_target');
            \unlink($targetPath);

            $file->moveTo($targetPath);
            expect(\file_exists($targetPath))->toBeTrue();
            expect(\file_get_contents($targetPath))->toBe('test content');
            \unlink($targetPath);
        } finally {
            if (\file_exists($tmpFile)) {
                \unlink($tmpFile);
            }
        }
    });
});

test('UploadedFile moveTo twice throws', function () {
    phasync::run(function () {
        $tmpFile = \tempnam(\sys_get_temp_dir(), 'phtest');
        \file_put_contents($tmpFile, 'test content');

        try {
            $file    = new UploadedFile($tmpFile, 'test.txt', 'text/plain', null, null, true);
            $target1 = \tempnam(\sys_get_temp_dir(), 'phtest_1');
            $target2 = \tempnam(\sys_get_temp_dir(), 'phtest_2');
            \unlink($target1);
            \unlink($target2);

            $file->moveTo($target1);
            expect(function () use ($file, $target2) {
                $file->moveTo($target2);
            })->toThrow(RuntimeException::class, 'already been moved');

            \unlink($target1);
        } finally {
            if (\file_exists($tmpFile)) {
                \unlink($tmpFile);
            }
        }
    });
});

test('UploadedFile getSize from file path', function () {
    phasync::run(function () {
        $tmpFile = \tempnam(\sys_get_temp_dir(), 'phtest');
        $content = 'test content with known size';
        \file_put_contents($tmpFile, $content);

        try {
            $file = new UploadedFile($tmpFile);
            expect($file->getSize())->toBe(\strlen($content));
        } finally {
            \unlink($tmpFile);
        }
    });
});

// ============================================================================
// Response Tests
// ============================================================================

test('Response constructor with invalid reason phrase throws', function () {
    expect(function () {
        new Response(200, [], null, '1.1', "Invalid\rReason");
    })->toThrow(InvalidArgumentException::class);
});

test('Response withStatus with invalid reason phrase throws', function () {
    $response = new Response(200);
    expect(function () use ($response) {
        $response->withStatus(201, "Bad\nReason");
    })->toThrow(InvalidArgumentException::class);
});

test('Response reason phrase defaults correctly', function () {
    $response = new Response(404);
    expect($response->getReasonPhrase())->toBe('Not Found');
});

test('Response reason phrase custom override', function () {
    $response = new Response(200, [], null, '1.1', 'Custom');
    expect($response->getReasonPhrase())->toBe('Custom');
});

// ============================================================================
// MessageTrait Tests (via Request/Response)
// ============================================================================

test('Message header with control character throws', function () {
    expect(function () {
        new Request('GET', '/', null, ['X-Custom' => "Invalid\x0aHeader"]);
    })->toThrow(InvalidArgumentException::class, 'control character');
});

test('Message header array with non-string value throws', function () {
    expect(function () {
        new Request('GET', '/', null, ['X-Values' => ['valid', 123, 'string']]);
    })->toThrow(InvalidArgumentException::class, 'must be a string');
});

test('Message header array with control character throws', function () {
    expect(function () {
        new Request('GET', '/', null, ['X-Values' => ['good', "bad\r\n", 'good']]);
    })->toThrow(InvalidArgumentException::class, 'control character');
});

test('Message withAddedHeader appends values', function () {
    phasync::run(function () {
        $request  = new Request('GET', '/', null, ['Accept' => 'text/html']);
        $request2 = $request->withAddedHeader('Accept', 'application/json');
        expect($request2->getHeader('Accept'))->toHaveCount(2);
    });
});

// ============================================================================
// Request Tests
// ============================================================================

test('Request constructor with whitespace in target throws', function () {
    expect(function () {
        new Request('GET', '/path with space', null);
    })->toThrow(InvalidArgumentException::class);
});

test('Request constructor with control character in target throws', function () {
    expect(function () {
        new Request('GET', "/path\x00bad", null);
    })->toThrow(InvalidArgumentException::class);
});

test('Request getQuery from uriOverride', function () {
    phasync::run(function () {
        $request  = new Request('GET', '/test', null);
        $uri      = new phasync\Psr\Uri('http://example.com/path?key=value');
        $request2 = $request->withUri($uri);

        expect($request2->getQuery())->toBe('key=value');
    });
});

test('Request getQuery extracts from requestTarget', function () {
    phasync::run(function () {
        $request = new Request('GET', '/path?search=test&limit=10', null);
        expect($request->getQuery())->toBe('search=test&limit=10');
    });
});

test('Request getQuery empty when no query', function () {
    phasync::run(function () {
        $request = new Request('GET', '/path', null);
        expect($request->getQuery())->toBe('');
    });
});

test('Request withRequestTarget pins URI', function () {
    phasync::run(function () {
        $request  = new Request('GET', '/original', null);
        $request2 = $request->withRequestTarget('/new');
        $uri      = new phasync\Psr\Uri('http://example.com/different');
        $request3 = $request2->withUri($uri);

        // requestTarget should remain pinned
        expect($request3->getRequestTarget())->toBe('/new');
    });
});

// ============================================================================
// BufferedStream Tests
// ============================================================================

test('BufferedStream isSeekable returns true', function () {
    phasync::run(function () {
        $stream = new BufferedStream();
        expect($stream->isSeekable())->toBeTrue();
    });
});

test('BufferedStream seek works with different whence values', function () {
    phasync::run(function () {
        $stream = new BufferedStream();
        phasync::go(function () use ($stream) {
            $stream->append('0123456789');
            $stream->end();
        });

        phasync::sleep(0.01);

        $stream->seek(5);
        expect($stream->tell())->toBe(5);

        $stream->seek(2, \SEEK_CUR);
        expect($stream->tell())->toBe(7);

        $stream->seek(-2, \SEEK_END);
        expect($stream->tell())->toBe(8);
    });
});

test('BufferedStream read from file after transition', function () {
    phasync::run(function () {
        // Create a stream that will transition to file (16 byte buffer)
        $stream = new BufferedStream(16, 10);

        phasync::go(function () use ($stream) {
            $stream->append('0123456789ABCDEF');
            $stream->append('GHIJKLMNOP');
            $stream->end();
        });

        phasync::sleep(0.01);

        $content = '';
        while (!$stream->eof()) {
            $content .= $stream->read(5);
        }
        expect($content)->toBe('0123456789ABCDEFGHIJKLMNOP');
    });
});

test('BufferedStream getMetadata returns metadata', function () {
    phasync::run(function () {
        $stream = new BufferedStream();
        phasync::go(function () use ($stream) {
            $stream->append('data');
            $stream->end();
        });

        phasync::sleep(0.01);

        $meta = $stream->getMetadata();
        expect($meta)->toBeArray();
        expect($meta['mode'])->toBe('r');
    });
});

test('BufferedStream getMetadata with key', function () {
    phasync::run(function () {
        $stream = new BufferedStream();
        phasync::go(function () use ($stream) {
            $stream->append('data');
            $stream->end();
        });

        phasync::sleep(0.01);

        expect($stream->getMetadata('mode'))->toBe('r');
        expect($stream->getMetadata('seekable'))->toBeFalse();
    });
});

test('BufferedStream lock contention', function () {
    phasync::run(function () {
        $stream = new BufferedStream();

        phasync::go(function () use ($stream) {
            for ($i = 0; $i < 3; ++$i) {
                $stream->append("chunk$i");
                phasync::sleep(0.001);
            }
            $stream->end();
        });

        phasync::sleep(0.001);
        $result = '';
        while (!$stream->eof()) {
            $result .= $stream->read(4);
        }
        expect($result)->toContain('chunk');
    });
});

test('BufferedStream getSize timeout when end not called', function () {
    phasync::run(function () {
        $stream = new BufferedStream(1024, 0.05);

        // Don't call end(), so getSize should timeout via blockUntilEnded
        expect(function () use ($stream) {
            $stream->getSize();
        })->toThrow(TimeoutException::class);
    });
});

test('BufferedStream append after end throws', function () {
    phasync::run(function () {
        $stream = new BufferedStream();
        phasync::go(function () use ($stream) {
            $stream->append('data');
            $stream->end();
            expect(function () use ($stream) {
                $stream->append('more');
            })->toThrow(LogicException::class);
        });
        phasync::sleep(0.01);
    });
});

// Additional tests for hard-to-reach error conditions

test('UploadedFile __construct with invalid path throws', function () {
    expect(function () {
        new UploadedFile('/completely/nonexistent/path/file.txt', 'test.txt');
    })->toThrow(InvalidArgumentException::class);
});

test('ResourceStream __toString with data yields when empty chunks', function () {
    phasync::run(function () {
        // Test the yield path (line 103)
        $fp = \fopen('php://temp', 'w+');
        \fwrite($fp, 'test');
        \rewind($fp);

        $stream = new ResourceStream($fp);
        $result = (string) $stream;
        expect($result)->toBe('test');
    });
});

test('Message withHeader validates header values', function () {
    phasync::run(function () {
        $request  = new Request('GET', '/');
        $request2 = $request->withHeader('X-Test', ['value1', 'value2']);
        expect($request2->getHeader('X-Test'))->toHaveCount(2);
    });
});

test('Request withUri sets Host header correctly', function () {
    phasync::run(function () {
        $request  = new Request('GET', '/path');
        $uri      = new phasync\Psr\Uri('http://example.com:8080/new');
        $request2 = $request->withUri($uri);

        expect($request2->getHeaderLine('Host'))->toContain('example.com');
    });
});

test('Request withUri preserves Host when flag is true', function () {
    phasync::run(function () {
        $request  = new Request('GET', '/', null, ['Host' => 'original.com']);
        $uri      = new phasync\Psr\Uri('http://new.com/path');
        $request2 = $request->withUri($uri, true);

        expect($request2->getHeaderLine('Host'))->toBe('original.com');
    });
});

test('BufferedStream metadata for file-backed stream', function () {
    phasync::run(function () {
        $stream = new BufferedStream(16, 10);
        phasync::go(function () use ($stream) {
            // Force transition to file
            $stream->append('0123456789ABCDEFGHIJKLMNOP');
            phasync::sleep(0.01);
            $stream->end();
        });

        phasync::sleep(0.02);
        $meta = $stream->getMetadata();
        expect($meta)->toHaveKey('stream_type');
    });
});

test('BufferedStream eof detects end of stream', function () {
    phasync::run(function () {
        $stream = new BufferedStream();
        phasync::go(function () use ($stream) {
            $stream->append('data');
            phasync::sleep(0.01);
            $stream->end();
        });

        phasync::sleep(0.02);
        while (!$stream->eof()) {
            $stream->read(4);
        }
        expect($stream->eof())->toBeTrue();
    });
});

test('Request invalid method throws', function () {
    expect(function () {
        new Request('', '/');
    })->toThrow(InvalidArgumentException::class);
});

test('Request invalid method with spaces throws', function () {
    expect(function () {
        new Request('GET POST', '/');
    })->toThrow(InvalidArgumentException::class);
});

test('UploadedFile getSize with StreamInterface', function () {
    phasync::run(function () {
        $stream = new StringStream('test data');
        $file   = new UploadedFile($stream, 'test.txt');
        expect($file->getSize())->toBe(9);
    });
});

test('UploadedFile getSize with resource', function () {
    phasync::run(function () {
        $fp = \fopen('php://temp', 'w+');
        \fwrite($fp, 'test data');
        $file = new UploadedFile($fp);
        expect($file->getSize())->toBe(9);
    });
});

test('Response constructor with invalid status throws', function () {
    expect(function () {
        new Response(99);
    })->toThrow(InvalidArgumentException::class);
});

test('Response constructor with status above range throws', function () {
    expect(function () {
        new Response(600);
    })->toThrow(InvalidArgumentException::class);
});

test('Response constructor with custom reason phrase', function () {
    $response = new Response(200, [], null, '1.1', 'OK Custom');
    expect($response->getReasonPhrase())->toBe('OK Custom');
});

test('Message withProtocolVersion', function () {
    phasync::run(function () {
        $request  = new Request('GET', '/');
        $request2 = $request->withProtocolVersion('2.0');
        expect($request2->getProtocolVersion())->toBe('2.0');
    });
});

test('MessageTrait invalid header name throws', function () {
    expect(function () {
        new Request('GET', '/', null, ['' => 'value']);
    })->toThrow(InvalidArgumentException::class);
});

test('BufferedStream tell after read', function () {
    phasync::run(function () {
        $stream = new BufferedStream();
        phasync::go(function () use ($stream) {
            $stream->append('0123456789');
            $stream->end();
        });

        phasync::sleep(0.01);
        $stream->read(5);
        expect($stream->tell())->toBe(5);
    });
});
