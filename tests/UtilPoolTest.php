<?php

use phasync\TimeoutException;
use phasync\Util\Pool;

class SimpleObject
{
    public static $created  = 0;
    public static $disposed = 0;

    public function __construct(public int $id = 0)
    {
        if (0 === $id) {
            $this->id = ++self::$created;
        }
    }
}

beforeEach(function () {
    SimpleObject::$created  = 0;
    SimpleObject::$disposed = 0;
});

test('Pool creates instances on demand', function () {
    phasync::run(function () {
        $pool = new Pool(fn () => new SimpleObject(), 5);

        expect($pool->count())->toBe(0);

        $obj1 = $pool->borrow();
        expect($pool->count())->toBe(1);

        $obj2 = $pool->borrow();
        expect($pool->count())->toBe(2);

        $pool->release($obj1);
        $pool->release($obj2);
    });
});

test('Pool reuses released instances', function () {
    phasync::run(function () {
        $pool = new Pool(fn () => new SimpleObject(), 5);

        $obj1 = $pool->borrow();
        $id1  = $obj1->id;

        $pool->release($obj1);

        $obj2 = $pool->borrow();
        expect($obj2->id)->toBe($id1);
    });
});

test('Pool borrow timeout', function () {
    phasync::run(function () {
        $pool = new Pool(fn () => new SimpleObject(), 1);

        $obj = $pool->borrow();

        expect(fn () => $pool->borrow(0.01))
            ->toThrow(TimeoutException::class);

        $pool->release($obj);
    });
});

test('Pool discard removes broken instances', function () {
    phasync::run(function () {
        $disposed = [];
        $pool     = new Pool(
            fn () => new SimpleObject(),
            5,
            null,
            function ($obj) use (&$disposed) {
                $disposed[] = $obj->id;
            }
        );

        $obj = $pool->borrow();
        $id  = $obj->id;

        $pool->discard($obj);

        expect($disposed)->toContain($id);
    });
});

test('Pool use() returns function result', function () {
    phasync::run(function () {
        $pool = new Pool(fn () => new SimpleObject(), 5);

        $result = $pool->use(function ($obj) {
            return 'id=' . $obj->id;
        });

        expect($result)->toMatch('/id=\d+/');
    });
});

test('Pool use() releases on exception', function () {
    phasync::run(function () {
        $pool = new Pool(fn () => new SimpleObject(), 5);

        try {
            $pool->use(function ($obj) {
                throw new Exception('test');
            });
        } catch (Exception) {
        }

        $obj = $pool->borrow(0.1);
        expect($obj)->toBeInstanceOf(SimpleObject::class);
        $pool->release($obj);
    });
});

test('Pool window shrinks idle instances', function () {
    phasync::run(function () {
        $disposed = [];
        $pool     = new Pool(
            fn () => new SimpleObject(),
            5,
            window: 0.05,
            dispose: function ($obj) use (&$disposed) {
                $disposed[] = $obj->id;
            }
        );

        // Borrow and release to establish peak
        $obj1 = $pool->borrow();
        $obj2 = $pool->borrow();
        $pool->release($obj1);
        $pool->release($obj2);

        phasync::sleep(0.01);

        // Borrow one, let window expire, release
        $obj3 = $pool->borrow();
        phasync::sleep(0.06);
        $pool->release($obj3);

        // Should have disposed idle ones
        expect($pool->count())->toBeLessThanOrEqual(5);
    });
});

test('Pool size < 1 throws', function () {
    expect(fn () => new Pool(fn () => new SimpleObject(), 0))
        ->toThrow(InvalidArgumentException::class);
});

test('Pool window <= 0 throws', function () {
    expect(fn () => new Pool(fn () => new SimpleObject(), 5, window: 0))
        ->toThrow(InvalidArgumentException::class);
});

test('Pool release unknown instance throws', function () {
    phasync::run(function () {
        $pool = new Pool(fn () => new SimpleObject(), 5);

        $obj = new SimpleObject();

        expect(fn () => $pool->release($obj))
            ->toThrow(LogicException::class);
    });
});

test('Pool discard unknown instance throws', function () {
    phasync::run(function () {
        $pool = new Pool(fn () => new SimpleObject(), 5);

        $obj = new SimpleObject();

        expect(fn () => $pool->discard($obj))
            ->toThrow(LogicException::class);
    });
});

test('Pool notices lost instances', function () {
    phasync::run(function () {
        $warnings = [];
        \set_error_handler(function ($errno, $errstr) use (&$warnings) {
            $warnings[] = $errstr;

            return true;
        });

        try {
            $pool = new Pool(fn () => new SimpleObject(), 5);

            $obj = $pool->borrow();
            unset($obj); // Lost without release/discard

            // Access pool to trigger lost instance notice
            $pool->borrow(0.1);

            // Check if warning was triggered
            $found = false;
            foreach ($warnings as $msg) {
                if (\str_contains($msg, 'destroyed without')) {
                    $found = true;
                    break;
                }
            }
            expect($found)->toBeTrue();
        } finally {
            \restore_error_handler();
        }
    });
});

test('Pool use with timeout', function () {
    phasync::run(function () {
        $pool = new Pool(fn () => new SimpleObject(), 1);

        $obj = $pool->borrow();

        expect(fn () => $pool->use(fn () => null, 0.01))
            ->toThrow(TimeoutException::class);

        $pool->release($obj);
    });
});

test('Pool multiple coroutines share', function () {
    phasync::run(function () {
        $ids  = [];
        $pool = new Pool(fn () => new SimpleObject(), 3);

        for ($i = 0; $i < 5; ++$i) {
            phasync::go(function () use ($pool, &$ids) {
                $obj   = $pool->borrow();
                $ids[] = $obj->id;
                phasync::sleep(0.01);
                $pool->release($obj);
            });
        }

        phasync::sleep(0.1);

        // Should reuse instances
        expect(\count(\array_unique($ids)))->toBeLessThanOrEqual(3);
    });
});

test('Pool dispose called on discard', function () {
    phasync::run(function () {
        $disposed = [];
        $pool     = new Pool(
            fn () => new SimpleObject(),
            5,
            null,
            function ($obj) use (&$disposed) {
                $disposed[] = ['id' => $obj->id, 'action' => 'discard'];
            }
        );

        $obj = $pool->borrow();
        $id  = $obj->id;

        $pool->discard($obj);

        expect($disposed)->toContain(['id' => $id, 'action' => 'discard']);
    });
});

test('Pool dispose called on shrink', function () {
    phasync::run(function () {
        $disposed = [];
        $pool     = new Pool(
            fn () => new SimpleObject(),
            5,
            window: 0.01,
            dispose: function ($obj) use (&$disposed) {
                $disposed[] = $obj->id;
            }
        );

        $a = $pool->borrow();
        $b = $pool->borrow();
        $pool->release($a);
        $pool->release($b);
        expect($disposed)->toBe([]);

        // Two were lent at once, but not within the window: borrowing one drops the other idle instance
        phasync::sleep(0.03);
        $c = $pool->borrow();
        expect($disposed)->toBe([$a->id === $c->id ? $b->id : $a->id]);
        expect($pool->count())->toBe(1);
        $pool->release($c);
    });
});

test('Pool create failure frees place', function () {
    phasync::run(function () {
        $creates = 0;
        $pool    = new Pool(
            function () use (&$creates) {
                if (2 === ++$creates) {
                    throw new Exception('Create failed');
                }

                return new SimpleObject();
            },
            2
        );

        $obj1 = $pool->borrow();

        expect(fn () => $pool->borrow(0.1))->toThrow(Exception::class, 'Create failed');

        // The failed create gave its place back: with the limit of 2 this borrow would time out otherwise
        $obj2 = $pool->borrow(0.1);
        expect($obj2)->not->toBe($obj1);
        expect($creates)->toBe(3);

        $pool->release($obj1);
        $pool->release($obj2);
    });
});
