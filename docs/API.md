# API Documentation for phasync

## Overview

The phasync library provides a comprehensive API for building and managing asynchronous operations in PHP using coroutines. It facilitates efficient asynchronous programming, including handling of asynchronous CURL and database connections. The API is centered around coroutines, utilizing fibers to suspend and resume operations without blocking the main execution thread.

## Key Components

 * Fibers: Lightweight threads that allow for non-blocking execution.
 * Frames (`phasync\Frame`): the lifetime of a `run()`. Every `run()` creates one; the coroutines started inside it belong to it, and it waits for them, cancels them and rethrows their failures. `phasync::frame()` returns the current one.
 * Scopes (`\Scope`, from `fiber-interop/scope`): the identity of the operation (a request, a job) the code belongs to. `Scope::getCurrent()` returns it; coroutines keep the scope they were started in. Keep per-request state in a `WeakMap` keyed by it.
 * EventLoop: runs coroutines, and waits for timers, flags and (through its poller) streams.

## Core Functions

### `phasync::run(callable $fn, mixed ...$args): mixed`

Runs `$fn` and returns its result once it and every coroutine started inside it have ended. Outside a coroutine it starts the event loop and blocks until then. Inside a coroutine it runs `$fn` inline in that coroutine, in a new frame nested in the caller's, and waits only for what `$fn` started.

A failure does not cancel the other coroutines. Once they have all ended, `run()` throws what `$fn` threw; else the earliest failure of a coroutine nobody awaited; else it returns what `$fn` returned. Later failures are not thrown; with `Frame::setExceptionHandler()` they all go to the handler instead. When the outermost `run()` has no coroutine left, it cancels the services and returns once they have ended. `run()` does not change the current `Scope`.

Parameters:

 * $fn: What to run.
 * $args: Passed to `$fn`.


### `phasync::go(callable $fn, mixed ...$args): Fiber`

Starts `$fn` as a coroutine and returns it without waiting for it. The coroutine runs at once, up to its first wait. It belongs to the caller's frame, and runs in the `Scope` current where `go()` is called, also after it waited. Throws `LogicException` outside a coroutine.

Parameters:

 * $fn: The coroutine.
 * $args: Passed to `$fn`.


### `phasync::frame(): Frame`

The frame of the running coroutine: that of the innermost `run()` it is in. Throws `LogicException` outside a coroutine and in a service.

```php
phasync::run(function () {
    $frame = phasync::frame();
    $frame->setTimeout(2.0);    // cancels the frame after 2 seconds; run() throws TimeoutException
    $frame->setExceptionHandler(fn (Throwable $e) => error_log($e->getMessage()));
    phasync::go(fn () => fetch('a'));
    phasync::go(fn () => fetch('b'));
    // $frame->cancel() cancels every coroutine of the frame and of the frames nested in it
});
```


### `phasync::await(Fiber|promise-like $awaitable, ?float $timeout = null): mixed`

Suspends the calling coroutine until the specified fiber or promise completes. Throws an exception if the awaitable results in an error or if the operation times out.

Parameters:

 * $awaitable: The fiber or promise-like object to wait for.
 * $timeout: Maximum time in seconds to wait before timing out.


### `phasync::service(callable $fn, mixed ...$args): Fiber`

Starts `$fn` as a service and returns its coroutine: one coroutine that serves many others and is started on first use, such as a connection manager or the `curl_multi_*` poller. It runs in the root scope (`Scope::getRootScope()`) and has no frame, so no `run()` waits for it. When the outermost `run()` has no coroutine left, it cancels its services and returns once they have ended, so a service must be written to be started again by the next use. A failure nobody awaits goes to the outermost `run()`.

Parameters:

 * $fn: The service.
 * $args: Passed to `$fn`.


### `phasync::cancel(Fiber $fiber, string|Stringable|CancelledException $message = 'Operation cancelled', int $code = 0, ?Throwable $previous = null): void`

Cancels a coroutine, and not the coroutines it started; to cancel a frame, use `$frame->cancel()`. Sticky: the coroutine's waits throw a `CancelledException` until it ends, so a coroutine that catches it and waits again is cancelled again. `$previous` is what caused the cancellation.


### `phasync::onScopeChange(callable $fn): void`

Registers `$fn(Scope $new, ?Scope $old)`, called before a coroutine runs when its scope differs from the one the loop last made current: on start and on resume, never between coroutines of one scope. Use it to swap state PHP keeps globally (a locale, a library's static) per request. Hooks run in the event loop, outside any coroutine: they must not wait, and an exception from one ends `phasync::run()`. They stay registered for the life of the loop.

### `phasync::throw(Fiber $fiber, Throwable $exception): void`

Interrupts the wait of a suspended coroutine with an exception, once. The coroutine can catch it and carry on; nothing is remembered. Throws `LogicException` if the coroutine is not waiting.


### `phasync::sleep(float $seconds = 0): void`

Suspends the current coroutine for the specified number of seconds. Outside a coroutine, it is a plain sleep.

Parameters:

 * $seconds: Time in seconds to suspend the execution.


### `phasync::yield(): void`

Yield execution of the current coroutine. This function causes the coroutine to resume at the end of the next tick, and it does not affect the sleep-time between each tick. Yielding should be used whenever you are waiting for some event to occur inside other coroutines, unless `phasync::await()` or `phasync::awaitFlag()` can be used.


### `phasync::idle(float $after = 0.0): void`

Suspends the current coroutine until the event loop has had nothing to run for at least `$after` seconds: the ready queue and the callback queue are both empty, and an immediate poll finds no I/O ready. Waiters wake one per such idle moment, oldest first, so each wakes exactly one coroutine before the loop measures the next idle moment for whoever is waiting next. Fits an admission gate that lets one more request in, then re-measures.

Parameters:

 * $after: Seconds the loop must have had nothing runnable, before this coroutine wakes.


### `phasync::readable(mixed $resource, float $timeout = PHP_FLOAT_MAX): mixed`

Suspends the coroutine until the stream resource becomes readable, or the timeout is reached. If the timeout is reached, a TimeoutException is thrown. One coroutine at a time may wait to read a stream; a second one gets LogicException.

Parameters:

 * $resource: The stream resource to monitor
 * $timeout: The max number of seconds to remain suspended.


### `phasync::writable(mixed $resource, float $timeout = PHP_FLOAT_MAX): mixed`

Suspends the coroutine until the stream resource becomes writable, or the timeout is reached. If the timeout is reached, a TimeoutException is thrown. One coroutine at a time may wait to write to a stream; a reader may wait meanwhile.

Parameters:

 * $resource: The stream resource to monitor
 * $timeout: The max number of seconds to remain suspended.


### `phasync::raiseFlag(object $signal): int`

Signals all coroutines waiting on a specific flag to resume.

Parameters:

 * $signal: The flag object to signal.

### `phasync::awaitFlag(object $signal, float $timeout = null): void`

Suspends the execution of the current coroutine until the specified flag is signaled or the operation times out.

Parameters:

 * $signal: The flag object to wait for.
 * $timeout: Timeout in seconds.

### `phasync::getLoop(): EventLoop`

The event loop, inside `phasync::run()` (LogicException outside it). Its low-level wait is for code that owns its waits (pollers, services, channels), and is lighter than a flag:

```php
$loop = phasync::getLoop();
$slot = $loop->getSlot();        // a slot number no one else has
$loop->park($slot, $timeout);    // suspend until unparked; TimeoutException, or CancelledException
$loop->unpark($slot);            // from elsewhere: true if it resumed a coroutine, false if the slot was vacant
```

What is parked must be unparked, or it waits until its timeout: nothing notices a forgotten slot, as the garbage collector notices a forgotten flag. Flags are for objects other code holds; slots are for waits inside one component.

A `Fiber` that phasync did not start can drive the loop in place with `$loop->tick()`; the running coroutine and its frame are restored when it returns.

### Notes

The API is designed to be used with PHP's native Fiber class available from PHP 8.1 onwards.

Exception handling is crucial, especially in asynchronous operations, to ensure that all errors are managed and do not lead to unhandled exceptions or resource leaks.

This API provides a robust framework for building efficient and scalable asynchronous PHP applications, allowing developers to handle complex asynchronous workflows with ease.
