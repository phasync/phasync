<?php

test('phasync::io() readable stream', function () {
    $resource = phasync::run(function () {
        $fp    = \fopen(__FILE__, 'r');
        $ticks = 0;
        phasync::go(function () use (&$ticks) {
            phasync::sleep();
            ++$ticks;
        });
        $resource = phasync::io($fp);
        expect(\fread($resource, 4096))->toBeString();
        expect($ticks)->toBe(1);

        return $resource;
    });
    expect($resource)->toBeResource();
    \fseek($resource, 0);
    expect(\fread($resource, 4096))->toBeString();
});

test('phasync::io() closing resource closes source', function () {
    phasync::run(function () {
        $fp       = \fopen(__FILE__, 'r');
        $resource = phasync::io($fp);
        \fclose($resource); // Close resource to cause an exception
        expect(\is_resource($fp))->toBeFalse();
    });
});

test('phasync::io() with non-streams', function () {
    phasync::run(function () {
        expect(phasync::io(false))->toBeFalse();
        expect(phasync::io(true))->toBeTrue();
        expect(phasync::io(null))->toBeNull();
    });
});

test('phasync::io() writable stream', function () {
    phasync::run(function () {
        $fp       = \fopen('php://temp', 'r+');
        $resource = phasync::io($fp);
        $data     = 'Test data';
        \fwrite($resource, $data);
        \rewind($resource);
        expect(\fread($resource, \strlen($data)))->toBe($data);
    });
});

test('phasync::io() resource type and metadata', function () {
    phasync::run(function () {
        $fp       = \fopen(__FILE__, 'r');
        $resource = phasync::io($fp);
        $metaData = \stream_get_meta_data($resource);
        expect($metaData['wrapper_type'])->toBe('user-space');
        expect($metaData['stream_type'])->toBe('user-space');
    });
});

test('phasync::io() actually context switches', function () {
    $fp  = \fopen(__FILE__, 'r');
    $fpa = phasync::io($fp);
    phasync::run(function () use ($fpa) {
        $stop    = false;
        $counter = 0;
        phasync::go(function () use (&$stop, &$counter) {
            while (!$stop) {
                ++$counter;
                phasync::sleep();
            }
        });
        $before = $counter;
        $chunk  = \fread($fpa, 100);
        expect($before)->toBeLessThan($counter);
        expect(\strlen($chunk))->toBe(100);
        $stop = true;
    });
});
