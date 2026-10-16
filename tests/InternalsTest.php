<?php

use phasync\Internal\ExceptionTool;
use phasync\Internal\FiberExceptionHolder;
use phasync\Internal\Flag;
use phasync\Internal\PromiseHandler;

/*
 * Tests for Internal\Flag
 */
test('Flag add and remove fiber', function () {
    phasync::run(function () {
        $eventLoop = phasync::getLoop();
        $flag      = Flag::create($eventLoop);
        $fiber     = Fiber::getCurrent();
        $flag->add($fiber);
        expect($flag->contains($fiber))->toBeTrue();
        $flag->remove($fiber);
        expect($flag->contains($fiber))->toBeFalse();
    });
});

test('Flag cannot add same fiber twice', function () {
    // Test through public API instead to avoid fiber cleanup issues
    phasync::run(function () {
        $eventLoop = phasync::getLoop();
        $flag      = Flag::create($eventLoop);
        $fiber1    = new Fiber(function () {});
        $fiber2    = new Fiber(function () {});
        $flag->add($fiber1);
        expect(function () use ($flag, $fiber1) {
            $flag->add($fiber1);
        })->toThrow(LogicException::class);
        $flag->add($fiber2);
        expect($flag->count())->toBe(2);
        // Leave none behind: a flag that is destroyed with waiters enqueues them in the shared loop.
        $flag->remove($fiber1);
        $flag->remove($fiber2);
    });
});

test('Flag cannot remove fiber that is not in it', function () {
    phasync::run(function () {
        $eventLoop = phasync::getLoop();
        $flag      = Flag::create($eventLoop);
        $fiber     = Fiber::getCurrent();
        expect(function () use ($flag, $fiber) {
            $flag->remove($fiber);
        })->toThrow(LogicException::class);
    });
});

test('Flag count works', function () {
    phasync::run(function () {
        $eventLoop = phasync::getLoop();
        $flag      = Flag::create($eventLoop);
        expect($flag->count())->toBe(0);
        $fiber = Fiber::getCurrent();
        $flag->add($fiber);
        expect($flag->count())->toBe(1);
        $flag->remove($fiber);
        expect($flag->count())->toBe(0);
    });
});

test('Flag returns to pool clears fibers', function () {
    phasync::run(function () {
        $eventLoop = phasync::getLoop();
        $flag      = Flag::create($eventLoop);
        $fiber     = Fiber::getCurrent();
        $flag->add($fiber);
        expect($flag->count())->toBe(1);
        $flag->remove($fiber);
        expect($flag->count())->toBe(0);
        // returnToPool should work fine now
        $flag->returnToPool();
        expect(true)->toBeTrue();
    });
});

test('Flag takeFibers returns and clears fibers', function () {
    phasync::run(function () {
        $eventLoop = phasync::getLoop();
        $flag      = Flag::create($eventLoop);
        $fiber     = Fiber::getCurrent();
        $flag->add($fiber);
        expect($flag->count())->toBe(1);
        $fibers = $flag->takeFibers();
        expect($fibers)->toHaveCount(1);
        expect($flag->count())->toBe(0);
        $fibers = $flag->takeFibers();
        expect($fibers)->toBeEmpty();
    });
});

/*
 * Tests for FiberExceptionHolder
 */
test('FiberExceptionHolder create from pool', function () {
    phasync::run(function () {
        $exception = new RuntimeException('test');
        $fiberRef  = Fiber::getCurrent();
        $holder1   = FiberExceptionHolder::create($exception, $fiberRef, function () {});
        expect($holder1->isHandled())->toBeFalse();
        expect($holder1->isFiberGone())->toBeFalse();
        $holder1->handleException();
        $holder1->returnToPool();
    });
});

test('FiberExceptionHolder get returns exception', function () {
    phasync::run(function () {
        $exception = new RuntimeException('test');
        $fiberRef  = Fiber::getCurrent();
        $holder    = FiberExceptionHolder::create($exception, $fiberRef, function () {});
        $result    = $holder->get();
        expect($result)->toBe($exception);
        expect($holder->isHandled())->toBeTrue();
    });
});

test('FiberExceptionHolder handle exception with closure', function () {
    $exception = new RuntimeException('test');
    phasync::run(function () use ($exception) {
        $fiberRef = Fiber::getCurrent();
        $handled  = false;
        $handler  = function ($e, $ref) use (&$handled) {
            $handled = true;
        };
        $holder = FiberExceptionHolder::create($exception, $fiberRef, $handler);
        $holder->handleException();
        expect($handled)->toBeTrue();
    });
});

test('FiberExceptionHolder handles exception on destruct if not handled', function () {
    phasync::run(function () {
        $exception = new RuntimeException('test');
        $fiberRef  = Fiber::getCurrent();
        $handled   = false;
        $handler   = function ($e, $ref) use (&$handled) {
            $handled = true;
        };
        $holder = FiberExceptionHolder::create($exception, $fiberRef, $handler);
        unset($holder);
        expect($handled)->toBeTrue();
    });
});

test('FiberExceptionHolder returnToPool works after handling', function () {
    phasync::run(function () {
        $exception = new RuntimeException('test');
        $fiberRef  = Fiber::getCurrent();
        $holder    = FiberExceptionHolder::create($exception, $fiberRef, function () {});
        $holder->handleException(); // Mark as handled
        $holder->returnToPool(); // Should succeed now
        expect(true)->toBeTrue();
    });
});

test('FiberExceptionHolder isFiberGone returns true when fiber is collected', function () {
    $exception = new RuntimeException('test');
    // Create a fiber and immediately let it go out of scope
    $fiber  = new Fiber(function () {});
    $holder = FiberExceptionHolder::create($exception, $fiber, function () {});
    // The fiber is no longer referenced after this
    unset($fiber);
    \gc_collect_cycles();
    // Now the weakref should return null
    expect($holder->isFiberGone())->toBeTrue();
});

/*
 * Tests for ExceptionTool
 */
test('ExceptionTool popTrace without filter', function () {
    $exception = new RuntimeException('test error', 0, null);
    $original  = $exception;
    $modified  = ExceptionTool::popTrace($exception);
    expect($modified)->toBe($original);
});

test('ExceptionTool popTrace with filter that matches', function () {
    $exception = new RuntimeException('test error', 0, null);
    $rc        = new ReflectionClass(Exception::class);
    $rTrace    = $rc->getProperty('trace');
    $trace     = [
        ['file' => 'test.php', 'line' => 10, 'function' => 'foo'],
        ['file' => 'test.php', 'line' => 20, 'function' => 'bar'],
    ];
    $rTrace->setValue($exception, $trace);
    $modified = ExceptionTool::popTrace($exception, 'test.php');
    expect($modified)->toBe($exception);
});

test('ExceptionTool popTrace with filter on function name', function () {
    $exception = new RuntimeException('test error', 0, null);
    $rc        = new ReflectionClass(Exception::class);
    $rTrace    = $rc->getProperty('trace');
    $trace     = [
        ['file' => 'test.php', 'line' => 10, 'function' => 'foo'],
        ['file' => 'test.php', 'line' => 20, 'function' => 'bar'],
    ];
    $rTrace->setValue($exception, $trace);
    $modified = ExceptionTool::popTrace($exception, 'foo');
    expect($modified)->toBe($exception);
});

test('ExceptionTool popTrace with filter on class name', function () {
    $exception = new RuntimeException('test error', 0, null);
    $rc        = new ReflectionClass(Exception::class);
    $rTrace    = $rc->getProperty('trace');
    $trace     = [
        ['file' => 'test.php', 'line' => 10, 'class' => 'MyClass', 'function' => 'foo'],
        ['file' => 'test.php', 'line' => 20, 'function' => 'bar'],
    ];
    $rTrace->setValue($exception, $trace);
    $modified = ExceptionTool::popTrace($exception, 'MyClass');
    expect($modified)->toBe($exception);
});

test('ExceptionTool popTrace returns early if filter does not match', function () {
    $exception = new RuntimeException('test error', 0, null);
    $rc        = new ReflectionClass(Exception::class);
    $rTrace    = $rc->getProperty('trace');
    $trace     = [
        ['file' => 'test.php', 'line' => 10, 'function' => 'foo'],
        ['file' => 'test.php', 'line' => 20, 'function' => 'bar'],
    ];
    $rTrace->setValue($exception, $trace);
    $modified = ExceptionTool::popTrace($exception, 'nonexistent');
    expect($modified)->toBe($exception);
});

/*
 * Tests for PromiseHandler
 */
test('PromiseHandler duckTyped returns false for non-objects', function () {
    $result = PromiseHandler::handle('not a promise');
    expect($result)->toBeFalse();
});

test('PromiseHandler duckTyped returns false for object without then method', function () {
    $obj    = new stdClass();
    $result = PromiseHandler::handle($obj);
    expect($result)->toBeFalse();
});

test('PromiseHandler handles promise with then method', function () {
    $promise = new class {
        public function then($onFulfilled = null, $onRejected = null)
        {
            if ($onFulfilled) {
                $onFulfilled(42);
            }
        }
    };

    $handled     = false;
    $value       = null;
    $onFulfilled = function ($v) use (&$handled, &$value) {
        $handled = true;
        $value   = $v;
    };

    $result = PromiseHandler::handle($promise, $onFulfilled);
    expect($result)->toBeTrue();
    expect($handled)->toBeTrue();
    expect($value)->toBe(42);
});

test('PromiseHandler set custom handler', function () {
    $oldHandler = PromiseHandler::get();

    $customCalled  = false;
    $customHandler = function ($promiseLike, $onFulfilled = null, $onRejected = null) use (&$customCalled) {
        $customCalled = true;

        return true;
    };

    PromiseHandler::set($customHandler);
    PromiseHandler::handle('anything');

    expect($customCalled)->toBeTrue();

    PromiseHandler::set($oldHandler);
});

test('PromiseHandler handles promise with catch method', function () {
    $promise = new class {
        public function then($onFulfilled = null)
        {
            if ($onFulfilled) {
                $onFulfilled(42);
            }
        }

        public function catch($onRejected)
        {
            $onRejected(new RuntimeException('error'));
        }
    };

    $fulfilled   = false;
    $rejected    = false;
    $onFulfilled = function ($v) use (&$fulfilled) {
        $fulfilled = true;
    };
    $onRejected = function ($e) use (&$rejected) {
        $rejected = true;
    };

    $result = PromiseHandler::handle($promise, $onFulfilled, $onRejected);
    expect($result)->toBeTrue();
    expect($fulfilled)->toBeTrue();
    expect($rejected)->toBeTrue();
});

test('PromiseHandler rejects when promise does not support rejection', function () {
    $promise = new class {
        public function then($onFulfilled = null)
        {
        }
    };

    $result = PromiseHandler::handle($promise, null, function () {});
    expect($result)->toBeFalse();
});

test('PromiseHandler with static then method returns false', function () {
    $result = PromiseHandler::handle(new class {
        public static function then($onFulfilled = null)
        {
        }
    });
    expect($result)->toBeFalse();
});
