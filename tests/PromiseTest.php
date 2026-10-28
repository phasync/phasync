<?php

use phasync\Internal\PromiseHandler;

/** A promise-like object: then() takes callbacks, which the test settles later. */
function thenable(): object
{
    return new class {
        public array $callbacks = [];

        public function then(?Closure $onFulfilled = null, ?Closure $onRejected = null): void
        {
            $this->callbacks = [$onFulfilled, $onRejected];
        }
    };
}

test('await() of a promise-like object returns its value', function () {
    $promise = thenable();
    $result  = phasync::run(function () use ($promise) {
        phasync::go(function () use ($promise) {
            phasync::sleep(0.01);
            ($promise->callbacks[0])('value');
        });

        return phasync::await($promise);
    });
    expect($result)->toBe('value');
});

test('await() of a promise-like object throws its rejection', function () {
    $promise = thenable();
    expect(fn () => phasync::run(function () use ($promise) {
        phasync::go(function () use ($promise) {
            phasync::sleep(0.01);
            ($promise->callbacks[1])(new DomainException('rejected'));
        });

        return phasync::await($promise);
    }))->toThrow(DomainException::class, 'rejected');
});

test('await() of a promise-like object waits for it without spinning (#152)', function () {
    $promise = thenable();
    $cpu     = static fn (): float => ($r = \getrusage())['ru_utime.tv_sec'] + $r['ru_utime.tv_usec'] / 1e6 + $r['ru_stime.tv_sec'] + $r['ru_stime.tv_usec'] / 1e6;
    $used    = $cpu();
    $result  = phasync::run(function () use ($promise) {
        phasync::go(function () use ($promise) {
            phasync::sleep(0.3);
            ($promise->callbacks[0])('late');
        });

        return phasync::await($promise);
    });
    expect($result)->toBe('late');
    expect($cpu() - $used)->toBeLessThan(0.1); // 0.3 s of waiting
});

test('await() of a promise-like object that nothing will ever settle is a deadlock (#152)', function () {
    expect(fn () => phasync::run(fn () => phasync::await(thenable())))->toThrow(phasync\DeadlockException::class);
});

test('await() of an object that is neither a coroutine nor a promise throws InvalidArgumentException', function () {
    expect(fn () => phasync::run(fn () => phasync::await(new stdClass())))
        ->toThrow(InvalidArgumentException::class, 'must be a Fiber or a promise-like object');
});

test('a replaced promise handler is used by await()', function () {
    $handler  = new ReflectionProperty(PromiseHandler::class, 'handler');
    $previous = $handler->getValue();
    PromiseHandler::set(static function (mixed $promise, ?Closure $onFulfilled, ?Closure $onRejected): bool {
        $onFulfilled('from handler');

        return true;
    });
    try {
        expect(phasync::run(fn () => phasync::await(new stdClass())))->toBe('from handler');
    } finally {
        $handler->setValue(null, $previous); // the handler is process-wide; later tests expect the default
    }
});
