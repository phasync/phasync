<?php

use phasync\Psr\ComposableStream;

test('constructor requires at least readFunction or writeFunction', function () {
    expect(fn () => new ComposableStream())->toThrow(InvalidArgumentException::class);
});

test('constructor with only readFunction sets mode to r', function () {
    $stream = new ComposableStream(readFunction: fn () => 'data');
    expect($stream->getMetadata('mode'))->toBe('r');
});

test('constructor with only writeFunction sets mode to a', function () {
    $stream = new ComposableStream(writeFunction: fn () => 5);
    expect($stream->getMetadata('mode'))->toBe('a');
});

test('constructor with seekFunction changes write mode to w', function () {
    $stream = new ComposableStream(writeFunction: fn () => 5, seekFunction: fn () => null);
    expect($stream->getMetadata('mode'))->toBe('w');
});

test('constructor with both functions sets mode to r+', function () {
    $stream = new ComposableStream(readFunction: fn () => 'data', writeFunction: fn () => 5);
    expect($stream->getMetadata('mode'))->toBe('r+');
});

test('isReadable returns true when readFunction provided', function () {
    expect(new ComposableStream(readFunction: fn () => 'data'))->isReadable()->toBeTrue();
});

test('isReadable returns false without readFunction', function () {
    expect(new ComposableStream(writeFunction: fn () => 5))->isReadable()->toBeFalse();
});

test('isWritable returns true when writeFunction provided', function () {
    expect(new ComposableStream(writeFunction: fn () => 5))->isWritable()->toBeTrue();
});

test('isWritable returns false without writeFunction', function () {
    expect(new ComposableStream(readFunction: fn () => 'data'))->isWritable()->toBeFalse();
});

test('isSeekable returns true when seekFunction provided', function () {
    expect(new ComposableStream(readFunction: fn () => 'data', seekFunction: fn () => null))->isSeekable()->toBeTrue();
});

test('isSeekable returns false without seekFunction', function () {
    expect(new ComposableStream(readFunction: fn () => 'data'))->isSeekable()->toBeFalse();
});

test('tell returns current offset', function () {
    $stream = new ComposableStream(readFunction: fn () => 'Hello World');
    expect($stream->tell())->toBe(0);
    $stream->read(5);
    expect($stream->tell())->toBe(5);
});

test('eof is false initially', function () {
    $stream = new ComposableStream(readFunction: fn () => 'data');
    expect($stream->eof())->toBeFalse();
});

test('eof becomes true when readFunction returns empty string', function () {
    $callCount = 0;
    $readFn    = function () use (&$callCount) {
        ++$callCount;

        return 1 === $callCount ? 'data' : '';
    };
    $stream = new ComposableStream(readFunction: $readFn);
    $stream->read(4);
    expect($stream->eof())->toBeFalse();
    // Now read again to trigger the second call to readFunction
    $stream->read(1);
    // Now eof should be true because readFunction returned empty
    expect($stream->eof())->toBeTrue();
});

test('read calls readFunction', function () {
    $stream = new ComposableStream(readFunction: fn () => 'Hello');
    expect($stream->read(5))->toBe('Hello');
});

test('read returns partial data from buffer', function () {
    $stream = new ComposableStream(readFunction: fn () => 'HelloWorld');
    expect($stream->read(5))->toBe('Hello');
    expect($stream->read(5))->toBe('World');
});

test('read after EOF returns empty string', function () {
    $stream = new ComposableStream(readFunction: fn () => '');
    expect($stream->read(10))->toBe('');
});

test('read throws when not readable', function () {
    $stream = new ComposableStream(writeFunction: fn () => 5);
    expect(fn () => $stream->read(10))->toThrow(RuntimeException::class, 'not readable');
});

test('write calls writeFunction and increments offset', function () {
    $written = '';
    $stream  = new ComposableStream(writeFunction: function ($data) use (&$written) {
        $written .= $data;

        return \strlen($data);
    });
    expect($stream->write('Hello'))->toBe(5);
    expect($stream->tell())->toBe(5);
});

test('write handles non-int return value', function () {
    $stream = new ComposableStream(writeFunction: fn () => null);
    expect($stream->write('Hello'))->toBe(5);
});

test('write throws when not writable', function () {
    $stream = new ComposableStream(readFunction: fn () => 'data');
    expect(fn () => $stream->write('test'))->toThrow(RuntimeException::class, 'not writable');
});

test('seek moves offset', function () {
    $seekCalled = false;
    $stream     = new ComposableStream(readFunction: fn () => 'data', seekFunction: function () use (&$seekCalled) {
        $seekCalled = true;
    });
    $stream->seek(10);
    expect($seekCalled)->toBeTrue();
    expect($stream->tell())->toBe(10);
});

test('seek throws when not seekable', function () {
    $stream = new ComposableStream(readFunction: fn () => 'data');
    expect(fn () => $stream->seek(5))->toThrow(RuntimeException::class, 'not seekable');
});

test('seek throws when whence != SEEK_SET', function () {
    $stream = new ComposableStream(readFunction: fn () => 'data', seekFunction: fn () => null);
    expect(fn () => $stream->seek(5, \SEEK_CUR))->toThrow(RuntimeException::class);
});

test('rewind calls seek with 0', function () {
    $offsets = [];
    $stream  = new ComposableStream(readFunction: fn () => 'data', seekFunction: function ($offset) use (&$offsets) {
        $offsets[] = $offset;
    });
    $stream->rewind();
    expect($offsets)->toContain(0);
});

test('getContents reads until EOF', function () {
    $callCount = 0;
    $readFn    = function () use (&$callCount) {
        ++$callCount;

        return 1 === $callCount ? 'Hello World' : '';
    };
    $stream = new ComposableStream(readFunction: $readFn);
    expect($stream->getContents())->toBe('Hello World');
});

test('__toString returns all content', function () {
    $callCount = 0;
    $readFn    = function () use (&$callCount) {
        ++$callCount;

        return 1 === $callCount ? 'Hello World' : '';
    };
    $stream = new ComposableStream(readFunction: $readFn);
    expect((string) $stream)->toBe('Hello World');
});

test('__toString returns empty for non-readable stream', function () {
    $stream = new ComposableStream(writeFunction: fn () => 5);
    expect((string) $stream)->toBe('');
});

test('close calls closeFunction', function () {
    $closeCalled = false;
    $stream      = new ComposableStream(
        readFunction: fn () => 'data',
        closeFunction: function () use (&$closeCalled) {
            $closeCalled = true;
        }
    );
    $stream->close();
    expect($closeCalled)->toBeTrue();
});

test('close detaches stream', function () {
    $stream = new ComposableStream(readFunction: fn () => 'data');
    $stream->close();
    expect($stream->isReadable())->toBeFalse();
});

test('detach returns null and clears functions', function () {
    $stream = new ComposableStream(readFunction: fn () => 'data');
    expect($stream->detach())->toBeNull();
    expect($stream->isReadable())->toBeFalse();
});

test('getSize calls getSizeFunction', function () {
    $stream = new ComposableStream(readFunction: fn () => 'data', getSizeFunction: fn () => 42);
    expect($stream->getSize())->toBe(42);
});

test('getSize returns null when no function provided', function () {
    $stream = new ComposableStream(readFunction: fn () => 'data');
    expect($stream->getSize())->toBeNull();
});

test('getMetadata returns all keys', function () {
    $stream = new ComposableStream(readFunction: fn () => 'data');
    $meta   = $stream->getMetadata();
    expect($meta)->toHaveKeys(['timed_out', 'blocked', 'eof', 'unread_bytes', 'stream_type', 'wrapper_type', 'wrapper_data', 'mode', 'seekable', 'uri']);
});

test('getMetadata with key returns specific value', function () {
    $stream = new ComposableStream(readFunction: fn () => 'data');
    expect($stream->getMetadata('mode'))->toBe('r');
    expect($stream->getMetadata('timed_out'))->toBeFalse();
    expect($stream->getMetadata('nonexistent'))->toBeNull();
});

test('constructor with both read and write and seek functions sets mode to r+ (with seek)', function () {
    $stream = new ComposableStream(
        readFunction: fn () => 'data',
        writeFunction: fn () => 5,
        seekFunction: fn () => null
    );
    expect($stream->getMetadata('mode'))->toBe('r+');
});

test('eof uses eofFunction when provided', function () {
    $eofValue = false;
    $stream   = new ComposableStream(
        readFunction: fn () => 'data',
        eofFunction: function () use (&$eofValue) {
            return $eofValue;
        }
    );
    expect($stream->eof())->toBeFalse();
    $eofValue = true;
    expect($stream->eof())->toBeTrue();
});

test('read returns empty when EOF is already set', function () {
    $callCount = 0;
    $readFn    = function () use (&$callCount) {
        ++$callCount;

        return 1 === $callCount ? 'Hello World' : '';
    };
    $stream = new ComposableStream(readFunction: $readFn);
    // First read gets 'Hello World'
    $result1 = $stream->read(11);
    expect($result1)->toBe('Hello World');
    // Second read triggers EOF
    $result2 = $stream->read(10);
    expect($result2)->toBe('');
    // Third read should return empty because EOF is already set
    $result3 = $stream->read(10);
    expect($result3)->toBe('');
    expect($callCount)->toBe(2); // readFunction was called only twice
});
