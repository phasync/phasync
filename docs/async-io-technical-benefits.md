# Technical Advantages of Asynchronous I/O with phasync

[Back to README.md](../README.md) - [Back to benefits](/benefits.md)


## Introduction

Asynchronous I/O and coroutines offer significant performance benefits over traditional synchronous I/O and multithreading. This document delves into the technical reasons why using `phasync` for asynchronous I/O can lead to more efficient CPU usage and overall better performance in server applications, especially those running on PHP-FPM or similar runtimes.

## Context Switching: Kernel vs. User Space

### Kernel-Level Context Switching

In traditional multitasking operating systems, processes and threads are the primary units of execution. When a process or thread performs an I/O operation, it often needs to wait for the operation to complete. During this wait, the operating system may switch to another process or thread to utilize the CPU effectively. This switching involves:

1. Saving the state of the current process or thread.
2. Loading the state of the next process or thread.
3. Switching the CPU's execution context, including memory mappings, registers, and other hardware-specific settings.

This process is known as kernel-level context switching. While effective in utilizing CPU resources, kernel-level context switching is relatively expensive due to the overhead involved in saving and loading states and interacting with the hardware.

### User-Level Context Switching with Green Threads

Green threads, also known as user-level threads or fibers, are managed by the application rather than the operating system. In the context of `phasync`, coroutines act as green threads, allowing the application to handle context switching within the user space without involving the kernel.

User-level context switching involves:

1. Saving the state of the current coroutine.
2. Loading the state of the next coroutine.
3. Switching the execution context within the application.

This process is significantly less expensive than kernel-level context switching because it avoids the overhead associated with interacting with the operating system and hardware. Instead, it relies on lightweight operations within the application's memory space.

## Benefits of Asynchronous I/O with phasync

### Improved Resource Utilization

By using `phasync` for asynchronous I/O, the application can continue executing other tasks while waiting for I/O operations to complete. This approach avoids blocking the entire process or thread, leading to more efficient CPU utilization. Coroutines enable the application to perform context switching within user space, minimizing the overhead and allowing more work to be done in less time.

**Example: Performing Multiple I/O Operations Concurrently**

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

### Reduced Latency

Asynchronous I/O allows the application to start processing data as soon as it becomes available, rather than waiting for all I/O operations to complete. This behavior reduces latency and improves the responsiveness of the application, particularly in scenarios involving multiple I/O-bound tasks.

### Lower CPU Overhead

Avoiding kernel-level context switching reduces the CPU overhead associated with saving and restoring process or thread states. This efficiency allows the CPU to spend more time executing application code rather than managing context switches.

### Simplified Concurrency Model

Using `phasync` coroutines simplifies the concurrency model compared to traditional multithreading. Coroutines enable a straightforward, linear flow of asynchronous operations, making the code easier to read, maintain, and debug. Error handling is also more intuitive, as exceptions propagate naturally through the coroutine stack.

**Example: Natural Exception Handling with Coroutines**

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

## Comparison: Coroutines vs. Promises

### Coroutines

- **Context Switching**: Managed within the user space, avoiding kernel-level context switching.
- **Error Handling**: Uses try-catch blocks, making error handling straightforward and natural.
- **Code Readability**: Maintains a linear flow, making asynchronous code easier to read and understand.
- **Performance**: Lower CPU overhead due to lightweight context switching.

### Promises

- **Context Switching**: Often involves more complex state management and can lead to higher CPU overhead.
- **Error Handling**: Requires chaining `.catch()` methods, which can be less intuitive than try-catch blocks.
- **Code Readability**: Can lead to "callback hell" or complex chaining, making the code harder to read and maintain.
- **Performance**: Potentially higher CPU overhead due to more complex state management.

## Incremental Adoption in Existing Applications

One of the key advantages of `phasync` is its ability to integrate incrementally into existing codebases. By replacing blocking I/O calls with `phasync` functions, developers can enhance concurrency and performance step by step, without a complete overhaul of the application.

**Example: Incremental Integration**

```php
<?php

use phasync;

$fp = fopen('php://temp', 'w+');
fwrite($fp, 'test data');
rewind($fp);

// Replace blocking fread with phasync::readable()
$result = fread(phasync::readable($fp), 4096);
echo $result; // Outputs: test data

fclose($fp);
```

This incremental approach allows developers to gradually introduce asynchronous I/O, leveraging the benefits of coroutines while minimizing disruption to the existing codebase.

## Conclusion

Leveraging asynchronous I/O via coroutines with `phasync` offers significant technical advantages, including improved resource utilization, reduced latency, lower CPU overhead, and a simplified concurrency model. By avoiding the costly overhead of kernel-level context switching, `phasync` enables more efficient execution of I/O-bound tasks, leading to better overall performance.

Developers can incrementally integrate `phasync` into existing applications, enhancing concurrency and performance with minimal changes. By understanding and utilizing these technical benefits, developers can build highly responsive and scalable applications that make the most of available CPU resources.
