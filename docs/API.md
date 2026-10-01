# API Documentation for phasync

## Overview

The phasync library provides a comprehensive API for building and managing asynchronous operations in PHP using coroutines. It facilitates efficient asynchronous programming, including handling of asynchronous CURL and database connections. The API is centered around coroutines, utilizing fibers to suspend and resume operations without blocking the main execution thread.

## Key Components

 * Fibers: Lightweight threads that allow for non-blocking execution.
 * Contexts: any object groups the coroutines of a run, a request or a task; `getContext()` returns the current one.
 * EventLoop: runs coroutines, and waits for timers, flags and (through its poller) streams.

## Core Functions

### `phasync::run(Closure $coroutine, array $arguments = [], ?object $context = null): mixed`

Executes a coroutine within an event loop, ensuring that all nested coroutines complete before returning. This function is blocking until the coroutine and all its nested operations are completed.

Parameters:

 * $coroutine: The coroutine to execute.
 * $arguments: Optional arguments to pass to the coroutine.
 * $context: Optional *fresh* context to associate with this coroutine run. This context MUST NOT be shared with other run instances.


### `phasync::go(Closure $coroutine, mixed ...$args): Fiber`

Launches a new coroutine immediately without waiting for it to finish, effectively creating and starting a fiber that runs in the background.

Parameters:

 * $coroutine: The coroutine to be executed asynchronously.
 * $args: Arguments to pass to the coroutine.


### `phasync::background(callable $coroutine, array $arguments): Fiber`

Launches a callable (Closure or serializable callback in the shape of a string or an array) in an isolated environment in parallel with the main loop. Technically, this leverages php-fpm to launch the callable in a configurable worker pool queue, so the coroutine will not be able to interact with other coroutines running in your main program.

If the `$coroutine` is passed as a Closure, `opis/closure` will be used to serialize the closure before passing it to be run in the worker pool.

Parameters:

 * $coroutine: The coroutine to be executed in parallel.
 * $args: A serializable array of arguments.
 

### `phasync::await(Fiber|promise-like $awaitable, ?float $timeout = null): mixed`

Suspends the calling coroutine until the specified fiber or promise completes. Throws an exception if the awaitable results in an error or if the operation times out.

Parameters:

 * $awaitable: The fiber or promise-like object to wait for.
 * $timeout: Maximum time in seconds to wait before timing out.


### `phasync::service(Closure $coroutine): void`

Creates a long-running and context-free coroutine which can be used to extend the functionality of phasync by for example polling `curl_multi_*` functions. The coroutine should terminate itself if it no longer provides such services. Also it should use the `phasync::yield()` function to sleep between each tick efficiently, or if a guaranteed polling frequency is needed `phasync::sleep(0.1)` would sleep 0.1 seconds between each tick.

Parameters:

 * $coroutine: The function that performs the polling in a loop.


### `phasync::cancel(Fiber $fiber, ?Throwable $exception = null): void`

Cancels a suspended coroutine, optionally throwing an exception within the coroutine to signal cancellation.

Parameters:

 * $fiber: The fiber to cancel.
 * $exception: Optional exception to throw within the fiber.


### `phasync::sleep(float $seconds = 0): void`

Suspends the current coroutine for the specified number of seconds. If called without a fiber context, it defaults to a simple sleep.

Parameters:

 * $seconds: Time in seconds to suspend the execution.


### `phasync::yield(): void`

Yield execution of the current coroutine. This function causes the coroutine to resume at the end of the next tick, and it does not affect the sleep-time between each tick. Yielding should be used whenever you are waiting for some event to occur inside other coroutines, unless `phasync::await()` or `phasync::awaitFlag()` can be used.


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

### Notes

The API is designed to be used with PHP's native Fiber class available from PHP 8.1 onwards.

Exception handling is crucial, especially in asynchronous operations, to ensure that all errors are managed and do not lead to unhandled exceptions or resource leaks.

This API provides a robust framework for building efficient and scalable asynchronous PHP applications, allowing developers to handle complex asynchronous workflows with ease.
