<?php

use phasync\Psr\EmptyStream;
use phasync\Psr\ReadChannelStream;
use phasync\Psr\ResourceStream;
use phasync\Psr\StreamFactory;
use phasync\Psr\StringStream;

test('StreamFactory::create() string creates StringStream', function () {
    $stream = StreamFactory::create('Hello World');
    expect($stream)->toBeInstanceOf(StringStream::class);
});

test('StreamFactory::create() empty string creates EmptyStream', function () {
    $stream = StreamFactory::create('');
    expect($stream)->toBeInstanceOf(EmptyStream::class);
});

test('StreamFactory::create() null creates EmptyStream', function () {
    $stream = StreamFactory::create(null);
    expect($stream)->toBeInstanceOf(EmptyStream::class);
});

test('StreamFactory::create() stream resource creates ResourceStream', function () {
    phasync::run(function () {
        $fp = \fopen('php://temp', 'w+');
        \fwrite($fp, 'Test');
        \rewind($fp);

        $stream = StreamFactory::create($fp);
        expect($stream)->toBeInstanceOf(ResourceStream::class);
    });
});

test('StreamFactory::create() ReadChannelInterface creates ReadChannelStream', function () {
    phasync::run(function () {
        phasync::channel($read, $write);
        $stream = StreamFactory::create($read);
        expect($stream)->toBeInstanceOf(ReadChannelStream::class);
    });
});

test('StreamFactory::create() StreamInterface returned as-is', function () {
    phasync::run(function () {
        $original = new StringStream('Test');
        $stream   = StreamFactory::create($original);
        expect($stream)->toBe($original);
    });
});

test('StreamFactory::create() Stringable converts to StringStream', function () {
    $obj = new class {
        public function __toString(): string
        {
            return 'Stringable content';
        }
    };

    $stream = StreamFactory::create($obj);
    expect($stream)->toBeInstanceOf(StringStream::class);
});

test('StreamFactory::create() JsonSerializable converts via json_encode', function () {
    $obj = new class implements JsonSerializable {
        public function jsonSerialize(): mixed
        {
            return ['key' => 'value'];
        }
    };

    $stream = StreamFactory::create($obj);
    expect($stream)->toBeInstanceOf(StringStream::class);
});

test('StreamFactory::create() throws on invalid type', function () {
    expect(function () {
        StreamFactory::create(123);
    })->toThrow(InvalidArgumentException::class, 'Unsupported stream source');
});

test('StreamFactory::create() throws on invalid type array', function () {
    expect(function () {
        StreamFactory::create(['array']);
    })->toThrow(InvalidArgumentException::class, 'Unsupported stream source');
});

test('StreamFactory::create() throws on non-stream resource', function () {
    $handle = \curl_init();
    expect(function () use ($handle) {
        StreamFactory::create($handle);
    })->toThrow(InvalidArgumentException::class, 'Unsupported stream source');
    \curl_close($handle);
});

test('StreamFactory::create() created stream is usable', function () {
    phasync::run(function () {
        $stream = StreamFactory::create('Test content');
        expect($stream->read(4))->toBe('Test');
    });
});

test('StreamFactory::create() resource stream is readable', function () {
    phasync::run(function () {
        $fp = \fopen('php://temp', 'w+');
        \fwrite($fp, 'Resource');
        \rewind($fp);

        $stream = StreamFactory::create($fp);
        expect($stream->read(8))->toBe('Resource');
    });
});

test('StreamFactory::create() channel stream is readable', function () {
    phasync::run(function () {
        phasync::channel($read, $write);
        $stream = StreamFactory::create($read);

        phasync::go(function () use ($write) {
            $write->write('Channel data');
            $write->close();
        });

        expect($stream->getContents())->toBe('Channel data');
    });
});
