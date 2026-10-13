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
