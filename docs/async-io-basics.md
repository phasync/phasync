# Asynchronous I/O in phasync

[Back to README.md](../README.md)

The `phasync` framework provides robust support for asynchronous I/O operations, enabling efficient handling of streams within coroutines and across the entire application. This document explains how asynchronous I/O is managed in `phasync`, detailing the use of `phasync::readable()`, `phasync::writable()`, and `phasync::stream()` functions.

## Functions Overview

### `phasync::readable(resource $stream): resource`

This function blocks the coroutine until the specified stream becomes readable. If called from outside a coroutine, unless the stream is already in non-blocking mode - the function has no effect.

**Example:**

```php
<?php

use phasync;

phasync::run(function () {
    $fp = fopen('php://temp', 'w+');
    fwrite($fp, 'test data');
    rewind($fp);

    $data = phasync::go(function () use ($fp) {
        $readableStream = phasync::readable($fp);
        return fread($readableStream, 1024);
    });

    $result = phasync::await($data);
    echo $result; // Outputs: test data

    fclose($fp);
});
```

### `phasync::writable(resource $stream): resource`

This function blocks the coroutine until the specified stream becomes writable. If called from outside a coroutine, unless the stream is already in non-blocking mode - the function has no effect.

**Example:**

```php
<?php

use phasync;

phasync::run(function () {
    $fp = fopen('php://temp', 'w+');

    phasync::go(function () use ($fp) {
        $writableStream = phasync::writable($fp);
        fwrite($writableStream, 'test data');
        fflush($writableStream);
    });

    $data = phasync::go(function () use ($fp) {
        rewind($fp);
        return fread($fp, 1024);
    });

    $result = phasync::await($data);
    echo $result; // Outputs: test data

    fclose($fp);
});
```

### `phasync::stream(resource $stream, int $mode = phasync::READABLE | phasync::WRITABLE, ?float $timeout = DEFAULT_TIMEOUT): int`

This function enables monitoring of streams for `phasync::READABLE`, `phasync::WRITABLE`, and `phasync::EXCEPT` states. Any number of coroutines can monitor the same resource, but only one coroutine can perform the actual read or write operation.

**Parameters:**
- `resource $stream`: The stream resource to monitor.
- `int $mode`: The mode to monitor (`phasync::READABLE`, `phasync::WRITABLE`, `phasync::EXCEPT`).
- `float $timeout`: Optional timeout for the operation.

**Example:**

```php
<?php

use phasync;

phasync::run(function () {
    $fp = fopen('php://temp', 'w+');
    fwrite($fp, 'test data');
    rewind($fp);

    $data = phasync::go(function () use ($fp) {
        phasync::stream($fp, phasync::READABLE);
        return fread($fp, 1024);
    });

    $result = phasync::await($data);
    echo $result; // Outputs: test data

    fclose($fp);
});
```

## Blocking State Side Effects

Using `phasync::readable()` or `phasync::writable()` within a coroutine will set the stream resource to non-blocking mode. The assumption is that the stream resource will continue to be monitored using the `phasync` API inside the coroutine for asynchronous I/O operations.

**Example:**

```php
<?php

use phasync;

phasync::run(function () {
    $fp = fopen('php://temp', 'w+');
    fwrite($fp, 'test data');
    rewind($fp);

    $data = phasync::go(function () use ($fp) {
        $readableStream = phasync::readable($fp);
        return fread($readableStream, 1024);
    });

    $result = phasync::await($data);
    echo $result; // Outputs: test data

    fclose($fp);
});
```

If these functions are invoked from outside a coroutine, the stream must explicitly have been set as non-blocking. Otherwise, the function will immediately return, and the subsequent `fread`/`fwrite` call will block the process.

**Example:**

```php
<?php

use phasync;

$fp = fopen('php://temp', 'w+');
fwrite($fp, 'test data');
rewind($fp);
stream_set_blocking($fp, false);

$readableStream = phasync::readable($fp);
$result = fread($readableStream, 1024);

echo $result; // Outputs: test data

fclose($fp);
```

## Integration in Existing Applications

The `phasync::readable()` and `phasync::writable()` functions are designed to be used directly in function calls like `fread(phasync::readable($fp), 4096)` and work regardless of whether they are used inside a coroutine or not. This flexibility enables developers to build support for concurrency in existing codebases with minimal impact on the overall operation of the application.

### Example: Integrating `phasync::readable()` in Existing Code

Consider an existing application that reads from a stream:

```php
<?php

$fp = fopen('php://temp', 'w+');
fwrite($fp, 'test data');
rewind($fp);

$result = fread($fp, 4096);
echo $result; // Outputs: test data

fclose($fp);
```

To integrate `phasync` for asynchronous reading, simply replace the `fread` call with `phasync::readable()`:

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

This change ensures that the reading operation is non-blocking and will benefit from coroutine concurrency if used inside a coroutine.

### Example: Integrating `phasync::writable()` in Existing Code

Similarly, consider an existing application that writes to a stream:

```php
<?php

$fp = fopen('php://temp', 'w+');
fwrite($fp, 'test data');
fflush($fp);

rewind($fp);
$result = fread($fp, 4096);
echo $result; // Outputs: test data

fclose($fp);
```

To integrate `phasync` for asynchronous writing, replace the `fwrite` call with `phasync::writable()`:

```php
<?php

use phasync;

$fp = fopen('php://temp', 'w+');

fwrite(phasync::writable($fp), 'test data');
fflush($fp);

rewind($fp);
$result = fread($fp, 4096);
echo $result; // Outputs: test data

fclose($fp);
```

This adjustment ensures that the writing operation is non-blocking and takes advantage of coroutine concurrency if used inside a coroutine.

### Benefits of Integration

Integrating `phasync` into existing applications provides several benefits:
- **Concurrency**: Enables coroutine concurrency, improving application responsiveness and performance.
- **Minimal Impact**: Requires minimal changes to the existing codebase, making it easy to adopt.
- **Flexibility**: Works both inside and outside of coroutines, ensuring compatibility with various parts of the application.

By leveraging `phasync::readable()` and `phasync::writable()` in existing applications, developers can enhance their codebases to support asynchronous I/O with minimal disruption.

## Conclusion

The `phasync` framework provides powerful asynchronous I/O capabilities through its `phasync::readable()`, `phasync::writable()`, and `phasync::stream()` functions. These functions allow you to efficiently manage I/O operations within coroutines, ensuring non-blocking behavior and robust handling of streams. By integrating these functions into existing applications, developers can build support for concurrency with minimal impact on the overall operation of the application. Understanding and utilizing these functions is key to building highly responsive and scalable applications.
