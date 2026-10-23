<?php

use phasync\Psr\PsrFactory;
use phasync\Psr\Request;
use phasync\Psr\Response;
use phasync\Psr\ServerRequest;
use phasync\Psr\UploadedFile;
use phasync\Psr\Uri;

test('PsrFactory::createUploadedFile() creates UploadedFile', function () {
    phasync::run(function () {
        $factory = new PsrFactory();
        $fp      = \fopen('php://temp', 'w+');
        \fwrite($fp, 'file content');
        \rewind($fp);

        $stream = new phasync\Psr\ResourceStream($fp);
        $file   = $factory->createUploadedFile($stream, 12, \UPLOAD_ERR_OK, 'test.txt', 'text/plain');

        expect($file)->toBeInstanceOf(UploadedFile::class);
        expect($file->getClientFilename())->toBe('test.txt');
        expect($file->getClientMediaType())->toBe('text/plain');
        expect($file->getSize())->toBe(12);
        expect($file->getError())->toBe(\UPLOAD_ERR_OK);
    });
});

test('PsrFactory::createServerRequest() with string URI', function () {
    phasync::run(function () {
        $factory = new PsrFactory();
        $request = $factory->createServerRequest('GET', '/path?query=value');

        expect($request)->toBeInstanceOf(ServerRequest::class);
        expect($request->getMethod())->toBe('GET');
        expect($request->getRequestTarget())->toBe('/path?query=value');
    });
});

test('PsrFactory::createServerRequest() with Uri object', function () {
    phasync::run(function () {
        $factory = new PsrFactory();
        $uri     = new Uri('http://example.com:8080/path?query=1');
        $request = $factory->createServerRequest('POST', $uri);

        expect($request->getMethod())->toBe('POST');
        expect($request->getUri()->getHost())->toBe('example.com');
        expect($request->getUri()->getPort())->toBe(8080);
    });
});

test('PsrFactory::createServerRequest() sets Host header from URI', function () {
    phasync::run(function () {
        $factory = new PsrFactory();
        $uri     = new Uri('http://example.com:8080/path');
        $request = $factory->createServerRequest('GET', $uri);

        expect($request->getHeader('Host'))->toBe(['example.com:8080']);
    });
});

test('PsrFactory::createServerRequest() with default port omits port in Host', function () {
    phasync::run(function () {
        $factory = new PsrFactory();
        $uri     = new Uri('http://example.com/path');
        $request = $factory->createServerRequest('GET', $uri);

        expect($request->getHeader('Host'))->toBe(['example.com']);
    });
});

test('PsrFactory::createServerRequest() includes query string in request target', function () {
    phasync::run(function () {
        $factory = new PsrFactory();
        $uri     = new Uri('/path?key=value&foo=bar');
        $request = $factory->createServerRequest('GET', $uri);

        expect($request->getRequestTarget())->toBe('/path?key=value&foo=bar');
    });
});

test('PsrFactory::createServerRequest() uses root path when none provided', function () {
    phasync::run(function () {
        $factory = new PsrFactory();
        $uri     = new Uri('http://example.com');
        $request = $factory->createServerRequest('GET', $uri);

        expect($request->getRequestTarget())->toBe('/');
    });
});

test('PsrFactory::createServerRequest() includes serverParams', function () {
    phasync::run(function () {
        $factory      = new PsrFactory();
        $serverParams = ['REMOTE_ADDR' => '127.0.0.1'];
        $request      = $factory->createServerRequest('GET', '/', $serverParams);

        expect($request->getServerParams())->toBe(['REMOTE_ADDR' => '127.0.0.1']);
    });
});

test('PsrFactory::createResponse() creates Response with default values', function () {
    phasync::run(function () {
        $factory  = new PsrFactory();
        $response = $factory->createResponse();

        expect($response)->toBeInstanceOf(Response::class);
        expect($response->getStatusCode())->toBe(200);
        expect($response->getProtocolVersion())->toBe('1.1');
    });
});

test('PsrFactory::createResponse() creates Response with custom code', function () {
    phasync::run(function () {
        $factory  = new PsrFactory();
        $response = $factory->createResponse(404, 'Not Found');

        expect($response->getStatusCode())->toBe(404);
        expect($response->getReasonPhrase())->toBe('Not Found');
    });
});

test('PsrFactory::createResponse() has writable body stream', function () {
    phasync::run(function () {
        $factory  = new PsrFactory();
        $response = $factory->createResponse(200);

        $body = $response->getBody();
        expect($body->isWritable())->toBeTrue();
    });
});

test('PsrFactory::createUri() creates Uri object', function () {
    phasync::run(function () {
        $factory = new PsrFactory();
        $uri     = $factory->createUri('http://example.com:8080/path?query=1#fragment');

        expect($uri)->toBeInstanceOf(Uri::class);
        expect($uri->getScheme())->toBe('http');
        expect($uri->getHost())->toBe('example.com');
        expect($uri->getPort())->toBe(8080);
        expect($uri->getPath())->toBe('/path');
        expect($uri->getQuery())->toBe('query=1');
        expect($uri->getFragment())->toBe('fragment');
    });
});

test('PsrFactory::createUri() empty string creates empty Uri', function () {
    phasync::run(function () {
        $factory = new PsrFactory();
        $uri     = $factory->createUri('');

        expect($uri)->toBeInstanceOf(Uri::class);
        expect((string) $uri)->toBe('');
    });
});

test('PsrFactory::createStream() creates stream with content', function () {
    phasync::run(function () {
        $factory = new PsrFactory();
        $stream  = $factory->createStream('Hello World');

        expect($stream->read(5))->toBe('Hello');
        expect($stream->read(6))->toBe(' World');
    });
});

test('PsrFactory::createStream() creates empty stream by default', function () {
    phasync::run(function () {
        $factory = new PsrFactory();
        $stream  = $factory->createStream();

        expect($stream->getContents())->toBe('');
    });
});

test('PsrFactory::createStream() is at beginning after creation', function () {
    phasync::run(function () {
        $factory = new PsrFactory();
        $stream  = $factory->createStream('Test');

        expect($stream->tell())->toBe(0);
    });
});

test('PsrFactory::createStreamFromFile() creates readable stream', function () {
    phasync::run(function () {
        $tmpfile = \tempnam(\sys_get_temp_dir(), 'psr_test');
        \file_put_contents($tmpfile, 'File content');

        try {
            $factory = new PsrFactory();
            $stream  = $factory->createStreamFromFile($tmpfile, 'r');

            expect($stream->read(4))->toBe('File');
        } finally {
            \unlink($tmpfile);
        }
    });
});

test('PsrFactory::createStreamFromFile() with write mode', function () {
    phasync::run(function () {
        $tmpfile = \tempnam(\sys_get_temp_dir(), 'psr_test');

        try {
            $factory = new PsrFactory();
            $stream  = $factory->createStreamFromFile($tmpfile, 'w+');

            expect($stream->isWritable())->toBeTrue();
            $stream->write('test');
            $stream->rewind();
            expect($stream->read(4))->toBe('test');
        } finally {
            \unlink($tmpfile);
        }
    });
});

test('PsrFactory::createStreamFromResource() leaves the blocking mode of the resource alone', function () {
    phasync::run(function () {
        [$fp] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        \stream_set_blocking($fp, false);

        (new PsrFactory())->createStreamFromResource($fp);
        expect(\stream_get_meta_data($fp)['blocked'])->toBeFalse();

        $temp = \fopen('php://temp', 'w+');
        expect((new PsrFactory())->createStreamFromResource($temp)->isSeekable())->toBeTrue();
    });
})->skip(\PHP_OS_FAMILY === 'Windows', 'stream_socket_pair(STREAM_PF_UNIX) is not implemented on Windows');

test('PsrFactory::createStreamFromResource() throws for a blocking socket and does not change it', function () {
    [$fp] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);

    expect(fn () => (new PsrFactory())->createStreamFromResource($fp))->toThrow(InvalidArgumentException::class, 'stream_set_blocking($resource, false)');
    expect(\stream_get_meta_data($fp)['blocked'])->toBeTrue();
})->skip(\PHP_OS_FAMILY === 'Windows', 'stream_socket_pair(STREAM_PF_UNIX) is not implemented on Windows');

test('PsrFactory::createStreamFromResource() wraps resource', function () {
    phasync::run(function () {
        $fp = \fopen('php://temp', 'w+');
        \fwrite($fp, 'Resource');
        \rewind($fp);

        $factory = new PsrFactory();
        $stream  = $factory->createStreamFromResource($fp);

        expect($stream->read(8))->toBe('Resource');
    });
});

test('PsrFactory::createRequest() creates Request', function () {
    phasync::run(function () {
        $factory = new PsrFactory();
        $request = $factory->createRequest('GET', 'http://example.com/path');

        expect($request)->toBeInstanceOf(Request::class);
        expect($request->getMethod())->toBe('GET');
    });
});

test('PsrFactory::createRequest() with Uri object', function () {
    phasync::run(function () {
        $factory = new PsrFactory();
        $uri     = new Uri('http://example.com/test');
        $request = $factory->createRequest('POST', $uri);

        expect($request->getMethod())->toBe('POST');
        expect($request->getUri()->getPath())->toBe('/test');
    });
});

test('PsrFactory all created objects are usable together', function () {
    phasync::run(function () {
        $factory = new PsrFactory();

        // Create a request
        $request = $factory->createRequest('POST', 'http://example.com/api');

        // Create a response
        $response = $factory->createResponse(201);

        // Create a stream
        $stream = $factory->createStream('API Response');

        // Create a URI
        $uri = $factory->createUri('http://redirect.com');

        expect($request->getMethod())->toBe('POST');
        expect($response->getStatusCode())->toBe(201);
        expect($stream->read(3))->toBe('API');
        expect((string) $uri)->toContain('redirect.com');
    });
});
