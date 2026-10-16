<?php

use phasync\Util\Queue;

test('Queue is initially empty', function () {
    $queue = new Queue();
    expect($queue->isEmpty())->toBeTrue();
    expect($queue->count())->toBe(0);
});

test('enqueue and tryDequeue', function () {
    $queue = new Queue();
    $queue->enqueue('a');
    expect($queue->isEmpty())->toBeFalse();
    expect($queue->count())->toBe(1);

    $value  = null;
    $result = $queue->tryDequeue($value);
    expect($result)->toBeTrue();
    expect($value)->toBe('a');
    expect($queue->isEmpty())->toBeTrue();
});

test('tryDequeue on empty queue returns false', function () {
    $queue  = new Queue();
    $value  = null;
    $result = $queue->tryDequeue($value);
    expect($result)->toBeFalse();
    expect($value)->toBeNull();
});

test('FIFO order', function () {
    $queue = new Queue();
    $queue->enqueue('a');
    $queue->enqueue('b');
    $queue->enqueue('c');
    expect($queue->count())->toBe(3);

    $v1 = null;
    $queue->tryDequeue($v1);
    expect($v1)->toBe('a');

    $v2 = null;
    $queue->tryDequeue($v2);
    expect($v2)->toBe('b');

    $v3 = null;
    $queue->tryDequeue($v3);
    expect($v3)->toBe('c');
});

test('tryPeek', function () {
    $queue = new Queue();
    $queue->enqueue('a');
    $queue->enqueue('b');

    $value  = null;
    $result = $queue->tryPeek($value);
    expect($result)->toBeTrue();
    expect($value)->toBe('a');
    expect($queue->count())->toBe(2);
});

test('tryPeek on empty queue returns false', function () {
    $queue  = new Queue();
    $value  = null;
    $result = $queue->tryPeek($value);
    expect($result)->toBeFalse();
    expect($value)->toBeNull();
});

test('queue is thread-safe with concurrent operations', function () {
    $queue  = new Queue();
    $values = [];
    phasync::run(function () use ($queue, &$values) {
        // Producer
        $prod = phasync::go(function () use ($queue) {
            for ($i = 0; $i < 5; ++$i) {
                $queue->enqueue($i);
                phasync::sleep(0.001);
            }
        });

        // Consumer
        $cons = phasync::go(function () use ($queue, &$values) {
            for ($i = 0; $i < 5; ++$i) {
                $queue->tryDequeue($v);
                $values[] = $v;
                phasync::sleep(0.002);
            }
        });

        phasync::await($prod);
        phasync::await($cons);
    });

    expect($values)->toBe([0, 1, 2, 3, 4]);
});

test('multiple coroutines can use the queue concurrently', function () {
    $queue = new Queue();
    $log   = phasync::run(function () use ($queue) {
        $log = [];

        phasync::go(function () use ($queue, &$log) {
            for ($i = 1; $i <= 2; ++$i) {
                $queue->lock(function () use ($i, &$log) {
                    $log[] = "a$i";
                    phasync::sleep(0.01);
                });
            }
        });

        phasync::go(function () use ($queue, &$log) {
            $queue->lock(function () use (&$log) {
                $log[] = 'b';
            });
        });

        phasync::sleep(0.05);

        return $log;
    });

    expect($log)->toBe(['a1', 'b', 'a2']);
});

test('count reflects number of items', function () {
    $queue = new Queue();
    for ($i = 0; $i < 10; ++$i) {
        $queue->enqueue($i);
        expect($queue->count())->toBe($i + 1);
    }

    for ($i = 10; $i > 0; --$i) {
        $queue->tryDequeue($v);
        expect($queue->count())->toBe($i - 1);
    }
});

test('various types can be enqueued', function () {
    $queue = new Queue();

    $queue->enqueue('string');
    $queue->enqueue(42);
    $queue->enqueue(3.14);
    $queue->enqueue(['array']);
    $queue->enqueue(new stdClass());

    $v = null;
    expect($queue->tryDequeue($v))->toBeTrue();
    expect($v)->toBe('string');

    expect($queue->tryDequeue($v))->toBeTrue();
    expect($v)->toBe(42);

    expect($queue->tryDequeue($v))->toBeTrue();
    expect($v)->toBe(3.14);

    expect($queue->tryDequeue($v))->toBeTrue();
    expect($v)->toBe(['array']);

    expect($queue->tryDequeue($v))->toBeTrue();
    expect($v)->toBeInstanceOf(stdClass::class);
});
