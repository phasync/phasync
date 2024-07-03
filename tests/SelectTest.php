<?php

declare(strict_types=1);

test('phasync::select() with two fibers', function () {
    phasync::run(function () {
        $a = phasync::go(function () {
            phasync::sleep(0.2);

            return 1;
        });
        $b = phasync::go(function () {
            phasync::sleep(0.1);

            return 2;
        });
        $result = phasync::select([$a, $b]);
        expect(phasync::await($result))->toBe(2);
        expect(phasync::await($result))->toBe(2);
    });
});

test('phasync::select() with one terminated fiber', function () {
    phasync::run(function () {
        $a = phasync::go(function () {
            return 1;
        });
        $b = phasync::go(function () {
            phasync::sleep(0.1);

            return 2;
        });
        $result = phasync::select([$a, $b]);
        expect(phasync::await($result))->toBe(1);
    });
});

test('phasync::select() with channel and fiber', function () {
    phasync::run(function () {
        phasync::channel($read, $write);

        $a = phasync::go(function () {
            phasync::sleep(0.1);

            return true;
        });

        $b = phasync::go(function () use ($write) {
            $write->activate();
            phasync::sleep(0.2);
            $write->write('Via channel');

            return 2;
        });

        $selected = phasync::select([$read, $a]);
        expect($selected)->toBe($a);
        $selected = phasync::select([$read]);
        expect($selected)->toBe($read);
        expect($read->read())->toBe('Via channel');
    });
});

test('phasync::select() with a timeout', function () {
    phasync::run(function () {
        $oneSec = phasync::go(function () {
            phasync::sleep(0.5);

            return true;
        });
        $twoSec = phasync::go(function () {
            phasync::sleep(0.7);

            return true;
        });

        $result = match (phasync::select([$oneSec, $twoSec], timeout: 0.2)) {
            $oneSec => 'one sec wins',
            $twoSec => 'two sec wins',
            default => 'timeout'
        };

        expect($result)->toBe('timeout');
    });
});

test('phasync::select() with closures', function () {
    phasync::run(function () {
        $oneSec = phasync::go(function () {
            phasync::sleep(0.5);

            return true;
        });
        $twoSec = phasync::go(function () {
            phasync::sleep(0.7);

            return true;
        });

        $oneSecClosure = function () use ($oneSec) {
            if ($oneSec) {
            }

            return 'one sec closure wins';
        };
        $twoSecClosure = function () use ($twoSec) {
            if ($twoSec) {
            }

            return 'two sec closure wins';
        };

        $result = match (phasync::select([$oneSecClosure, $twoSecClosure])) {
            $oneSecClosure => $oneSecClosure(),
            $twoSecClosure => $twoSecClosure()
        };

        expect($result)->toBe('one sec closure wins');
    });
});
