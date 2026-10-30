# Upgrading from phasync 1.x to 2.0.0

2.0.0 redesigns the coroutine model (contexts become `Scope` + `Frame`), removes several
`phasync` facade methods, and changes two defaults that will not raise a fatal error but can
change what your code does. Read "Timeouts" and "Cancellation" first.

## PHP and dependencies

- PHP requirement is unchanged (`^8.2`).
- New required dependency: `fiber-interop/scope`, which provides the global `\Scope` class
  (`^0.5` since 2.0.7: Scope RFC 0.10).
- `psr/log` is now required (`^1.0 || ^2.0 || ^3.0`); it was not required in 1.x.
- `psr/http-factory` is accepted from `^1.0` (1.x required `^1.1`).
- `phasync\Process\Process` runs on Windows (PHP 8.3 or later there), with sockets for the child's
  standard streams; in 1.x it threw `LogicException` on Windows.

## The optional extension

ext-phasync did not exist for 1.x; it is new in 2.0 and optional. With it loaded, blocking PHP
calls inside a coroutine (file I/O, `sleep()`, DNS, PDO, mysqli, curl, …) suspend the coroutine
instead of the process, and preemption happens automatically. Without it, phasync behaves the
same, just without that.

Three ways to load it:

- `vendor/bin/phasync app.php` — runs PHP with the extension loaded, same arguments as `php`.
- `phasync\ext\ensure_loaded()` — call once, early, in a CLI script; it may re-exec the process.
- `pie install phasync/ext-phasync` — installs it into `php.ini` for any SAPI (including FPM).

## The `phasync` facade: `run()` / `go()` / `service()`

Contexts are gone. Identity is now `\Scope` (from `fiber-interop/scope`); lifetime is
`phasync\Frame`, created by every `run()`.

| 1.x | 2.0 |
| --- | --- |
| `phasync::run(Closure $fn, ?array $args = [], ?ContextInterface $context = null)` | `phasync::run(callable $fn, mixed ...$args)` |
| `phasync::go(Closure $fn, array $args = [], int $concurrent = 1, ?ContextInterface $context = null, bool $run = false)` | `phasync::go(callable $fn, mixed ...$args)` |
| `phasync::service(Closure $coroutine): void` | `phasync::service(callable $fn, mixed ...$args): Fiber` |

**Silent break:** `$args` used to be one array of arguments. `phasync::go($fn, [$a, $b])` now
passes a single array as `$fn`'s first argument instead of `$a, $b`. Change it to
`phasync::go($fn, $a, $b)`. No error is raised either way — `$fn` just receives different
arguments.

`go()`'s `$concurrent` and `$run` are gone: start one coroutine per instance and `await()` each,
or call `phasync::run()` yourself instead of `$run: true`.

A per-request `$context` becomes a `\Scope`, with per-request state in a `WeakMap` keyed by it:

| 1.x | 2.0 |
| --- | --- |
| `phasync::go($fn, [], $context)` | `(new Scope())->run(fn () => phasync::go($fn))` |
| `phasync::withContext($fn, $context)` | `$scope->run(fn () => phasync::run($fn))` |
| `phasync::getContext()` | `Scope::getCurrent()` (root: `Scope::getRootScope()`) |
| `phasync::cancel($context)` | `$frame->cancel()` (get the frame with `phasync::frame()`) |
| `phasync::awaitContext($context)` | a nested `phasync::run()`, which waits for its coroutines |

Removed outright, no replacement: `phasync::fork()` (no redesign yet), `phasync::select()` (an
audit found real races in it; see `docs/SEMANTICS.md` §8), `ContextInterface`, `ContextTrait`,
`DefaultContext`, `ServiceContext`, `ContextUsedException`,
`phasync\Context\SwitchAwareInterface`, `ExceptionHandlerInterface`, `ContextFactoryInterface`.

**Silent behaviour change:** `run()` used to fail fast — the first unhandled coroutine failure
dropped every other coroutine at once. Now `run()` waits for every coroutine of its frame to end
before throwing: one failure is thrown as-is, several as a `phasync\AggregateException`
(`getExceptions(?string $class)`, `contains(string $class)`). Code that assumed the process ends
the instant something fails now keeps running (and doing I/O) until the rest finish.

## Timeouts

**The default timeout is gone, and this is the change most likely to break code silently.** 1.x
defaulted every wait (`await()`, `readable()`, `writable()`, `awaitFlag()`, …) to
`phasync::DEFAULT_TIMEOUT` (30 seconds) unless you passed your own. 2.0 has no global default:
an omitted timeout now means `\PHP_FLOAT_MAX` — wait forever. Code that relied on the implicit
30-second timeout to eventually throw `TimeoutException` will now hang instead. Pass a timeout
explicitly wherever one is needed, or use `phasync\Frame::setTimeout()` for a whole `run()`.

Removed: `phasync::setDefaultTimeout()`, `getDefaultTimeout()`, `DEFAULT_TIMEOUT`.

`phasync::idle()` also changed shape:

| 1.x | 2.0 |
| --- | --- |
| `idle(?float $timeout = null)`: wait until the loop is empty, or `$timeout` elapses; never throws | `idle(float $after = 0.0)`: wait until the loop has had nothing runnable for `$after` seconds; throws `CancelledException` if cancelled while waiting |

## Cancellation

`phasync::cancel()` changed signature and, for the common no-argument call, changed behaviour
without changing how you call it:

| 1.x | 2.0 |
| --- | --- |
| `cancel(Fiber $fiber, ?Throwable $exception = null)` | `cancel(Fiber $fiber, string\|Stringable\|CancelledException $message = 'Operation cancelled', int $code = 0, ?Throwable $previous = null)` |

**Silent behaviour change:** in 1.x, `cancel()` threw the given exception once, at the
coroutine's current suspension point; catching it, the coroutine carried on normally at its next
wait. In 2.0, cancellation is sticky: every wait after `cancel()` re-throws `CancelledException`
until the coroutine ends. `phasync::cancel($fiber)` with no custom exception still compiles and
runs in both versions, but a coroutine that used to catch the cancellation and keep working will
now be cancelled again at its very next wait.

Passing an arbitrary `Throwable` as `cancel()`'s second argument now throws
`InvalidArgumentException` (loud, not silent) telling you to use the new one-shot interrupt
instead, or pass it as `$previous`:

```php
// 1.x: phasync::cancel($fiber, $myException);
phasync::throw($fiber, $myException);   // one-shot, not sticky
```

New: `phasync::shielded(Closure $fn)` runs `$fn` out of every cancellation's reach (for cleanup
that must finish what it started). `Frame::requestCancellation()` /
`isCancellationRequested()` give cooperative cancellation without an exception.

## Streams and I/O

`phasync::stream()` (a mode bitmask of `READABLE|WRITABLE|EXCEPT`) and the `READABLE`,
`WRITABLE`, `EXCEPT` constants are gone; wait per direction instead.

| 1.x | 2.0 |
| --- | --- |
| `phasync::stream($resource, phasync::READABLE, $timeout)` | `phasync::readable($resource, $timeout)` |
| `phasync::stream($resource, phasync::WRITABLE, $timeout)` | `phasync::writable($resource, $timeout)` |
| `phasync::io($resource)` (implicit async wrapper) | call `readable()`/`writable()` explicitly before each operation |
| `phasync::streamPoll($resource)` | removed, no replacement (had no callers) |

Also removed: `phasync::setDriver()` and `DriverInterface`/`StreamSelectDriver` — `EventLoop` is
now the only loop; what varies is the poller (pluggable, used by ext-phasync).

**Silent behaviour change:** `readable()`/`writable()` no longer put the resource into
non-blocking mode themselves; the read or write after them must be on a resource you already set
non-blocking with `stream_set_blocking($resource, false)`, or it blocks the process as plain PHP
would.

## Channels and publishers

`phasync::channel()` and `phasync::publisher()` are unchanged in shape. Channels now accept and
return `mixed` instead of `Serializable|array|string|float|int|bool|null` — closures, objects
and resources round-trip, since a channel is in-process and nothing was ever serialized.

## Flags

`phasync::raiseFlag()` is unchanged. `awaitFlag()` gained a `$throw` parameter and now returns
`bool`:

```php
// 1.x: void, always throws TimeoutException on timeout
// 2.0:
phasync::awaitFlag($signal, $timeout, throw: false);   // returns false on timeout instead
```

The default (`throw: true`) behaves as 1.x did.

## Namespaced functions (`phasync\...`)

`src/functions.php` dropped the thin aliases for the facade and most of the stream-call
rewrites:

- `phasync\run()`, `phasync\go()`, `phasync\await()`, `phasync\idle()` — removed; call
  `phasync::run()`, `::go()`, `::await()`, `::idle()` directly.
- `phasync\fread()`, `fgets()`, `fgetc()`, `fgetcsv()`, `fputcsv()`, `fwrite()`, `ftruncate()`,
  `stream_get_contents()` — removed, along with the `io::` methods behind them. Use
  `phasync::readable($stream)` / `writable($stream)` before the native call, or let ext-phasync
  make the native call itself cooperative.
- `phasync\sleep()`, `file_get_contents()`, `file_put_contents()`, `flock()` stay.

## Services: CurlMulti and MySQLiPoll

Renamed, and awaited with `await()` instead of a bespoke method name:

| 1.x | 2.0 |
| --- | --- |
| `phasync\Services\CurlMulti::register($ch)` / `::await($ch)` | `phasync\Services\CurlAwaiter::register($ch)` / `::await($ch)` |
| `phasync\Services\MySQLiPoll::poll($connection, ?int $timeout)` | `phasync\Services\MySQLiAwaiter::await($connection, ?float $timeout)` |

The old classes still exist as `@deprecated` wrappers calling the new ones; they go in 3.0.

## Util namespace moves

| 1.x | 2.0 |
| --- | --- |
| `phasync\LockInterface` | `phasync\Util\LockInterface` |
| `phasync\QueueInterface` | `phasync\Util\QueueInterface` |
| `phasync\LockTrait` | `phasync\Util\LockTrait` |
| `phasync\Process\Process` | `phasync\Util\Process` |
| `phasync\Process\ProcessInterface` | `phasync\Util\ProcessInterface` |
| `phasync\Process\PosixProcessRunner` | `phasync\Util\ProcessRunner` (renamed, not just moved) |

The 1.1.0 back-compat shims for these (`phasync\Interfaces\*`, `phasync\Util\LockTrait` pointing
elsewhere, `phasync\Util\Collections\Queue`) are gone; they are not kept a second cycle.

`phasync::waitGroup()` (deprecated since 1.1.0-rc6) is removed: use `new
phasync\Util\WaitGroup()` directly, which now implements `SelectableInterface` so
`phasync::await($waitGroup)` works on it too.

## PSR-7 messages

Message bodies are explicit streams, not strings or mixed values:

| 1.x | 2.0 |
| --- | --- |
| `new Request($method, $uri, $headers, $body)` | `new Request($method, $requestTarget, $body, $headers)` — note the reordering, and `$body` is now `?StreamInterface` |
| `new Response($code, $reasonPhrase)` | `new Response($statusCode, $headers, $body, $protocolVersion, $reasonPhrase)` |
| a string/resource body | `new StringStream($string)`, `new ResourceStream($resource)`, or `null` for empty |

**Silent break:** the parameter order of `Request`'s constructor changed (`$uri` is now
`$requestTarget`, a plain string, and moved after `$method`; `$body` and `$headers` swapped
positions). Code calling it positionally with the 1.x order passes its old `$headers` array
where `$body` (a `?StreamInterface`) is now expected, which throws `TypeError` rather than
silently misbehaving. Build requests from a URL with `Request::create($method, $uri)` instead,
which is unaffected.

`ResourceStream` now throws `InvalidArgumentException` for a non-seekable resource still in
blocking mode — call `stream_set_blocking($resource, false)` first.

Removed: `phasync\Psr\StreamFactory` (construct the stream explicitly),
`phasync\Psr\ReadChannelStream` (wrap a channel in a stream of your own),
`phasync\Psr\FormDataStream`, `TempFileStream`, `MultipartStreamInterface` (removed in 1.1.0
already, unreferenced).

## Exceptions

- `phasync\AggregateException` has a new shape (see "run()" above):
  `getExceptions(?string $class = null)`, `contains(string $class)`; `getPrevious()` is the
  first failure.
- New: `phasync\DeadlockException` — thrown by the outermost `run()` when coroutines wait and
  nothing can ever wake them, instead of waiting forever.
- New: `phasync\ShutdownException` (a `CancelledException`), thrown by the new
  `phasync::shutdown()`.

## Removed, no direct replacement

- `phasync::preempt()`, `setPreemptInterval()`, `DEFAULT_PREEMPT_INTERVAL` — manual preemption in
  busy loops is gone; with ext-phasync, preemption is automatic instead.
- `phasync::setPromiseHandler()` / `getPromiseHandler()` — promise handling moved to the internal
  `phasync\Internal\PromiseHandler`; `await()` of a promise-like object still works the same.
- `phasync::onEnter()` / `onExit()`, `defer()`, `enqueue()`, `enqueueWithException()`,
  `logUnhandledException()` — all had no real callers. For per-scope state, see
  `phasync::onScopeChange()` (new in 2.0; not a 1:1 replacement — it fires on every scope switch,
  not once per `run()`).
