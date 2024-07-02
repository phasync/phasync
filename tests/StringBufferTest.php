<?php

use phasync\Util\StringBuffer;

test('StringBuffer basic tests', function () {
    $sb = new StringBuffer();
    expect($sb->eof())->toBeFalse();
    $sb->write('Hello');
    expect($sb->read(10000))->toBe('Hello');
    expect($sb->read(100))->toBe('');
    expect($sb->write('ab'))->toBeNull();
    expect($sb->read(1))->toBe('a');
    expect($sb->read(2))->toBe('b');
    $sb->write('abc');
    expect($sb->eof())->toBeFalse();
    $sb->write('def');
    $sb->end();
    $sb->unread('b');
    expect($sb->read(5))->toBe('babcd');
    expect($sb->eof())->toBeFalse();
    expect($sb->read(10000))->toBe('ef');
    expect($sb->eof())->toBeTrue();
});

test('StringBuffer concurrent tests', function () {
    phasync::run(function () {
        $sb = new StringBuffer();
        phasync::go(function () use ($sb) {
            expect($sb->read(10, true))->toBe('abc');
            expect($sb->eof())->toBe(false);
            expect($sb->read(1, true))->toBe('');
            expect($sb->eof())->toBeTrue();
        });
        $sb->write('abc');
        phasync::go(function () use ($sb) {
            phasync::sleep(0.1);
            $sb->end();
        });
    });
});
test('StringBuffer performance tests', function () {
    phasync::run(function () {
        $t  = \microtime(true);
        $sb = new StringBuffer();
        phasync::go(function () use ($sb) {
            $s = '';
            while (!$sb->eof()) {
                $s .= $sb->read(1, true);
            }
        });
        phasync::go(function () use ($sb) {
            for ($i = 0; $i < 1000; ++$i) {
                $sb->write('a');
            }
            $sb->end();
        });
        expect(\microtime(true) - $t)->toBeLessThan(0.3);
    });
});
