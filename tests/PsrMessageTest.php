<?php

use phasync\Psr\PsrFactory;

test('a response from PsrFactory has an empty body, and every with*() works on it', function () {
    $f        = new PsrFactory();
    $response = $f->createResponse(404);
    expect((string) $response->getBody())->toBe('');

    $response = $response->withBody($f->createStream('x'))->withStatus(200)->withHeader('A', 'b');
    expect([(string) $response->getBody(), $response->getStatusCode(), $response->getHeaderLine('A')])->toBe(['x', 200, 'b']);
});

test('withAddedHeader() adds a header the message does not have, from a list of values too', function () {
    $f        = new PsrFactory();
    $response = $f->createResponse(200)->withAddedHeader('Set-Cookie', ['a=1', 'b=2'])->withAddedHeader('Set-Cookie', 'c=3');
    expect($response->getHeader('set-cookie'))->toBe(['a=1', 'b=2', 'c=3']);
});

test('a response from the factory has a body that can be written and read back', function () {
    $response = (new PsrFactory())->createResponse(200);
    $response->getBody()->write('Hello');
    expect($response->getBody()->getSize())->toBe(5);
    $response->getBody()->rewind();
    expect((string) $response->getBody())->toBe('Hello');
});

test('a stream of a read channel reads the chunks written before the close, and then ends (#93)', function () {
    phasync::run(function () {
        phasync::channel($read, $write, 4);
        $write->write('Hello ');
        $write->write('World');
        $write->close();
        $stream = phasync\Psr\StreamFactory::create($read);
        expect($stream->isReadable())->toBeTrue();
        expect($stream->eof())->toBeFalse();
        expect($stream->getContents())->toBe('Hello World');
        expect($stream->eof())->toBeTrue();
        expect($stream->read(10))->toBe('');
        expect($stream->tell())->toBe(11);
    });
});

test('a stream of a read channel returns at most $length bytes, and the rest at the next read (#93)', function () {
    phasync::run(function () {
        phasync::channel($read, $write, 4);
        $read->activate();
        phasync::go(function () use ($write) {
            $write->write('abcdefgh');
            phasync::sleep(0.01);
            $write->write('ij');
            $write->close();
        });
        $stream = phasync\Psr\StreamFactory::create($read);
        expect($stream->read(3))->toBe('abc');
        expect($stream->read(100))->toBe('defgh');
        expect((string) $stream)->toBe('ij');
        expect($stream->eof())->toBeTrue();
    });
});

test('a Uri normalises its string as its getters do: lowercase scheme and host, no default port (#94)', function () {
    $warnings = [];
    \set_error_handler(function (int $code, string $message) use (&$warnings) {
        $warnings[] = $message;

        return true;
    });
    try {
        $uri = new phasync\Psr\Uri('HTTPS://User:Pass@Example.COM:443/Path?Q=1#F');
        expect((string) $uri)->toBe('https://User:Pass@example.com/Path?Q=1#F');
        expect((string) $uri->withPath('/other'))->toBe('https://User:Pass@example.com/other?Q=1#F');
        expect((string) $uri->withHost('Other.ORG'))->toBe('https://User:Pass@other.org/Path?Q=1#F');
        expect((string) $uri->withPort(8443))->toBe('https://User:Pass@example.com:8443/Path?Q=1#F');
        expect((string) $uri->withScheme('ws'))->toBe('ws://User:Pass@example.com/Path?Q=1#F');
        expect((string) new phasync\Psr\Uri('WS://Example.com:8080/'))->toBe('ws://example.com:8080/');
        expect((string) new phasync\Psr\Uri('/relative?x=1'))->toBe('/relative?x=1');
    } finally {
        \restore_error_handler();
    }
    expect($warnings)->toBe([]);
});
