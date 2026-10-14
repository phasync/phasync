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

A failure nobody awaits cancels nothing. The other coroutines, and the function given to
`phasync::run()`, go on. Once every coroutine of the run has ended, `run()` throws:

- what its own function threw, if it threw;
- else the earliest failure nobody awaited, by the time it happened.

Awaiting the failed coroutine before `run()` ends counts as handling it. A coroutine that ends
with the cancellation it was given is no failure. Later failures nobody awaited are not thrown;
to see every one, give the frame a handler (below).

A nested `run()` throws into the coroutine that called it. A service's failures go to the
outermost `run()`.

To stop the rest when one coroutine fails, cancel the frame yourself:

```php
phasync::run(function () {
    $frame = phasync::frame();
    foreach ($urls as $url) {
        phasync::go(function () use ($frame, $url) {
            try {
                fetch($url);
            } catch (Throwable $e) {
                $frame->cancel();
                throw $e;
            }
        });
    }
});
```

## Handling failures for a whole application

Give the frame of the outermost `run()` a handler. It gets every failure nobody awaited, in the
order they became known, and `run()` no longer throws them:

```php
phasync::run(function () use ($main) {
    phasync::frame()->setExceptionHandler(function (Throwable $e) {
        error_log('A coroutine failed: ' . $e);
    });
    $main();
});
```

The handler runs in the event loop, outside any coroutine, so it must not wait. What it
throws, `run()` throws. phasync itself logs nothing: inside PHP-FPM, a `run()` without a handler
throws its failure into the application's own error handling.

## A Fiber object kept past its run

A failure is known as unhandled when its `Fiber` object is released, since anyone holding it may
still await it, and at the latest when its `run()` ends: a `Fiber` still referenced then does not
keep the failure from reaching the run. Such a coroutine can not be awaited afterwards
(`LogicException`).
