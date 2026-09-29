# Exception handling

A coroutine's exception belongs to the coroutine. Whoever awaits it gets it:

```php
$fiber = phasync::go(fn () => throw new RuntimeException('failed'));
try {
    phasync::await($fiber);
} catch (RuntimeException $e) {
    // handled
}
```

## A failure nobody awaits

When a coroutine fails and nobody awaits it, the failure goes outward through the contexts:
the coroutine's own, then the one that context was entered from (`withContext()`, or `go()`
with a context of its own), and so on.

- The first context implementing `phasync\Context\ExceptionHandlerInterface` takes it:
  `handleException()` is called from the event loop. Only the failed coroutine ends; the rest
  run on.
- With no handler on the way, the failure fails the nearest `phasync::run()`. The run drops
  all its coroutines at once, and those of the contexts nested in it, and throws the failure.
  Dropped coroutines are never resumed: PHP destroys them, running their `finally` blocks,
  which can no longer suspend. Nothing is left running.
- Several failures in one run are thrown as a `phasync\AggregateException`, the first as its
  previous exception, all of them in `getExceptions()`.

A nested `run()` fails alone and throws into the coroutine that called it. A service's
failures go to the outermost `run()`.

## Handling failures for a whole application

Give the outermost `run()` a context with a handler:

```php
phasync::run($main, context: new class implements phasync\Context\ExceptionHandlerInterface {
    public function handleException(Throwable $e): void
    {
        error_log('A coroutine failed: ' . $e);
    }
});
```

phasync itself logs nothing: inside PHP-FPM, a `run()` without a handler throws its failures
into the application's own error handling.

## A Fiber object kept past its run

A failure is known as unhandled only when its `Fiber` object is released, since anyone
holding it may still await it. When that happens after its `run()` has ended, the failure is
thrown where the last reference goes.
