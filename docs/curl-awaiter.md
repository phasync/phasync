# `phasync\Services\CurlAwaiter` for concurrent HTTP requests

[Back to README.md](../README.md)

The `CurlAwaiter` class provides asynchronous handling of cURL handles within the `phasync` framework. This allows you to perform multiple cURL requests concurrently using fibers.

## Class Overview

The `CurlAwaiter` class is designed to manage multiple cURL handles asynchronously. It uses the `curl_multi_*` functions to handle multiple cURL handles and `phasync` fibers to manage asynchronous execution.

## Usage

### Initialization

You don't need to explicitly initialize the `CurlAwaiter` class. It automatically initializes when you use it.

### Running Asynchronous cURL Requests

To run asynchronous cURL requests using `CurlAwaiter`, you need to use the `CurlAwaiter::await` method from within a `phasync` coroutine. Here's how you can do it:

1. **Create a cURL Handle**: Initialize a cURL handle with the desired URL and options.
2. **Use `CurlAwaiter::await`**: Pass the cURL handle to `CurlAwaiter::await` to run the request asynchronously.

### Example

```php
<?php

use phasync\Services\CurlAwaiter;

// Run the example inside phasync::run()
phasync::run(function () {
    // Create a coroutine for the first cURL request
    $a = phasync::go(function () {
        $ch = \curl_init('https://httpbin.org/get');
        \curl_setopt($ch, \CURLOPT_RETURNTRANSFER, true);

        $body = CurlAwaiter::await($ch);

        return [$body, \curl_errno($ch)];
    });

    // Create a coroutine for the second cURL request
    $b = phasync::go(function () {
        $ch = \curl_init('https://httpbin.org/get');
        \curl_setopt($ch, \CURLOPT_RETURNTRANSFER, true);

        $body = CurlAwaiter::await($ch);

        return [$body, \curl_errno($ch)];
    });

    // Await the results of the coroutines
    [$bodyA, $errorA] = phasync::await($a);
    [$bodyB, $errorB] = phasync::await($b);

    // Handle the results
    if (0 === $errorA) {
        echo "Request A succeeded: " . $bodyA;
    } else {
        echo "Request A failed.";
    }

    if (0 === $errorB) {
        echo "Request B succeeded: " . $bodyB;
    } else {
        echo "Request B failed.";
    }
});
```

### Error Handling

A failed transfer does not throw, and `CurlAwaiter::await` returns an empty string for it. Check `curl_errno($ch)` (and `curl_error($ch)`) after it returns, as in the example above.

### Advanced Usage

#### Handling Multiple Concurrent Requests

You can handle multiple concurrent cURL requests by creating multiple coroutines and awaiting their results.

```php
<?php

use phasync\Services\CurlAwaiter;

phasync::run(function () {
    $handles = [];

    // Create 10 concurrent cURL requests
    for ($i = 0; $i < 10; ++$i) {
        $handles[] = phasync::go(function () {
            $ch = \curl_init('https://httpbin.org/get');
            \curl_setopt($ch, \CURLOPT_RETURNTRANSFER, true);

            return CurlAwaiter::await($ch);
        });
    }

    // Await the results of all coroutines
    foreach ($handles as $handle) {
        $result = phasync::await($handle);
        if ($result !== false) {
            echo "Request succeeded: " . $result;
        } else {
            echo "Request failed.";
        }
    }
});
```

#### Sequential vs Concurrent Execution

To measure the performance of sequential versus concurrent execution, you can use the following approach:

```php
<?php

use phasync\Services\CurlAwaiter;

// Measure sequential execution time
$sequentialTime = phasync::run(function () {
    $start = microtime(true);

    for ($i = 0; $i < 5; ++$i) {
        $ch = \curl_init('https://httpbin.org/delay/1');
        \curl_setopt($ch, \CURLOPT_RETURNTRANSFER, true);
        CurlAwaiter::await($ch);
    }

    return \microtime(true) - $start;
});

echo "Sequential Time: " . $sequentialTime . " seconds\n";

// Measure concurrent execution time
$concurrentTime = phasync::run(function () {
    $start = microtime(true);
    $handles = [];

    for ($i = 0; $i < 5; ++$i) {
        $handles[] = phasync::go(function () {
            $ch = \curl_init('https://httpbin.org/delay/1');
            \curl_setopt($ch, \CURLOPT_RETURNTRANSFER, true);

            return CurlAwaiter::await($ch);
        });
    }

    foreach ($handles as $handle) {
        phasync::await($handle);
    }

    return \microtime(true) - $start;
});

echo "Concurrent Time: " . $concurrentTime . " seconds\n";
```

## API Reference

### `CurlAwaiter::await(\CurlHandle $ch)`

- **Description**: Runs the given cURL handle asynchronously and waits for its completion.
- **Parameters**:
  - `\CurlHandle $ch`: The cURL handle to run.
- **Returns**: The response body as a string (set `CURLOPT_RETURNTRANSFER`), or an empty string when the transfer fails: check `curl_errno($ch)`.

## Conclusion

The `CurlAwaiter` class allows you to efficiently run multiple cURL requests concurrently using the `phasync` framework. By leveraging the power of fibers, you can improve the performance of your application when dealing with multiple HTTP requests.

For more detailed information and advanced usage, refer to the `phasync` documentation and examples.
