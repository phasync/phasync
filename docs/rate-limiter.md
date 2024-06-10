# `phasync\Util\RateLimiter` for rate limiting

[Back to README.md](../README.md)

The `RateLimiter` class provides an efficient way to limit the rate at which events happen, potentially across coroutines. It ensures that a specified number of events occur per second, optionally allowing for bursts of activity.

## Class Overview

The `RateLimiter` class uses a channel-based mechanism to control the rate of events. It can be used to ensure that certain operations do not exceed a specified rate, making it useful for tasks such as API rate limiting, controlling resource access, or any scenario where you need to throttle events.

## Usage

### Initialization

To create a `RateLimiter` instance, you need to specify the number of events per second. Optionally, you can also specify a burst size, which allows a certain number of events to happen immediately.

### Constructor

```php
public function __construct(float $eventsPerSecond, int $burst = 0)
```

- **Parameters**:
  - `eventsPerSecond` (float): The rate at which events are allowed to happen, expressed in events per second.
  - `burst` (int, optional): The number of events that can happen immediately before the rate limiting starts. Default is 0.

### Example

Here's an example of how to use the `RateLimiter` class within a `phasync` context:

```php
<?php

use phasync\Util\RateLimiter;

phasync::run(function() {
    $rateLimiter = new RateLimiter(10); // 10 events per second
    phasync::go(function() use ($rateLimiter) {
        for ($i = 0; $i < 100; $i++) {
            $rateLimiter->wait();
            echo "This happens 10 times per second\n";
        }
    });
});
```

### Methods

#### `await`

```php
public function await(): void
```

- **Description**: Blocks the current coroutine until the rate limiter allows the next event. This method is used internally by `wait`.

#### `getSelectManager`

```php
public function getSelectManager(): SelectManager
```

- **Description**: Returns the `SelectManager` associated with the rate limiter's read channel. This is used internally for selecting on multiple channels.

#### `selectWillBlock`

```php
public function selectWillBlock(): bool
```

- **Description**: Returns `true` if selecting on the rate limiter's read channel would block. This is used internally for checking if a select operation will block.

#### `wait`

```php
public function wait(): void
```

- **Description**: Blocks the current coroutine if rate limiting is needed. This is the main method used to enforce the rate limit.

### Error Handling

The `RateLimiter` constructor throws an `InvalidArgumentException` if the `eventsPerSecond` parameter is less than or equal to 0.

### Example with Burst

The following example demonstrates how to use the `RateLimiter` class with a burst:

```php
<?php

use phasync\Util\RateLimiter;

phasync::run(function() {
    $rateLimiter = new RateLimiter(10, 5); // 10 events per second, burst of 5
    phasync::go(function() use ($rateLimiter) {
        for ($i = 0; $i < 10; $i++) {
            $rateLimiter->wait();
            echo "This happens with a burst of 5, then 10 times per second\n";
        }
    });
});
```

## Conclusion

The `RateLimiter` class is a powerful tool for controlling the rate of events in your applications. By integrating it with the `phasync` framework, you can easily manage and throttle operations across multiple coroutines. Use the provided methods and examples to effectively implement rate limiting in your projects.
