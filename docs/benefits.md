# Why Leverage Asynchronous I/O via Coroutines?

[Back to README.md](../README.md)

In modern web applications, efficient resource management and performance are critical. Leveraging asynchronous I/O via coroutines offers significant benefits, particularly for applications running on PHP-FPM or similar runtimes. This chapter explores the advantages of using asynchronous I/O with `phasync`, demonstrating how it enhances resource efficiency, performance, and ease of use in existing applications.

> [For a more technical discussion](async-io-technical-benefits.md)

## Resource Efficiency and Performance

### Concurrency with Coroutines

Traditional synchronous I/O operations block the execution of your application, leading to inefficient resource usage. When your application waits for I/O operations to complete, such as reading from a file or making an HTTP request, valuable CPU cycles are wasted.

With `phasync`, you can use coroutines to perform asynchronous I/O operations. Coroutines allow your application to continue executing other tasks while waiting for I/O operations to complete. This non-blocking behavior significantly improves resource efficiency and overall performance.

### Immediate Processing with Asynchronous I/O

Consider a scenario where you need to make multiple HTTP requests concurrently. Using traditional methods like `curl_multi_exec`, you can initiate multiple requests simultaneously, but you often need to wait for the slowest request to complete before processing any responses.

With `phasync`, you can begin processing the API responses immediately after the first response is received, without waiting for all requests to complete. This capability allows you to handle data as it becomes available, reducing latency and improving the responsiveness of your application.

**Example:**

```php
<?php

use phasync;
use phasync\Services\CurlMulti;

phasync::run(function () {
    $urls = ['https://api.example.com/data1', 'https://api.example.com/data2', 'https://api.example.com/data3'];

    foreach ($urls as $url) {
        phasync::go(function () use ($url) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $response = CurlMulti::await($ch);

            // Process the response immediately
            echo "Received response from $url: $response\n";
        });
    }
});
```

## Ease of Use and Error Handling

### Natural Exception Handling

One of the main challenges with promise-based systems is managing exceptions and errors, which often involve complex callback structures. In contrast, `phasync` coroutines handle exceptions more naturally. When an exception is thrown within a coroutine, it propagates up the coroutine stack, allowing you to handle errors using familiar try-catch blocks.

**Example:**

```php
<?php

use phasync;

phasync::run(function () {
    $coroutine = phasync::go(function () {
        throw new \Exception("Error inside coroutine");
    });

    try {
        phasync::await($coroutine);
    } catch (\Exception $e) {
        echo "Caught exception: " . $e->getMessage();
    }
});
```

### Incremental Integration with Existing Codebases

Unlike many frameworks that abstract away PHP streams, `phasync` is designed to incrementally integrate into existing applications. This approach allows you to apply asynchronous I/O capabilities to your application gradually, providing immediate benefits without requiring a complete rewrite of your codebase.

You can start by replacing blocking I/O calls with `phasync` functions, enhancing concurrency and performance step by step. Over time, the synergies of using `phasync` will grow, leading to even greater improvements in efficiency and responsiveness.

### Minimal Changes to Existing Code

Integrating `phasync` into your existing codebase is straightforward. Functions like `phasync::readable()` and `phasync::writable()` can be used directly in function calls, such as `fread(phasync::readable($fp), 4096)`, making it easy to introduce asynchronous I/O with minimal changes.

**Example:**

```php
<?php

use phasync;

$fp = fopen('php://temp', 'w+');
fwrite($fp, 'test data');
rewind($fp);

$result = fread(phasync::readable($fp), 4096);
echo $result; // Outputs: test data

fclose($fp);
```

## Benefits of Using Coroutines with phasync

1. **Improved Resource Efficiency**: By allowing other tasks to execute while waiting for I/O operations, coroutines make better use of available CPU resources.
2. **Reduced Latency**: Start processing data as soon as it becomes available, rather than waiting for all operations to complete.
3. **Natural Error Handling**: Handle exceptions in a straightforward manner using try-catch blocks, without the complexity of callbacks.
4. **Incremental Adoption**: Integrate `phasync` into existing applications gradually, providing immediate performance improvements with minimal disruption.
5. **Enhanced Concurrency**: Perform multiple I/O operations concurrently, improving the responsiveness and scalability of your application.

## Conclusion

Leveraging asynchronous I/O via coroutines in your applications offers numerous advantages in terms of resource efficiency, performance, and ease of use. The `phasync` framework provides powerful tools for integrating asynchronous I/O into existing codebases, enabling developers to build highly responsive and scalable applications with minimal changes. By adopting `phasync`, you can enhance the concurrency of your application, handle errors more naturally, and incrementally improve performance over time.
