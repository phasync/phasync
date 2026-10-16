<?php

use phasync\Psr\EmptyStream;

test('create returns singleton', function () {
    $stream1 = EmptyStream::create();
    $stream2 = EmptyStream::create();
    expect($stream1 === $stream2)->toBeTrue();
});

test('__toString returns empty string', function () {
    $stream = EmptyStream::create();
    expect((string) $stream)->toBe('');
});

test('close does nothing', function () {
    $stream = EmptyStream::create();
    $stream->close();
    expect($stream)->toBeInstanceOf(EmptyStream::class);
});

test('detach returns null', function () {
    $stream = EmptyStream::create();
    expect($stream->detach())->toBeNull();
});

test('getSize returns 0', function () {
    $stream = EmptyStream::create();
    expect($stream->getSize())->toBe(0);
});

test('tell returns 0', function () {
    $stream = EmptyStream::create();
    expect($stream->tell())->toBe(0);
});

test('eof returns true', function () {
    $stream = EmptyStream::create();
    expect($stream->eof())->toBeTrue();
});

test('isSeekable returns false', function () {
    $stream = EmptyStream::create();
    expect($stream->isSeekable())->toBeFalse();
});

test('seek does nothing', function () {
    $stream = EmptyStream::create();
    $stream->seek(5);
    // Should not throw
    expect($stream->tell())->toBe(0);
});

test('rewind does nothing', function () {
    $stream = EmptyStream::create();
    $stream->rewind();
    // Should not throw
    expect($stream->tell())->toBe(0);
});

test('isWritable returns false', function () {
    $stream = EmptyStream::create();
    expect($stream->isWritable())->toBeFalse();
});

test('write throws', function () {
    $stream = EmptyStream::create();
    expect(fn () => $stream->write('test'))->toThrow(RuntimeException::class, 'Not writable');
});

test('isReadable returns false', function () {
    $stream = EmptyStream::create();
    expect($stream->isReadable())->toBeFalse();
});

test('read returns empty string', function () {
    $stream = EmptyStream::create();
    expect($stream->read(100))->toBe('');
});

test('getContents returns empty string', function () {
    $stream = EmptyStream::create();
    expect($stream->getContents())->toBe('');
});

test('getMetadata returns correct values', function () {
    $stream = EmptyStream::create();
    $meta   = $stream->getMetadata();

    expect($meta['timed_out'])->toBeFalse();
    expect($meta['blocked'])->toBeFalse();
    expect($meta['eof'])->toBeTrue();
    expect($meta['unread_bytes'])->toBe(0);
    expect($meta['stream_type'])->toBe('custom');
    expect($meta['wrapper_type'])->toBe('');
    expect($meta['wrapper_data'])->toBeNull();
    expect($meta['mode'])->toBe('r+');
    expect($meta['seekable'])->toBeFalse();
    expect($meta['uri'])->toBe('resource');
});

test('getMetadata with specific key', function () {
    $stream = EmptyStream::create();
    expect($stream->getMetadata('mode'))->toBe('r+');
    expect($stream->getMetadata('eof'))->toBeTrue();
    expect($stream->getMetadata('unread_bytes'))->toBe(0);
    expect($stream->getMetadata('nonexistent'))->toBeNull();
});

test('can be used as a PSR-7 stream', function () {
    $stream = EmptyStream::create();

    expect($stream->isReadable())->toBeFalse();
    expect($stream->isWritable())->toBeFalse();
    expect($stream->isSeekable())->toBeFalse();
    expect($stream->getSize())->toBe(0);
    expect($stream->tell())->toBe(0);
    expect($stream->eof())->toBeTrue();
    expect($stream->getContents())->toBe('');
    expect((string) $stream)->toBe('');
});

test('multiple calls to create return same instance', function () {
    $instances     = \array_map(fn () => EmptyStream::create(), \range(1, 5));
    $firstInstance = $instances[0];

    foreach ($instances as $instance) {
        expect($instance === $firstInstance)->toBeTrue();
    }
});
