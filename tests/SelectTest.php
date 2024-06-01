<?php
declare(strict_types=1);
use phasync\Internal\Inspect;

test('phasync::select() with two fibers', function () {
    phasync::run(function() {
        $a = phasync::go(function() {
            phasync::sleep(0.2);
            return 1;
        });
        $b = phasync::go(function() {
            phasync::sleep(0.1);
            return 2;
        });
        $result = phasync::select([$a, $b]);
        expect(phasync::await($result))->toBe(2);
        expect(phasync::await($result))->toBe(2);
    });
});

test('phasync::select() with one terminated fiber', function () {
    phasync::run(function() {
        $a = phasync::go(function() {
            return 1;
        });
        $b = phasync::go(function() {
            phasync::sleep(0.1);
            return 2;
        });
        $result = phasync::select([$a, $b]);
        expect(phasync::await($result))->toBe(1);
    });
});

test('phasync::select() with channel and fiber', function() {
    phasync::run(function() {
        phasync::channel($read, $write);

        $a = phasync::go(function() {
            phasync::sleep(0.1);
            return true;
        });

        $b = phasync::go(function() use ($write) {
            $write->activate();
            phasync::sleep(0.2);
            $write->write("Via channel");
            return 2;
        });

        $selected = phasync::select([$read, $a]);
        expect($selected)->toBe($a);
        $selected = phasync::select([$read]);
        expect($selected)->toBe($read);
        expect($read->read())->toBe("Via channel");
    });
});
