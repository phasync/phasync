<?php

/*
 * Tests for ReadChannel and WriteChannel wrappers
 */

test('ReadChannel activate() does nothing (deprecated)', function () {
    phasync::run(function () {
        phasync::channel($reader, $writer);
        $reader->activate();
        expect(true)->toBeTrue();
    });
});

test('ReadChannel isReady delegates to backend', function () {
    phasync::run(function () {
        phasync::channel($reader, $writer);
        phasync::go(function () use ($writer) {
            $writer->write('test');
        });
        phasync::sleep(0.01);
        expect($reader->isReady())->toBeTrue();
        expect($reader->read())->toBe('test');
    });
});

test('ReadChannel await delegates to backend', function () {
    phasync::run(function () {
        phasync::channel($reader, $writer);
        phasync::go(function () use ($writer) {
            phasync::sleep(0.01);
            $writer->write('test');
        });
        $reader->await(1.0);
        $value = $reader->read();
        expect($value)->toBe('test');
    });
});

test('ReadChannel read delegates to backend', function () {
    phasync::run(function () {
        phasync::channel($reader, $writer);
        phasync::go(function () use ($writer) {
            $writer->write('message');
        });
        expect($reader->read())->toBe('message');
    });
});

test('ReadChannel isReadable delegates to backend', function () {
    phasync::run(function () {
        phasync::channel($reader, $writer);
        expect($reader->isReadable())->toBeTrue();
        $writer->close();
        phasync::sleep(0.01);
        expect($reader->isReadable())->toBeFalse();
    });
});

test('ReadChannel close delegates to backend', function () {
    phasync::run(function () {
        phasync::channel($reader, $writer);
        $reader->close();
        expect($reader->isClosed())->toBeTrue();
    });
});

test('ReadChannel isClosed delegates to backend', function () {
    phasync::run(function () {
        phasync::channel($reader, $writer);
        expect($reader->isClosed())->toBeFalse();
        $reader->close();
        expect($reader->isClosed())->toBeTrue();
    });
});

test('ReadChannel iterator works', function () {
    phasync::run(function () {
        phasync::channel($reader, $writer);
        $values = [];
        phasync::go(function () use ($writer) {
            $writer->write(1);
            $writer->write(2);
            $writer->write(3);
            $writer->close();
        });
        foreach ($reader as $value) {
            $values[] = $value;
        }
        expect($values)->toBe([1, 2, 3]);
    });
});

test('WriteChannel activate() does nothing (deprecated)', function () {
    phasync::run(function () {
        phasync::channel($reader, $writer);
        $writer->activate();
        expect(true)->toBeTrue();
    });
});

test('WriteChannel isReady delegates to backend', function () {
    phasync::run(function () {
        phasync::channel($reader, $writer);
        phasync::go(function () use ($reader) {
            $reader->read();
        });
        phasync::sleep(0.01);
        expect($writer->isReady())->toBeTrue();
    });
});

test('WriteChannel await delegates to backend', function () {
    phasync::run(function () {
        phasync::channel($reader, $writer);
        phasync::go(function () use ($reader) {
            phasync::sleep(0.01);
            $reader->read();
        });
        $writer->await(1.0);
        expect(true)->toBeTrue();
    });
});

test('WriteChannel write delegates to backend', function () {
    phasync::run(function () {
        phasync::channel($reader, $writer);
        phasync::go(function () use ($reader) {
            expect($reader->read())->toBe('data');
        });
        $writer->write('data');
    });
});

test('WriteChannel isWritable delegates to backend', function () {
    phasync::run(function () {
        phasync::channel($reader, $writer);
        expect($writer->isWritable())->toBeTrue();
        $writer->close();
        expect($writer->isWritable())->toBeFalse();
    });
});

test('WriteChannel close delegates to backend', function () {
    phasync::run(function () {
        phasync::channel($reader, $writer);
        $writer->close();
        expect($writer->isClosed())->toBeTrue();
    });
});

test('WriteChannel isClosed delegates to backend', function () {
    phasync::run(function () {
        phasync::channel($reader, $writer);
        expect($writer->isClosed())->toBeFalse();
        $writer->close();
        expect($writer->isClosed())->toBeTrue();
    });
});

test('the creator awaits its own channel\'s reader end while a coroutine writes (#153)', function () {
    phasync::run(function () {
        phasync::channel($reader, $writer);
        phasync::go(function () use ($writer) {
            phasync::sleep(0.01);
            $writer->write('test');
        });
        $reader->await(1.0);
        expect($reader->read())->toBe('test');
    });
});

test('the creator awaits its own channel\'s writer end while a coroutine reads (#153)', function () {
    phasync::run(function () {
        phasync::channel($reader, $writer);
        phasync::go(function () use ($reader) {
            $reader->read();
        });
        $writer->await(0.01);
        expect(true)->toBeTrue(); // Should not throw
    });
});
