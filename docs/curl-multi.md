# `phasync\Services\CurlMulti` for concurrent HTTP requests

[Back to README.md](../README.md)

The `CurlMulti` class provides asynchronous handling of cURL handles within the `phasync` framework. This allows you to perform multiple cURL requests concurrently using fibers.

## Class Overview

The `CurlMulti` class is designed to manage multiple cURL handles asynchronously. It uses the `curl_multi_*` functions to handle multiple cURL handles and `phasync` fibers to manage asynchronous execution.

## Usage

### Initialization

You don't need to explicitly initialize the `CurlMulti` class. It automatically initializes when you use it.

### Running Asynchronous cURL Requests

To run asynchronous cURL requests using `CurlMulti`, you need to use the `CurlMulti::await` method from within a `phasync` coroutine. Here's how you can do it:

1. **Create a cURL Handle**: Initialize a cURL handle with the desired URL and options.
2. **Use `CurlMulti::await`**: Pass the cURL handle to `CurlMulti::await` to run the request asynchronously.

### Example

```php
<?php

use phasync\Services\CurlMulti;

// Set the default timeout for phasync operations
phasync::setDefaultTimeout(10);

// Run the example within a phasync context
phasync::run(function () {
    // Create a coroutine for the first cURL request
    $a = phasync::go(function () {
        $ch = \curl_init('https://httpbin.org/get');
        \curl_setopt($ch, \CURLOPT_RETURNTRANSFER, true);

        return CurlMulti::await($ch);
    });

    // Create a coroutine for the second cURL request
    $b = phasync::go(function () {
        $ch = \curl_init('https://httpbin.org/get');
        \curl_setopt($ch, \CURLOPT_RETURNTRANSFER, true);

        return CurlMulti::await($ch);
    });

    // Await the results of the coroutines
    $resultA = phasync::await($a);
    $resultB = phasync::await($b);

    // Handle the results
    if ($resultA !== false) {
        echo "Request A succeeded: " . $resultA;
    } else {
        echo "Request A failed.";
    }

    if ($resultB !== false) {
        echo "Request B succeeded: " . $resultB;
    } else {
        echo "Request B failed.";
    }
});
```

### Error Handling

The `CurlMulti::await` method returns `false` if the cURL request fails. You can handle this in your coroutine by checking the return value.

### Advanced Usage

#### Handling Multiple Concurrent Requests

You can handle multiple concurrent cURL requests by creating multiple coroutines and awaiting their results.

```php
<?php

use phasync\Services\CurlMulti;

phasync::run(function () {
    $handles = [];

    // Create 10 concurrent cURL requests
    for ($i = 0; $i < 10; ++$i) {
        $handles[] = phasync::go(function () {
            $ch = \curl_init('https://httpbin.org/get');
            \curl_setopt($ch, \CURLOPT_RETURNTRANSFER, true);

            return CurlMulti::await($ch);
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

use phasync\Services\CurlMulti;

// Measure sequential execution time
$sequentialTime = phasync::run(function () {
    $start = microtime(true);

    for ($i = 0; $i < 5; ++$i) {
        $ch = \curl_init('https://httpbin.org/delay/1');
        \curl_setopt($ch, \CURLOPT_RETURNTRANSFER, true);
        CurlMulti::await($ch);
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

            return CurlMulti::await($ch);
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

### `CurlMulti::await(\CurlHandle $ch)`

- **Description**: Runs the given cURL handle asynchronously and waits for its completion.
- **Parameters**:
  - `\CurlHandle $ch`: The cURL handle to run.
- **Returns**: The result of the cURL request as a string on success, or `false` on failure.

## Conclusion

The `CurlMulti` class allows you to efficiently run multiple cURL requests concurrently using the `phasync` framework. By leveraging the power of fibers, you can improve the performance of your application when dealing with multiple HTTP requests.

For more detailed information and advanced usage, refer to the `phasync` documentation and examples.
