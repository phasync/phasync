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

test('phasync::select() with a fiber that throws an exception', function () {
    phasync::run(function () {
        $a = phasync::go(function () {
            throw new Exception('Fiber Exception');
        });

        $b = phasync::go(function () {
            phasync::sleep(0.1);

            return 2;
        });

        $result = phasync::select([$a, $b]);

        try {
            phasync::await($result);
            $this->fail('Expected an exception to be thrown');
        } catch (Exception $e) {
            expect($e->getMessage())->toBe('Fiber Exception');
        }
    });
});

test('phasync::select() with multiple timeouts and mixed completion times', function () {
    phasync::run(function () {
        $short = phasync::go(function () {
            phasync::sleep(0.1);

            return 'short';
        });
        $medium = phasync::go(function () {
            phasync::sleep(0.5);

            return 'medium';
        });
        $long = phasync::go(function () {
            phasync::sleep(1.0);

            return 'long';
        });

        $result = match (phasync::select([$short, $medium, $long], timeout: 0.3)) {
            $short => 'short',
            $medium => 'medium',
            $long => 'long',
            default => 'timeout'
        };

        expect($result)->toBe('short');

        $result = match (phasync::select([$medium, $long], timeout: 0.7)) {
            $medium => 'medium',
            $long => 'long',
            default => 'timeout'
        };

        expect($result)->toBe('medium');

        $result = match (phasync::select([$long], timeout: 1.2)) {
            $long => 'long',
            default => 'timeout'
        };

        expect($result)->toBe('long');
    });
});
test('phasync::select() with an empty array', function () {
    phasync::run(function () {
        $result = phasync::select([]);
        expect($result)->toBeNull();
    });
});
test('phasync::select() with high number of fibers', function () {
    phasync::run(function () {
        $fibers = [];
        for ($i = 0; $i < 1000; $i++) {
            $fibers[] = phasync::go(function () use ($i) {
                phasync::sleep(rand(1, 100) / 1000);
                return $i;
            });
        }

        $result = phasync::select($fibers);
        expect(is_int(phasync::await($result)))->toBeTrue();
    });
});
test('phasync::select() with fibers throwing different exceptions', function () {
    phasync::run(function () {
        $fibers = [];
        $fibers[] = phasync::go(function () {
            throw new \InvalidArgumentException('Invalid argument');
        });
        $fibers[] = phasync::go(function () {
            phasync::sleep(0.1);
            throw new \RuntimeException('Runtime error');
        });

        foreach ($fibers as $fiber) {
            try {
                $result = phasync::select([$fiber]);
                phasync::await($result);
                $this->fail('Expected an exception to be thrown');
            } catch (\InvalidArgumentException $e) {
                expect($e->getMessage())->toBe('Invalid argument');
            } catch (\RuntimeException $e) {
                expect($e->getMessage())->toBe('Runtime error');
            }
        }
    });
});
test('phasync::select() with maximum number of fibers', function () {
    phasync::run(function () {
        $maxFibers = 5000; // Example boundary condition
        $fibers = [];

        for ($i = 0; $i < $maxFibers; $i++) {
            $fibers[] = phasync::go(function () use ($i) {
                phasync::sleep(rand(1, 100) / 1000);
                return $i;
            });
        }

        $result = phasync::select($fibers);
        expect(is_int(phasync::await($result)))->toBeTrue();
    });
});
