<?php

use phasync\Psr\StringStream;

test('constructor stores content', function () {
    $stream = new StringStream('Hello World');
    expect($stream->getSize())->toBe(11);
});

test('constructor with empty content', function () {
    $stream = new StringStream('');
    expect($stream->getSize())->toBe(0);
});

test('read returns content from current position', function () {
    $stream = new StringStream('Hello World');
    expect($stream->read(5))->toBe('Hello');
});

test('read advances position', function () {
    $stream = new StringStream('Hello World');
    $stream->read(5);
    expect($stream->tell())->toBe(5);
    expect($stream->read(6))->toBe(' World');
});

test('read past end returns partial content', function () {
    $stream = new StringStream('Hello');
    expect($stream->read(10))->toBe('Hello');
});

test('read after EOF returns empty string', function () {
    $stream = new StringStream('Hi');
    $stream->read(10);
    expect($stream->read(10))->toBe('');
});

test('tell returns current position', function () {
    $stream = new StringStream('Hello World');
    expect($stream->tell())->toBe(0);
    $stream->read(5);
    expect($stream->tell())->toBe(5);
});

test('eof returns true at end', function () {
    $stream = new StringStream('Hi');
    expect($stream->eof())->toBeFalse();
    $stream->read(2);
    expect($stream->eof())->toBeTrue();
});

test('eof returns true for empty stream', function () {
    $stream = new StringStream('');
    expect($stream->eof())->toBeTrue();
});

test('getSize returns content length', function () {
    $stream = new StringStream('Hello');
    expect($stream->getSize())->toBe(5);
});

test('getSize for empty stream returns 0', function () {
    $stream = new StringStream('');
    expect($stream->getSize())->toBe(0);
});

test('seek with SEEK_SET moves to absolute position', function () {
    $stream = new StringStream('Hello World');
    $stream->seek(6);
    expect($stream->tell())->toBe(6);
    expect($stream->read(5))->toBe('World');
});

test('seek with SEEK_CUR moves relative to current', function () {
    $stream = new StringStream('Hello World');
    $stream->read(5);
    $stream->seek(1, \SEEK_CUR);
    expect($stream->tell())->toBe(6);
    expect($stream->read(5))->toBe('World');
});

test('seek with SEEK_END moves relative to end', function () {
    $stream = new StringStream('Hello World');
    $stream->seek(-5, \SEEK_END);
    expect($stream->tell())->toBe(6);
    expect($stream->read(5))->toBe('World');
});

test('seek clamps to valid range', function () {
    $stream = new StringStream('Hello');
    $stream->seek(-100);
    expect($stream->tell())->toBe(0);

    $stream->seek(1000);
    expect($stream->tell())->toBe(5);
});

test('rewind resets position to start', function () {
    $stream = new StringStream('Hello World');
    $stream->read(5);
    $stream->rewind();
    expect($stream->tell())->toBe(0);
    expect($stream->read(5))->toBe('Hello');
});

test('isSeekable returns true', function () {
    $stream = new StringStream('test');
    expect($stream->isSeekable())->toBeTrue();
});

test('isReadable returns true when open', function () {
    $stream = new StringStream('test');
    expect($stream->isReadable())->toBeTrue();
});

test('isReadable returns false when closed', function () {
    $stream = new StringStream('test');
    $stream->close();
    expect($stream->isReadable())->toBeFalse();
});

test('isWritable returns false', function () {
    $stream = new StringStream('test');
    expect($stream->isWritable())->toBeFalse();
});

test('write throws', function () {
    $stream = new StringStream('test');
    expect(fn () => $stream->write('data'))->toThrow(RuntimeException::class, 'not writable');
});

test('close prevents reading', function () {
    $stream = new StringStream('Hello');
    $stream->close();
    expect(fn () => $stream->read(5))->toThrow(RuntimeException::class, 'closed or detached');
});

test('detach sets closed and detached flags', function () {
    $stream = new StringStream('Hello');
    $stream->detach();
    expect($stream->isReadable())->toBeFalse();
    expect(fn () => $stream->read(5))->toThrow(RuntimeException::class);
});

test('__toString returns all content and advances position', function () {
    $stream = new StringStream('Hello World');
    $result = (string) $stream;
    expect($result)->toBe('Hello World');
    expect($stream->tell())->toBe(11);
});

test('__toString on partial read stream returns all content', function () {
    $stream = new StringStream('Hello World');
    $stream->read(6);
    $result = (string) $stream;
    expect($result)->toBe('Hello World');
    expect($stream->tell())->toBe(11);
});

test('getContents reads from current position to end', function () {
    $stream = new StringStream('Hello World');
    $stream->read(6);
    expect($stream->getContents())->toBe('World');
});

test('getContents from start returns all', function () {
    $stream = new StringStream('Hello World');
    expect($stream->getContents())->toBe('Hello World');
});

test('getMetadata returns correct values', function () {
    $stream = new StringStream('Hello');
    $meta   = $stream->getMetadata();

    expect($meta['timed_out'])->toBeFalse();
    expect($meta['blocked'])->toBeFalse();
    expect($meta['unread_bytes'])->toBe(0);
    expect($meta['stream_type'])->toBe('custom');
    expect($meta['wrapper_type'])->toBe('');
    expect($meta['wrapper_data'])->toBeNull();
    expect($meta['mode'])->toBe('r');
    expect($meta['seekable'])->toBeTrue();
    expect($meta['uri'])->toBe('resource');
});

test('getMetadata with specific key', function () {
    $stream = new StringStream('Hello');
    expect($stream->getMetadata('mode'))->toBe('r');
    expect($stream->getMetadata('seekable'))->toBeTrue();
    expect($stream->getMetadata('eof'))->toBeFalse();
    expect($stream->getMetadata('nonexistent'))->toBeNull();
});

test('eof in metadata matches eof()', function () {
    $stream = new StringStream('Hello');
    expect($stream->getMetadata('eof'))->toBe($stream->eof());

    $stream->read(5);
    expect($stream->getMetadata('eof'))->toBe($stream->eof());
});

test('read in small chunks', function () {
    $stream = new StringStream('ABCDEFGH');
    expect($stream->read(1))->toBe('A');
    expect($stream->read(1))->toBe('B');
    expect($stream->read(1))->toBe('C');
    expect($stream->tell())->toBe(3);
});

test('complex seek pattern', function () {
    $stream = new StringStream('0123456789');
    $stream->seek(5);
    expect($stream->read(2))->toBe('56');

    $stream->seek(2, \SEEK_CUR);
    expect($stream->tell())->toBe(9);

    $stream->seek(-3, \SEEK_END);
    expect($stream->read(3))->toBe('789');
});

test('multiple reads and seeks', function () {
    $stream = new StringStream('Hello World');

    $stream->read(5);
    expect($stream->tell())->toBe(5);

    $stream->seek(0);
    expect($stream->read(5))->toBe('Hello');

    $stream->seek(6);
    expect($stream->read(5))->toBe('World');

    $stream->rewind();
    expect($stream->read(11))->toBe('Hello World');
});

test('read with length 0 returns empty string', function () {
    $stream = new StringStream('Hello');
    expect($stream->read(0))->toBe('');
});

test('content with special characters', function () {
    $content = "Hello\nWorld\t\r\n";
    $stream  = new StringStream($content);
    expect($stream->getContents())->toBe($content);
    expect($stream->getSize())->toBe(\strlen($content));
});

test('content with unicode', function () {
    $content = 'Hello 世界 🌍';
    $stream  = new StringStream($content);
    expect($stream->getSize())->toBe(\strlen($content));
    expect($stream->getContents())->toBe($content);
});

test('large content', function () {
    $content = \str_repeat('x', 100000);
    $stream  = new StringStream($content);
    expect($stream->getSize())->toBe(100000);
    expect($stream->read(50000))->toBe(\str_repeat('x', 50000));
    expect($stream->tell())->toBe(50000);
});
