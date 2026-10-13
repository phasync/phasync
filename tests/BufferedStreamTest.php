<?php

use phasync\Psr\BufferedStream;

phasync::run(function () {
    $stream = new BufferedStream(4096, 1);
    $stream->append('Hello World');
    expect($stream->eof())->toBeFalse();
    expect($stream->__toString())->toContain('Stream Error');
    expect(function () use ($stream) {
        $stream->getSize();
    })->toThrow(RuntimeException::class);
    expect(function () use ($stream) {
        $stream->getSize();
    })->toThrow(RuntimeException::class);

    phasync::run(function () use ($stream) {
        expect($stream->read(4096))->toBe('Hello World');
        expect(function () use ($stream) {
            $stream->read(4096);
        })->toThrow(RuntimeException::class);
    });
    $future = phasync::go(function () use ($stream) {
        expect($stream->read(4096))->toBe('This was written late');
        expect($stream->eof())->toBe(false);
    });
    $stream->append('This was written late');
    phasync::await($future);
    $stream->end();
    phasync::go(function () use ($stream) {
        expect($stream->eof())->toBe(true);
    });
});

test('content that outgrows the memory buffer moves to a temporary file, and reads back whole (#91)', function () {
    phasync::run(function () {
        $stream = new BufferedStream(16, 1);
        $stream->append('0123456789');
        $stream->append('abcdefghij');   // past 16 bytes: to the file
        $stream->append('KLMNOPQRST');
        expect($stream->read(5))->toBe('01234');
        $stream->append('uvwxyz');
        expect($stream->read(100))->toBe('56789abcdefghijKLMNOPQRSTuvwxyz');
        $stream->end();
        expect($stream->getSize())->toBe(36);
        expect($stream->eof())->toBeTrue();
    });
});

test('after end(), the content can be read, seeked and read again, in memory and from the file (#74)', function () {
    phasync::run(function () {
        foreach ([1024, 4] as $bufferSize) {
            $stream = new BufferedStream($bufferSize, 1);
            $stream->append('Hello ');
            $stream->append('World');
            $stream->end();
            expect($stream->read(5))->toBe('Hello');
            expect($stream->getContents())->toBe(' World');
            expect($stream->eof())->toBeTrue();
            expect($stream->read(5))->toBe('');
            $stream->seek(6);
            expect($stream->read(100))->toBe('World');
            $stream->rewind();
            expect($stream->getContents())->toBe('Hello World');
            expect((string) $stream)->toBe('Hello World');
            expect($stream->getMetadata('eof'))->toBeTrue();
        }
    });
});

test('append() after end() throws LogicException of its own (#92)', function () {
    phasync::run(function () {
        $stream = new BufferedStream(1024, 1);
        $stream->append('done');
        $stream->end();
        expect(fn () => $stream->append('more'))->toThrow(LogicException::class, "can't be appended to after ending");
        expect($stream->getContents())->toBe('done');
    });
});

test('getContents() before end() waits for the writer, which can still append and end (#105)', function () {
    phasync::run(function () {
        foreach ([1024, 4] as $bufferSize) {
            $stream = new BufferedStream($bufferSize, 1);
            phasync::go(function () use ($stream) {
                foreach (['alpha ', 'beta ', 'gamma'] as $chunk) {
                    phasync::sleep(0.01);
                    $stream->append($chunk);
                }
                $stream->end();
            });
            expect($stream->getContents())->toBe('alpha beta gamma');
        }
    });
});
