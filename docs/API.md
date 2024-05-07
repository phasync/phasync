# API Documentation for phasync

## Overview

The phasync library provides a comprehensive API for building and managing asynchronous operations in PHP using coroutines. It facilitates efficient asynchronous programming, including handling of asynchronous CURL and database connections. The API is centered around coroutines, utilizing fibers to suspend and resume operations without blocking the main execution thread.

## Key Components

 * Fibers: Lightweight threads that allow for non-blocking execution.
 * ContextInterface: Manages groups of related fibers, providing a mechanism to group and control coroutine behavior collectively.
 * DriverInterface: The backend mechanism that handles the scheduling and execution of fibers.

## Core Functions

### `phasync::run(Closure $coroutine, array $arguments = [], ContextInterface $context = null): mixed`

Executes a coroutine within an event loop, ensuring that all nested coroutines complete before returning. This function is blocking until the coroutine and all its nested operations are completed.

Parameters:

 * $coroutine: The coroutine to execute.
 * $arguments: Optional arguments to pass to the coroutine.
 * $context: Optional *unused* context to associate with this coroutine run.


### `phasync::go(Closure $coroutine, mixed ...$args): Fiber`

Launches a new coroutine immediately without waiting for it to finish, effectively creating and starting a fiber in the background.

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


### `phasync::idle(?float $timeout=null): void`

Pause the execution of the current coroutine until the timeout is reached, or until there are no coroutines that are about to run immediately.

Parameters:

 * $timeout: The maximum number of seconds to wait.


### `phasync::readable(mixed $resource, ?float $timeout=null): void`

Suspends the coroutine until the stream resource becomes readable, or the timeout is reached. If the timeout is reached, a TimeoutException is thrown. This is equivalent to using `phasync::stream($resource, $timeout, phasync::READABLE)`.

Parameters:

 * $resource: The stream resource to monitor
 * $timeout: The max number of seconds to remain suspended.


### `phasync::writable(mixed $resource, ?float $timeout=null): void`

Suspends the coroutine until the stream resource becomes writable, or the timeout is reached. If the timeout is reached, a TimeoutException is thrown. This is equivalent to using `phasync::stream($resource, $timeout, phasync::WRITABLE)`.

Parameters:

 * $resource: The stream resource to monitor
 * $timeout: The max number of seconds to remain suspended.


### `phasync::stream(mixed $resource, int $mode, float $timeout = null): void`

Suspends the coroutine until the specified stream resource becomes available for reading, writing, or until an exception occurs on the stream.

Parameters:

 * $resource: The stream resource to monitor.
 * $mode: The type of IO-event to await (phasync::READABLE, phasync::WRITABLE, or phasync::EXCEPT).
 * $timeout: Timeout in seconds.


### `phasync::raiseFlag(object $signal): int`

Signals all coroutines waiting on a specific flag to resume.

Parameters:

 * $signal: The flag object to signal.

### `phasync::awaitFlag(object $signal, float $timeout = null): void`

Suspends the execution of the current coroutine until the specified flag is signaled or the operation times out.

Parameters:

 * $signal: The flag object to wait for.
 * $timeout: Timeout in seconds.

## Configuration Functions

### `phasync::setDriver(DriverInterface $driver): void`

Sets the driver that powers the event loop and task scheduling. This must be set before any asynchronous operations are performed.

Parameters:

 * $driver: The driver to set.


### `phasync::setPromiseHandler(Closure $promiseHandlerFunction): void`

Set a promise handler function. The function allows phasync to interact with promise implementations from other frameworks in a configurable way. The function has the following signature:

`function($object, ?Closure $onFulfilled, ?Closure $onRejected): bool`

The function will return `false` if $object is not promise-like or `true` if it is promise like and the `$onFulfilled` and `$onRejected` callbacks were provided and successfully subscribed to the promise. The default promise handler function correctly works with objects having a `then($onFulfilled, $onRejected)` method, or objects having a `then($onFulfilled)` and a `catch($onRejected)` function.

NOTE! The promise handler can be extended by getting the current promise handler via `phasync::getPromiseHandler()` and wrapping it in your new promise handler function.

Parameters:

 * $promiseHandlerFunction: The new promise handler function to use.


### `phasync::setDefaultTimeout(float $timeout): void`

Sets the default timeout for all coroutine blocking operations. When a timeout occurs in any suspended coroutine, a `TimeoutException` is thrown for all `phasync::*` functions that have a return value.

Parameters:

 * $timeout: Timeout in seconds.


## Utility Functions

### `phasync::getDefaultTimeout(): float`

Returns the currently configured default timeout for blocking operations.


### `phasync::getPromiseHandler(): Closure`

Returns the currently configured promise handler function. This can be used to add support for additional promise-like objects, by first extracting the current promise handler and then setting a new promise handler that falls back to the previous promise handler.


### Notes

The API is designed to be used with PHP's native Fiber class available from PHP 8.1 onwards.

Exception handling is crucial, especially in asynchronous operations, to ensure that all errors are managed and do not lead to unhandled exceptions or resource leaks.

This API provides a robust framework for building efficient and scalable asynchronous PHP applications, allowing developers to handle complex asynchronous workflows with ease.