<?php

test('awaitContext() waits for the coroutines a context started after the code that started them returned', function () {
    $log = phasync::run(static function () {
        $log     = [];
        $context = new stdClass();
        phasync::withContext(static function () use (&$log) {
            phasync::go(static function () use (&$log) {
                phasync::sleep(0.03);
                $log[] = 'child';
            });
            $log[] = 'handler done';
        }, $context);
        phasync::awaitContext($context);
        $log[] = 'after';

        return $log;
    });

    expect($log)->toBe(['handler done', 'child', 'after']);
});

test('awaitContext() returns at once for a context without coroutines', function () {
    $start = \microtime(true);
    phasync::run(static fn () => phasync::awaitContext(new stdClass()));

    expect(\microtime(true) - $start)->toBeLessThan(0.05);
});

test('awaitContext() waits for the contexts nested in the one given, and for coroutines started meanwhile', function () {
    $log = phasync::run(static function () {
        $log   = [];
        $outer = new stdClass();
        phasync::withContext(static function () use (&$log) {
            phasync::go(static function () use (&$log) {
                phasync::withContext(static function () use (&$log) {
                    phasync::go(static function () use (&$log) {
                        phasync::sleep(0.02);
                        phasync::go(static function () use (&$log) {
                            phasync::sleep(0.02);
                            $log[] = 'grandchild';
                        });
                        $log[] = 'child';
                    });
                }, new stdClass());
            });
        }, $outer);
        phasync::awaitContext($outer);

        return $log;
    });

    expect($log)->toBe(['child', 'grandchild']);
});

test('awaitContext() throws TimeoutException while coroutines still run, and can be called again', function () {
    $log = phasync::run(static function () {
        $log     = [];
        $context = new stdClass();
        phasync::go(static function () use ($context) {
            phasync::withContext(static function () {
                phasync::go(static fn () => phasync::sleep(0.1));
            }, $context);
        });
        try {
            phasync::awaitContext($context, 0.01);
        } catch (phasync\TimeoutException) {
            $log[] = 'timed out';
        }
        phasync::awaitContext($context, 5);
        $log[] = 'done';

        return $log;
    });

    expect($log)->toBe(['timed out', 'done']);
});

test('awaitContext() does not wait for the calling coroutine itself', function () {
    $done = phasync::run(static function () {
        $context = new stdClass();
        $result  = null;
        phasync::withContext(static function () use (&$result, $context) {
            phasync::awaitContext($context, 0.5);
            $result = 'returned';
        }, $context);

        return $result;
    });

    expect($done)->toBe('returned');
});

test('awaitContext() outside a coroutine is a LogicException', function () {
    expect(static fn () => phasync::awaitContext(new stdClass()))->toThrow(LogicException::class);
});
