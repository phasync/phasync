<?php

/*
 * phasync::throw() interrupts one wait with an exception and remembers nothing, unlike
 * phasync::cancel(), which is sticky and always a CancelledException (see CancellationTest).
 */

use phasync\CancelledException;

uses()->group('characterization');

test('THR-1: throw() delivers that very exception to a coroutine in every kind of wait', function (Closure $wait) {
    $sent = new DomainException('sent');
    $seen = null;
    phasync::run(static function () use ($wait, $sent, &$seen) {
        $keep  = [];
        $child = phasync::go(static function () use ($wait, &$seen, &$keep) {
            try {
                $wait($keep);
            } catch (Throwable $e) {
                $seen = $e;
            }
        });
        phasync::sleep(0.01);
        phasync::throw($child, $sent);
        phasync::await($child);
    });

    expect($seen)->toBe($sent);
})->with([
    'sleep'     => [static fn () => phasync::sleep(5)],
    'readable'  => [static function (array &$keep) {
        $keep = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        phasync::readable($keep[0], 5);
    }],
    'awaitFlag' => [static function (array &$keep) {
        $keep = new stdClass();
        phasync::awaitFlag($keep, 5);
    }],
    'await'     => [static fn () => phasync::await(phasync::go(static fn () => phasync::sleep(0.5)), 5)],
]);

test('THR-2: throw() is one-shot: the next wait is an ordinary wait', function () {
    $log = [];
    phasync::run(static function () use (&$log) {
        $child = phasync::go(static function () use (&$log) {
            try {
                phasync::sleep(5);
            } catch (Throwable $e) {
                $log[] = $e->getMessage();
            }
            phasync::sleep(0.02);
            $log[] = 'slept';
        });
        phasync::sleep(0.01);
        phasync::throw($child, new LogicException('once'));
        phasync::await($child);
    });

    expect($log)->toBe(['once', 'slept']);
});

test('THR-3: a timeout of the interrupted wait does not fire afterwards', function () {
    $log = [];
    phasync::run(static function () use (&$log) {
        $child = phasync::go(static function () use (&$log) {
            try {
                phasync::await(phasync::go(static fn () => phasync::sleep(1)), 0.05);
            } catch (Throwable $e) {
                $log[] = $e->getMessage();
            }
            phasync::sleep(0.15);
            $log[] = 'slept';
        });
        phasync::sleep(0.01);
        phasync::throw($child, new LogicException('before the timeout'));
        phasync::await($child);
    });

    expect($log)->toBe(['before the timeout', 'slept']);
});

test('THR-4: a coroutine ending with the thrown exception is no failure of the run, and an awaiter gets it', function () {
    $seen = phasync::run(static function () {
        $child = phasync::go(static fn () => phasync::sleep(5));
        phasync::sleep(0.01);
        phasync::throw($child, new DomainException('ended with it'));
        try {
            phasync::await($child);
        } catch (Throwable $e) {
            return $e::class . ':' . $e->getMessage();
        }
    });

    expect($seen)->toBe('DomainException:ended with it');
});

test('THR-5: a coroutine throwing into itself throws at once', function () {
    $seen = phasync::run(static function () {
        try {
            phasync::throw(Fiber::getCurrent(), new DomainException('self'));
        } catch (Throwable $e) {
            return $e::class . ':' . $e->getMessage();
        }

        return 'no exception';
    });

    expect($seen)->toBe('DomainException:self');
});

test('THR-6: throwing into a coroutine that is not waiting is a LogicException, and the exception is not delivered later', function () {
    $log = phasync::run(static function () {
        $log    = [];
        $parent = Fiber::getCurrent();
        phasync::go(static function () use ($parent, &$log) {
            try {
                phasync::throw($parent, new DomainException('lost'));
            } catch (Throwable $e) {
                $log[] = \get_class($e);
            }
        });
        phasync::sleep(0.01);
        $log[] = 'parent waited undisturbed';

        return $log;
    });

    expect($log)->toBe([LogicException::class, 'parent waited undisturbed']);
});

test('THR-7: throwing into a terminated coroutine is an InvalidArgumentException', function () {
    $result = phasync::run(static function () {
        $child = phasync::go(static fn () => 1);
        try {
            phasync::throw($child, new DomainException('late'));
        } catch (Throwable $e) {
            return $e::class . ': ' . $e->getMessage();
        }
    });

    expect($result)->toBe('InvalidArgumentException: Fiber is already terminated');
});

test('THR-8: throwing into a Fiber that phasync did not create is a LogicException', function () {
    $fiber = new Fiber(static function () {
        Fiber::suspend();
    });
    $fiber->start();

    expect(static fn () => phasync::throw($fiber, new DomainException('x')))->toThrow(LogicException::class, 'is not a phasync fiber');
});

test('THR-9: throw() leaves a cancellation in force: a cancelled coroutine keeps throwing CancelledException', function () {
    $log = [];
    phasync::run(static function () use (&$log) {
        $child = phasync::go(static function () use (&$log) {
            try {
                phasync::sleep(5);
            } catch (Throwable $e) {
                $log[] = $e::class;
            }
            try {
                phasync::sleep(5);
            } catch (Throwable $e) {
                $log[] = $e::class;
            }
        });
        phasync::sleep(0.01);
        phasync::throw($child, new DomainException('first'));
        phasync::cancel($child, 'then cancelled');
        phasync::await($child);
    });

    expect($log)->toBe([DomainException::class, CancelledException::class]);
});

test('CAN-10: cancel() takes a message, a code and a cause, and a Stringable message is used as it is', function () {
    $seen = phasync::run(static function () {
        $message = new class implements Stringable {
            public function __toString(): string
            {
                return 'late-bound';
            }
        };
        $child = phasync::go(static function () {
            try {
                phasync::sleep(5);
            } catch (CancelledException $e) {
                return [$e->getMessage(), $e->getCode(), $e->getPrevious()?->getMessage()];
            }
        });
        phasync::sleep(0.01);
        phasync::cancel($child, $message, 3, new RuntimeException('why'));

        return phasync::await($child);
    });

    expect($seen)->toBe(['late-bound', 3, 'why']);
});

test('CAN-10: cancel() without arguments throws a CancelledException with the default message', function () {
    $seen = phasync::run(static function () {
        $child = phasync::go(static function () {
            try {
                phasync::sleep(5);
            } catch (CancelledException $e) {
                return $e->getMessage();
            }
        });
        phasync::sleep(0.01);
        phasync::cancel($child);

        return phasync::await($child);
    });

    expect($seen)->toBe('Operation cancelled');
});

test('CAN-10: an exception as the message of cancel() is refused, pointing to throw()', function () {
    phasync::run(static function () {
        $child = phasync::go(static fn () => phasync::sleep(5));
        phasync::sleep(0.01);
        try {
            phasync::cancel($child, new DomainException('wrong'));
            $thrown = null;
        } catch (InvalidArgumentException $e) {
            $thrown = $e->getMessage();
        }
        phasync::cancel($child);

        expect($thrown)->toContain('phasync::throw()');
    });
});

test('CAN-10: cancelling a context passes the message, code and cause to every coroutine of it', function () {
    $seen = [];
    phasync::run(static function () use (&$seen) {
        $context = new stdClass();
        phasync::withContext(static function () use (&$seen) {
            phasync::go(static function () use (&$seen) {
                try {
                    phasync::sleep(5);
                } catch (CancelledException $e) {
                    $seen[] = [$e->getMessage(), $e->getCode(), $e->getPrevious()?->getMessage()];
                }
            });
            phasync::sleep(0.01);
        }, $context);
        phasync::sleep(0.01);
        phasync::cancel($context, 'teardown', 9, new RuntimeException('cause'));
        phasync::sleep(0.01);
    });

    expect($seen)->toBe([['teardown', 9, 'cause']]);
});
