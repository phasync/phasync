<?php

use phasync\Psr\PsrFactory;
use phasync\Psr\Request;
use phasync\Psr\Response;
use phasync\Psr\ServerRequest;
use phasync\Psr\StreamFactory;
use phasync\Psr\Uri;

/*
 * PSR-7 conformance: immutability, header case handling, URI normalization,
 * request-target/URI independence, stream contracts. See phasync#49 and the
 * phasync\Psr bug fixes filed alongside this file.
 */

// --- Message: immutability of with*() ---

test('withHeader/withAddedHeader/withoutHeader/withBody/withProtocolVersion return a new instance and leave the original untouched', function () {
    $r  = new Request('GET', '/');
    $r2 = $r->withHeader('X-A', '1');
    expect($r2)->not->toBe($r);
    expect($r->hasHeader('X-A'))->toBeFalse();
    expect($r2->getHeaderLine('X-A'))->toBe('1');

    $r3 = $r2->withAddedHeader('X-A', '2');
    expect($r3)->not->toBe($r2);
    expect($r2->getHeader('X-A'))->toBe(['1']);
    expect($r3->getHeader('X-A'))->toBe(['1', '2']);

    $r4 = $r3->withoutHeader('X-A');
    expect($r4)->not->toBe($r3);
    expect($r3->hasHeader('X-A'))->toBeTrue();
    expect($r4->hasHeader('X-A'))->toBeFalse();

    $body = StreamFactory::create('hello');
    $r5   = $r->withBody($body);
    expect($r5)->not->toBe($r);
    expect((string) $r->getBody())->toBe('');
    expect($r5->getBody())->toBe($body);

    $r6 = $r->withProtocolVersion('2.0');
    expect($r6)->not->toBe($r);
    expect($r->getProtocolVersion())->toBe('1.1');
    expect($r6->getProtocolVersion())->toBe('2.0');
});

test('with*() shares the same body stream instance across the returned message (phasync#63; swerve streams a response as it is produced)', function () {
    $body = StreamFactory::create('hello');
    $r    = (new Response(200, [], $body))->withHeader('X-A', '1')->withStatus(201);
    expect($r->getBody())->toBe($body);

    $req = (new Request('GET', '/', $body))->withHeader('X-A', '1')->withMethod('POST');
    expect($req->getBody())->toBe($body);

    $sr = (new ServerRequest('GET', '/', $body))->withAttribute('a', 1)->withHeader('X-A', '1');
    expect($sr->getBody())->toBe($body);
});

test('withProtocolVersion() works on a Response too (phasync#58)', function () {
    $response = (new Response())->withProtocolVersion('2.0');
    expect($response->getProtocolVersion())->toBe('2.0');
});

// --- Message: header case handling ---

test('header names are case-insensitive for hasHeader/getHeader/getHeaderLine, but original casing is preserved by getHeaders()', function () {
    $r = (new Request('GET', '/'))->withHeader('X-My-Header', 'value');

    expect($r->hasHeader('x-my-header'))->toBeTrue();
    expect($r->hasHeader('X-MY-HEADER'))->toBeTrue();
    expect($r->getHeader('x-my-header'))->toBe(['value']);
    expect($r->getHeaderLine('x-my-header'))->toBe('value');
    expect($r->getHeaders())->toBe(['X-My-Header' => ['value']]);

    // Re-setting with different casing updates the preserved casing
    $r2 = $r->withHeader('X-MY-HEADER', 'other');
    expect($r2->getHeaders())->toBe(['X-MY-HEADER' => ['other']]);
});

test('getHeader()/getHeaderLine() return empty for a missing header, per spec', function () {
    $r = new Request('GET', '/');
    expect($r->getHeader('Absent'))->toBe([]);
    expect($r->getHeaderLine('Absent'))->toBe('');
});

test('withHeader() replaces all previous values; withAddedHeader() appends', function () {
    $r = (new Request('GET', '/'))->withHeader('A', ['1', '2']);
    expect($r->getHeader('A'))->toBe(['1', '2']);
    $r = $r->withHeader('A', '3');
    expect($r->getHeader('A'))->toBe(['3']);
    $r = $r->withAddedHeader('A', '4');
    expect($r->getHeader('A'))->toBe(['3', '4']);
});

// --- Uri: normalization ---

test('Uri::getScheme() and getHost() are lowercased, per spec', function () {
    $uri = new Uri('HTTP://Example.COM/path');
    expect($uri->getScheme())->toBe('http');
    expect($uri->getHost())->toBe('example.com');
});

test('Uri::withScheme() and withHost() normalize to lowercase', function () {
    $uri = (new Uri('http://example.com/'))->withScheme('HTTPS')->withHost('EXAMPLE.ORG');
    expect($uri->getScheme())->toBe('https');
    expect($uri->getHost())->toBe('example.org');
});

test('Uri without a scheme returns an empty string, not null or a TypeError (phasync#59)', function () {
    $uri = new Uri('/path/only');
    expect($uri->getScheme())->toBe('');
    expect($uri->getHost())->toBe('');
});

test('Uri::withPort() accepts null to remove the port, and rejects out-of-range ports', function () {
    $uri = (new Uri('http://example.com/'))->withPort(8080);
    expect($uri->getPort())->toBe(8080);
    expect($uri->withPort(null)->getPort())->toBeNull();
    expect(fn () => $uri->withPort(0))->toThrow(InvalidArgumentException::class);
    expect(fn () => $uri->withPort(65536))->toThrow(InvalidArgumentException::class);
});

test('Uri::getAuthority() omits the port when it matches the scheme default, and includes userinfo', function () {
    $uri = new Uri('http://user:pass@example.com:80/');
    expect($uri->getAuthority())->toBe('user:pass@example.com');
    $uri = new Uri('http://example.com:8080/');
    expect($uri->getAuthority())->toBe('example.com:8080');
});

test('Uri with*() methods are immutable', function () {
    $uri  = new Uri('http://example.com/a');
    $uri2 = $uri->withPath('/b');
    expect($uri2)->not->toBe($uri);
    expect($uri->getPath())->toBe('/a');
    expect($uri2->getPath())->toBe('/b');
});

test('Uri __toString() round-trips a full URL', function () {
    $url = 'https://user:pass@example.com:8443/a/b?x=1&y=2#frag';
    expect((string) new Uri($url))->toBe($url);
});

// --- Request: request-target vs URI independence ---

test('getRequestTarget() defaults to "/" for an empty request-target, per spec', function () {
    $r = new Request('GET', '');
    expect($r->getRequestTarget())->toBe('/');
});

test('withRequestTarget() sets the request-target verbatim and freezes the URI it had, independent of the target string', function () {
    $r  = Request::create('GET', 'http://example.com/original?q=1');
    $r2 = $r->withRequestTarget('*');
    expect($r2->getRequestTarget())->toBe('*');
    // The URI is unaffected by an arbitrary request-target
    expect((string) $r2->getUri())->toBe('http://example.com/original?q=1');
});

test('getUri() derives scheme/host/path from the request-target and the Host header when no URI was attached', function () {
    $r = (new Request('GET', '/foo?bar=1'))->withHeader('Host', 'example.com');
    expect((string) $r->getUri())->toBe('http://example.com/foo?bar=1');
});

test('withUri() updates the Host header by default, and leaves it alone with preserveHost when one is already set', function () {
    $r  = new Request('GET', '/');
    $r2 = $r->withUri(new Uri('http://example.com/'));
    expect($r2->getHeaderLine('Host'))->toBe('example.com');

    $r3 = $r2->withHeader('Host', 'kept.example')->withUri(new Uri('http://ignored.example/'), true);
    expect($r3->getHeaderLine('Host'))->toBe('kept.example');

    // preserveHost with no existing Host header still picks up the new URI's host
    $r4 = $r->withUri(new Uri('http://example.com/'), true);
    expect($r4->getHeaderLine('Host'))->toBe('example.com');
});

// --- ServerRequest ---

test('ServerRequest exposes server params, and derives an https:// URI from the HTTPS server param', function () {
    $sr = (new ServerRequest('GET', '/x', '', ['Host' => 'example.com'], null, ['HTTPS' => 'on']));
    expect((string) $sr->getUri())->toBe('https://example.com/x');
});

test('ServerRequest::withUploadedFiles() accepts a nested tree, and rejects non-UploadedFileInterface leaves', function () {
    $file = (new PsrFactory())->createUploadedFile(StreamFactory::create('x'), 1);
    $sr   = new ServerRequest('GET', '/', '');
    $sr2  = $sr->withUploadedFiles(['avatar' => $file, 'gallery' => [$file, $file]]);
    expect($sr2->getUploadedFiles()['gallery'])->toHaveCount(2);
    expect(fn () => $sr->withUploadedFiles(['bad' => 'not-a-file']))->toThrow(InvalidArgumentException::class);
});

test('ServerRequest::withParsedBody() rejects scalars', function () {
    $sr = new ServerRequest('GET', '/', '');
    expect(fn () => $sr->withParsedBody('scalar'))->toThrow(InvalidArgumentException::class);
    expect($sr->withParsedBody(['a' => 1])->getParsedBody())->toBe(['a' => 1]);
    expect($sr->withParsedBody(null)->getParsedBody())->toBeNull();
});

test('ServerRequest attributes: getAttribute() default, withAttribute(), withoutAttribute()', function () {
    $sr = new ServerRequest('GET', '/', '');
    expect($sr->getAttribute('missing', 'fallback'))->toBe('fallback');
    $sr2 = $sr->withAttribute('k', 'v');
    expect($sr2->getAttribute('k'))->toBe('v');
    expect($sr2->withoutAttribute('k')->getAttribute('k'))->toBeNull();
});

// --- Response ---

test('Response defaults its reason phrase from the status code, and withStatus() updates both', function () {
    $r = new Response();
    expect($r->getStatusCode())->toBe(200);
    expect($r->getReasonPhrase())->toBe('OK');

    $r2 = $r->withStatus(404);
    expect($r2->getReasonPhrase())->toBe('Not Found');

    $r3 = $r->withStatus(499, 'Custom');
    expect($r3->getReasonPhrase())->toBe('Custom');
});

test('a PsrFactory response has an empty, but non-null, body (phasync#49 regression)', function () {
    $response = (new PsrFactory())->createResponse();
    expect($response->getBody())->toBeInstanceOf(Psr\Http\Message\StreamInterface::class);
    expect((string) $response->getBody())->toBe('');
});

// --- Stream contracts (StringStream, used for string bodies) ---

test('a string-backed stream is seekable, readable, and not writable', function () {
    $s = StreamFactory::create('hello world');
    expect($s->isReadable())->toBeTrue();
    expect($s->isWritable())->toBeFalse();
    expect($s->isSeekable())->toBeTrue();
    expect($s->getSize())->toBe(11);

    expect($s->read(5))->toBe('hello');
    expect($s->tell())->toBe(5);
    expect($s->eof())->toBeFalse();
    expect($s->getContents())->toBe(' world');
    expect($s->eof())->toBeTrue();

    $s->rewind();
    expect($s->tell())->toBe(0);
    expect((string) $s)->toBe('hello world');

    expect(fn () => $s->write('x'))->toThrow(RuntimeException::class);
});

test('StreamFactory::create() is idempotent for an existing StreamInterface', function () {
    $s = StreamFactory::create('x');
    expect(StreamFactory::create($s))->toBe($s);
});

// --- UploadedFile ---

test('UploadedFile::getStream()/moveTo() throw once an upload error is set', function () {
    $file = new phasync\Psr\UploadedFile(StreamFactory::create(''), null, null, 0, \UPLOAD_ERR_NO_FILE);
    expect(fn () => $file->getStream())->toThrow(RuntimeException::class);
    expect(fn () => $file->moveTo('/tmp/x'))->toThrow(RuntimeException::class);
});

test('UploadedFile::moveTo() writes the stream contents to the target path and can only be called once', function () {
    $target = \tempnam(\sys_get_temp_dir(), 'phasync-upload-');
    \unlink($target);
    $file = new phasync\Psr\UploadedFile(StreamFactory::create('the-contents'), 'a.txt', 'text/plain', 12, \UPLOAD_ERR_OK);

    $file->moveTo($target);
    expect(\file_get_contents($target))->toBe('the-contents');
    expect(fn () => $file->moveTo($target))->toThrow(RuntimeException::class);

    \unlink($target);
});

test('a ServerRequest resolves a Closure body, parsed body and uploaded files each time, until a with...() replaces them', function () {
    $f      = new PsrFactory();
    $state  = ['body' => $f->createStream('raw'), 'fields' => ['a' => '1']];
    $upload = $f->createUploadedFile($f->createStream('x'));
    $req    = new ServerRequest('POST', '/', static function () use (&$state) { return $state['body']; }, [], null, [], [], static fn () => ['f' => $upload], static function () use (&$state) { return $state['fields']; });
    $clone  = $req->withAttribute('k', 'v');

    $state['body'] = $f->createStream('parsed'); // the server parsed the body meanwhile
    expect([(string) $req->getBody(), (string) $clone->getBody(), $clone->getParsedBody(), $clone->getUploadedFiles()['f']])
        ->toBe(['parsed', 'parsed', ['a' => '1'], $upload]);

    $own = $clone->withBody($f->createStream('own'))->withParsedBody(['b' => '2'])->withUploadedFiles([]);
    expect([(string) $own->getBody(), $own->getParsedBody(), $own->getUploadedFiles()])->toBe(['own', ['b' => '2'], []]);
});

test('ServerRequest::cookies() parses a Cookie header as PHP fills $_COOKIE', function () {
    expect(ServerRequest::cookies('a=1; b=x%20y;a=2; c'))->toBe(['a' => '1', 'b' => 'x y']);
});
