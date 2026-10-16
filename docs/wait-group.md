# `phasync\Util\WaitGroup` to coordinate work

[Back to README.md](../README.md)

The `WaitGroup` class provides an efficient tool for waiting until multiple coroutines have completed their tasks. It allows you to synchronize the execution of multiple asynchronous operations, ensuring that all tasks are finished before proceeding.

## Class Overview

The `WaitGroup` class maintains a counter that tracks the number of active tasks. It provides methods to add tasks, mark tasks as done, and wait for all tasks to complete.

## Usage

### Initialization

To create a `WaitGroup` instance, simply instantiate the class:

```php
$wg = new \phasync\Util\WaitGroup();
```

### Adding Work

To add work to the `WaitGroup`, use the `add` method. This increments the internal counter, indicating that a new task has started:

```php
$wg->add();
```

### Marking Work as Done

Once a task is completed, call the `done` method to decrement the internal counter:

```php
$wg->done();
```

### Waiting for All Work to Complete

To wait until all added tasks are completed, use the `await` method. This blocks the calling coroutine until the counter reaches zero:

```php
$wg->await();
```

### Example

Here is a basic example of how to use the `WaitGroup` class inside `phasync::run()`:

```php
<?php

use phasync\Util\WaitGroup;

phasync::run(function() {
    $wg = new WaitGroup();

    // Add work for three coroutines
    $wg->add();
    $wg->add();
    $wg->add();

    phasync::go(function() use ($wg) {
        \phasync::sleep(0.1); // Simulate some work
        echo "Task 1 done\n";
        $wg->done();
    });

    phasync::go(function() use ($wg) {
        \phasync::sleep(0.2); // Simulate some work
        echo "Task 2 done\n";
        $wg->done();
    });

    phasync::go(function() use ($wg) {
        \phasync::sleep(0.3); // Simulate some work
        echo "Task 3 done\n";
        $wg->done();
    });

    // Wait for all tasks to complete
    $wg->await();
    echo "All tasks completed\n";
});
```

### Methods

#### `add`

```php
public function add(int $delta = 1): void
```

- **Description**: Adds `$delta` to the internal counter of unfinished work. A negative delta marks work as done.
- **Throws**: `LogicException` if the counter would become negative.

#### `done`

```php
public function done(): void
```

- **Description**: Decrements the internal counter, indicating that a task has finished.
- **Throws**: `LogicException` if `done` is called without a preceding `add`.

#### `await`

```php
public function await(float $timeout = \PHP_FLOAT_MAX): void
```

- **Description**: Blocks the calling coroutine until the counter reaches zero, indicating that all tasks have completed. Returns at once if it is zero already.
- **Throws**: `TimeoutException` if the counter is not zero in time.

#### `isReady`

```php
public function isReady(): bool
```

- **Description**: Returns `true` when the counter is zero.

The `WaitGroup` class implements `SelectableInterface` (`await()` and `isReady()`).

#### `wait`

Deprecated alias of `await()`.

## Conclusion

The `WaitGroup` class is a powerful tool for synchronizing the completion of multiple asynchronous tasks in `phasync`. By adding tasks with `add`, marking them as done with `done`, and waiting for all tasks to complete with `await`, you can easily manage complex asynchronous workflows.

For more detailed information and advanced usage, refer to the `phasync` documentation and examples.
