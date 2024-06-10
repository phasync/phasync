# Exception Handling in phasync

[Back to README.md](../README.md)

The `phasync` framework provides robust exception handling mechanisms to manage errors that occur within coroutines. This document explains how exceptions are propagated and handled within `phasync`, ensuring that developers can effectively manage and respond to errors in their asynchronous workflows.

## Basic Principles

1. **Exception Association with Coroutines**:
   - Whenever an exception is thrown from inside a coroutine, that exception is associated with the coroutine (Fiber instance).

2. **Exception Propagation**:
   - If a coroutine is explicitly awaited via `phasync::await()`, then `phasync::await()` will throw the exception.
   - If `phasync::await()` is never called for the coroutine, the exception will be propagated to the parent coroutine at the next context switch opportunity.
   - If no context switch opportunity arrives, the exception will propagate up to the parent of the parent coroutine, and so on.

3. **Exception Handling in phasync::run()**:
   - Coroutines created with `phasync::run()` automatically have an associated `DefaultContext` object.
   - `phasync::run()` will always throw exceptions that are not handled within the coroutines it manages.

4. **Multiple Exceptions**:
   - Due to concurrency, multiple exceptions can be thrown inside a coroutine.
   - If the top-level coroutine throws an exception on its own, any exception thrown from a child coroutine may be shadowed and not surface.

## Detailed Explanation

### Associating Exceptions with Coroutines

When an exception is thrown within a coroutine, `phasync` associates this exception with the specific coroutine (Fiber instance). This allows `phasync` to manage and track exceptions on a per-coroutine basis, ensuring that exceptions are properly propagated and handled.

### Explicitly Awaiting Coroutines

If a coroutine is explicitly awaited using `phasync::await()`, any exception thrown within that coroutine will be re-thrown by `phasync::await()`. This behavior allows developers to handle exceptions immediately when awaiting the result of a coroutine.

Example:

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

### Propagation to Parent Coroutines

If a coroutine is not explicitly awaited, any exception it throws will be propagated to the parent coroutine at the next context switch opportunity. If the parent coroutine does not handle the exception, it will continue to propagate up the chain of parent coroutines.

Example:

```php
<?php

use phasync;

phasync::run(function () {
    $coroutine = phasync::go(function () {
        throw new \Exception("Error inside coroutine");
    });

    // Not awaiting the coroutine, exception will propagate to this parent coroutine
});
```

### Context Interface

Coroutines can be created with an associated `ContextInterface` object, which provides additional control over exception handling and propagation. By default, coroutines created with `phasync::run()` or `phasync::go()` use a `DefaultContext` object.

### Handling Multiple Exceptions

Due to the concurrent nature of coroutines, multiple exceptions may be thrown within a single coroutine. If the top-level coroutine throws an exception, it may shadow exceptions thrown by child coroutines, preventing them from surfacing.

### Example: Multiple Exceptions

Consider the following example where multiple exceptions could occur:

```php
<?php

use phasync;

phasync::run(function () {
    $coroutine1 = phasync::go(function () {
        throw new \Exception("Error in coroutine 1");
    });

    $coroutine2 = phasync::go(function () {
        throw new \Exception("Error in coroutine 2");
    });

    try {
        phasync::await($coroutine1);
        phasync::await($coroutine2);
    } catch (\Exception $e) {
        echo "Caught exception: " . $e->getMessage();
    }
});
```

In this example, the first exception that occurs will be caught, and subsequent exceptions may be shadowed.

## Demonstrating Exceptions in Coroutines

### Example: Exceptions Not Caught in Immediate Context

Consider the following example, where a coroutine throws an exception, but the exception is not caught in the immediate context:

```php
<?php

use phasync;

phasync::run(function () {
    try {
        phasync::go(function () {
            throw new \Exception("Error inside coroutine");
        });
    } catch (\Exception $e) {
        // This block will never be executed
        echo "Caught exception: " . $e->getMessage();
    }

    // Without calling phasync::await() on the coroutine,
    // the exception will propagate to the parent coroutine at the next context switch
});
```

In this example, the exception thrown inside the coroutine is associated with that coroutine. The `try-catch` block in the parent coroutine does not catch this exception immediately because `phasync::await()` is not called. The exception will propagate to the parent coroutine at the next context switch opportunity.

## Best Practices

1. **Explicitly Await Coroutines**: Always explicitly await coroutines using `phasync::await()` to ensure exceptions are properly propagated and handled.
2. **Use Try-Catch Blocks**: Wrap coroutine execution in try-catch blocks to handle exceptions immediately and prevent them from propagating unexpectedly.
3. **Monitor Context Switches**: Be mindful of context switches and the opportunities they provide for exception propagation.

By following these best practices, developers can effectively manage exceptions within the `phasync` framework, ensuring robust and reliable asynchronous applications.

## Conclusion

The `phasync` framework provides powerful mechanisms for handling exceptions in asynchronous workflows. By associating exceptions with coroutines, propagating exceptions through context switches, and providing explicit awaiting mechanisms, `phasync` ensures that developers can manage errors effectively. Understanding and utilizing these mechanisms is key to building resilient and robust asynchronous applications.
