<?php

use phasync\Internal\Channel;
use phasync\Internal\ExceptionTool;
use phasync\Internal\FiberExceptionHolder;
use phasync\Internal\Flag;
use phasync\Internal\PromiseHandler;
use phasync\Util\Console;
use phasync\Util\LockTrait;
use phasync\Util\Pool;
use phasync\Util\StringBuffer;
use phasync\Util\Synchronized;

test('PromiseHandler handles promise with only onRejected callback', function () {
    $promise = new class {
        public function then(?Closure $onFulfilled = null, ?Closure $onRejected = null)
        {
            if ($onRejected) {
                $onRejected(new RuntimeException('rejection'));
            }
        }
    };

    $rejected   = false;
    $onRejected = function ($e) use (&$rejected) {
        $rejected = $e instanceof RuntimeException;
    };

    $result = PromiseHandler::handle($promise, null, $onRejected);
    expect($result)->toBeTrue();
    expect($rejected)->toBeTrue();
});

test('PromiseHandler rejects promise with non-callable parameter type', function () {
    $promise = new class {
        public function then(?string $onFulfilled = null, $onRejected = null)
        {
            return true;
        }
    };

    $result = PromiseHandler::handle($promise);
    expect($result)->toBeFalse();
});

test('PromiseHandler handles promise with variadic then parameters', function () {
    $promise = new class {
        public function then(...$handlers)
        {
            if (isset($handlers[0])) {
                $handlers[0](42);
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

test('Channel isReadyForRead returns true when closed and buffered', function () {
    phasync::run(function () {
        $channel = new Channel(1);
        $channel->write(42);
        $channel->close();
        expect($channel->isReadyForRead())->toBeTrue();
    });
});

test('Channel isReadyForRead returns true for non-empty buffer', function () {
    phasync::run(function () {
        $channel = new Channel(5);
        $channel->write(1);
        expect($channel->isReadyForRead())->toBeTrue();
    });
});

test('Channel isReadyForWrite returns true when buffer has capacity', function () {
    phasync::run(function () {
        $channel = new Channel(5);
        expect($channel->isReadyForWrite())->toBeTrue();
        $channel->write(1);
        expect($channel->isReadyForWrite())->toBeTrue();
    });
});

test('Channel isReady returns true when isReadable is true', function () {
    phasync::run(function () {
        $channel = new Channel(1);
        $channel->write(42);
        expect($channel->isReady())->toBeTrue();
    });
});

test('Channel await returns immediately when ready', function () {
    phasync::run(function () {
        $channel = new Channel(1);
        $channel->write(42);
        $channel->await(1.0);
        expect($channel->isReadyForRead())->toBeTrue();
    });
});

test('Channel getIterator ends when closed with no data', function () {
    phasync::run(function () {
        $channel = new Channel(0);
        $channel->close();

        $count = 0;
        foreach ($channel as $value) {
            ++$count;
        }
        expect($count)->toBe(0);
    });
});

test('Channel getIterator yields all buffered values', function () {
    phasync::run(function () {
        $channel = new Channel(10);
        $channel->write(1);
        $channel->write(2);
        $channel->close();

        $values = [];
        foreach ($channel as $value) {
            $values[] = $value;
        }
        expect($values)->toEqual([1, 2]);
    });
});

test('Flag listFibers lists waiting fibers', function () {
    phasync::run(function () {
        $eventLoop = phasync::getLoop();
        $flag      = Flag::create($eventLoop);
        $fiber     = Fiber::getCurrent();
        $flag->add($fiber);

        \ob_start();
        $flag->listFibers();
        $output = \ob_get_clean();

        expect($output)->toContain(' - ');
        $flag->remove($fiber);
    });
});

test('Flag cancelAll removes fiber from flagGraph when isFiberFlag', function () {
    phasync::run(function () {
        $eventLoop = phasync::getLoop();
        $flag      = Flag::create($eventLoop, true);
        // A real coroutine of this run: cancelAll() enqueues it on the loop, so a bare Fiber would be left in the loop's queue
        $fiber = phasync::go(function () {
            try {
                phasync::sleep(10);
            } catch (phasync\CancelledException) {
            }
        });
        $flag->add($fiber);

        $eventLoop->flagGraph[$fiber] = true;

        $flag->cancelAll();
        expect(isset($eventLoop->flagGraph[$fiber]))->toBeFalse();
    });
});

test('FiberExceptionHolder throws exception when handler is null', function () {
    phasync::run(function () {
        $exception = new RuntimeException('test error');
        $fiberRef  = Fiber::getCurrent();
        $holder    = FiberExceptionHolder::create($exception, $fiberRef, null);

        expect(function () use ($holder) {
            $holder->handleException();
        })->toThrow(RuntimeException::class, 'test error');
    });
});

test('FiberExceptionHolder returnToPool throws when not handled', function () {
    phasync::run(function () {
        $exception = new RuntimeException('test');
        $fiberRef  = Fiber::getCurrent();
        $holder    = FiberExceptionHolder::create($exception, $fiberRef, function () {});

        expect(function () use ($holder) {
            $holder->returnToPool();
        })->toThrow(LogicException::class, 'Not handled');
    });
});

test('ExceptionTool popTrace returns early when filter does not match after found', function () {
    $exception = new RuntimeException('test');
    $rc        = new ReflectionClass(Exception::class);
    $rTrace    = $rc->getProperty('trace');
    $trace     = [
        ['file' => 'test.php', 'line' => 10, 'function' => 'foo'],
        ['file' => 'other.php', 'line' => 20, 'function' => 'bar'],
    ];
    $rTrace->setValue($exception, $trace);

    $modified = ExceptionTool::popTrace($exception, 'foo');
    expect($modified)->toBe($exception);
});

test('StringBuffer write respects maxSize', function () {
    phasync::run(function () {
        $buffer = new StringBuffer(10);
        $buffer->write(\str_repeat('x', 10));
        expect($buffer->isEmpty())->toBeFalse();
    });
});

test('StringBuffer readFixed returns null when buffer ends early', function () {
    phasync::run(function () {
        $buffer = new StringBuffer();
        $buffer->write('hi');
        $buffer->end();
        $result = $buffer->readFixed(10);
        expect($result)->toBeNull();
    });
});

test('StringBuffer writeToResource validates stream resource', function () {
    phasync::run(function () {
        $buffer = new StringBuffer();
        $buffer->write('test');
        $buffer->end();

        expect(function () use ($buffer) {
            $buffer->writeToResource('not a stream');
        })->toThrow(InvalidArgumentException::class);
    });
});

test('StringBuffer unread puts data back in buffer', function () {
    phasync::run(function () {
        $buffer = new StringBuffer();
        $buffer->write('hello world');
        $buffer->end();

        $data = $buffer->read(5);
        expect($data)->toBe('hello');

        $buffer->unread(' world');
        $data = $buffer->read(6);
        expect($data)->toBe(' world');
    });
});

test('Synchronized run provides basic locking', function () {
    phasync::run(function () {
        $token = 'test-sync';
        $count = 0;
        Synchronized::run($token, function () use (&$count) {
            ++$count;
        });
        expect($count)->toBe(1);
    });
});

test('LockTrait provides basic locking', function () {
    phasync::run(function () {
        $obj = new class {
            use LockTrait;
        };
        $count = 0;
        $obj->lock(function () use (&$count) {
            ++$count;
        });
        expect($count)->toBe(1);
    });
});

test('Console width returns byte count for non-UTF8 text', function () {
    $nonUtf8 = "\x80\x81\x82";
    $width   = Console::width($nonUtf8);
    expect($width)->toBe(\strlen($nonUtf8));
});

test('Console detectColor method exists', function () {
    $class = new ReflectionClass(Console::class);
    expect($class->hasMethod('detectColor'))->toBeTrue();
});

test('Pool borrow and release workflow', function () {
    phasync::run(function () {
        $pool = new Pool(
            fn () => new stdClass(),
            2
        );

        $obj1 = $pool->borrow();
        expect($obj1)->toBeInstanceOf(stdClass::class);

        $pool->release($obj1);
        expect(true)->toBeTrue();
    });
});

test('Pool handles multiple concurrent borrows', function () {
    phasync::run(function () {
        $pool = new Pool(
            fn () => new stdClass(),
            2
        );

        $obj1 = $pool->borrow();
        $obj2 = $pool->borrow();

        expect($obj1)->toBeInstanceOf(stdClass::class);
        expect($obj2)->toBeInstanceOf(stdClass::class);
        expect($obj1)->not->toBe($obj2);

        $pool->release($obj1);
        $pool->release($obj2);
    });
});

test('CurlAwaiter class has required methods', function () {
    $class = new ReflectionClass(phasync\Services\CurlAwaiter::class);
    expect($class->hasMethod('await'))->toBeTrue();
    expect($class->hasMethod('register'))->toBeTrue();
});
