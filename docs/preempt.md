# phasync::preempt() Documentation

[Back to README.md](../README.md)

## Overview

`phasync::preempt()` is a special function in the `phasync` coroutine library for PHP that ensures fair execution of coroutines by preventing any single coroutine from monopolizing the CPU. It achieves this by measuring the time a coroutine has been running and, if it exceeds a configurable threshold, suspends the coroutine to allow other coroutines to perform work. This function is essential for maintaining the responsiveness of your application, especially when running long-running or blocking tasks.

## Purpose

In a concurrent environment, it's crucial to ensure that no single coroutine runs for too long without yielding control, as this can lead to unresponsiveness in your application. While it's best to avoid time-consuming blocking tasks within coroutines, there are scenarios where such tasks are necessary. `phasync::preempt()` allows you to write these tasks without making the entire process unresponsive.

## Usage

### Basic Example

Here is a basic example demonstrating the use of `phasync::preempt()` in a coroutine:

```php
<?php

use phasync;

phasync::run(function () {
    phasync::go(function () {
        for ($i = 0; $i < 100000000; ++$i) {
            if ($i % 1000000 == 0) {
                echo "Counter: $i\n";
                phasync::preempt(); // Suspend coroutine if running too long
            }
        }
        echo "Long running task completed\n";
    });
});
```

In this example, `phasync::preempt()` is called periodically to ensure that the long-running loop doesn't monopolize the CPU. This allows other coroutines to run and keeps the application responsive.

### Best Practices

- **Avoid Innermost Loops**: While it's important to call `phasync::preempt()` in long-running tasks, avoid placing it in the innermost loops. Instead, place it in outer loops or conditional blocks where it will be called less frequently.
- **Configure Threshold**: The time threshold for `phasync::preempt()` can be configured to suit your application's needs. Ensure it's set to a reasonable value that balances responsiveness and performance.
- **Monitor Performance**: Use profiling and monitoring tools to identify bottlenecks and adjust your usage of `phasync::preempt()` accordingly.

### Example with Nested Loops

Here is an example showing the appropriate placement of `phasync::preempt()` in nested loops:

```php
<?php

use phasync;

phasync::run(function () {
    phasync::go(function () {
        for ($i = 0; $i < 1000; ++$i) {
            for ($j = 0; $j < 1000; ++$j) {
                // Perform some work here
            }
            if ($i % 100 == 0) {
                phasync::preempt(); // Suspend coroutine if running too long
            }
        }
        echo "Nested loops task completed\n";
    });
});
```

In this example, `phasync::preempt()` is placed in the outer loop to reduce the number of invocations, ensuring efficient and fair execution without excessive overhead.

## Integration with Existing Code

Integrating `phasync::preempt()` into existing code can help improve the responsiveness of long-running tasks. Simply identify long-running sections of your code and inject calls to `phasync::preempt()` at appropriate intervals.

### Example in Existing Algorithm

```php
<?php

use phasync;

function complexAlgorithm() {
    for ($i = 0; $i < 1000000; ++$i) {
        // Perform complex calculations here
        if ($i % 10000 == 0) {
            phasync::preempt(); // Inject preempt call to keep application responsive
        }
    }
}

phasync::run(function () {
    phasync::go(function () {
        complexAlgorithm();
        echo "Algorithm completed\n";
    });
});
```

In this example, `phasync::preempt()` is integrated into a complex algorithm, ensuring that the coroutine yields control periodically and the application remains responsive.

## Conclusion

`phasync::preempt()` is a powerful tool for maintaining the responsiveness of your application by ensuring fair execution of coroutines. By strategically placing `phasync::preempt()` in your code, you can prevent any single coroutine from monopolizing the CPU, allowing for efficient and responsive execution of concurrent tasks. Use this function to enhance the performance and user experience of your `phasync`-based applications.
