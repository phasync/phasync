# Changelog

Earlier releases are listed on the GitHub releases page.

## Unreleased

### Added

- `phasync::signal($signals, $timeout)`: a coroutine waits for a POSIX signal (or one of several)
  and gets the one that came. Any number of coroutines may wait for the same signal; each is
  woken, between coroutines, by the event loop (the handler only records the signal), so the
  waiter may await and do I/O like any coroutine. Works with and without `pcntl_async_signals()`.
- `phasync::onSignal($signo, $handler)`: code that runs the moment a signal arrives, inside its
  handler (with async signals, even inside a coroutine that never yields), such as logging where
  a stuck process is. phasync takes each signal it handles over with one handler of its own, which
  still calls the `pcntl_signal()` handler installed before, and puts that one back when the
  outermost `phasync::run()` returns. Each signal has its own flag, so a waiter is only woken by
  the signals it waits for.
- `phasync::shutdown($window, $exception)` and `ShutdownException` (a `CancelledException`): every
  coroutine of the outermost run but the caller gets the exception at its wait and has up to
  `$window` seconds to clean up (`phasync::finally()` cleanup included); it returns how many are
  still running. `phasync::cancel()` also takes a `CancelledException` of the caller's own.

### Changed

- The event loop collects cyclic garbage only when it has a spare moment: nothing runnable
  and a `poll(0)` finds no ready I/O, at most once per `GC_MIN_INTERVAL` (0.5 s). A loop kept
  busy by always-runnable coroutines never has one, so as a safety net it collects once
  `GC_MAX_INTERVAL` (0.5 s) has passed while there is anything to collect, and not before:
  until then its garbage stays in memory, since it has no time to spare for collecting it.
  The roots threshold (and its adaptive doubling) is gone. Both intervals can be set with the
  `PHASYNC_GC_MIN_INTERVAL` and `PHASYNC_GC_MAX_INTERVAL` constants. (#87: collecting before
  every wait cost a loaded WordPress worker 17% more CPU per page.)
- The phasync extension ships inside the release packages of phasync under `ext/` (closed source
  binaries with their own licence in `ext/LICENSE`; phasync itself stays MIT). It is no longer a
  separate `composer require`, and the `suggest` entry for `phasync/phasync-ext` is gone. The
  loader (`phasync\ext\ensure_loaded()`) is now part of phasync and finds binaries at
  `ext/phasync-<php>-nts-<arch>-<libc>.so`, or at `PHASYNC_EXT_SO`.
- Without a licence file the extension lets at most 4 blocking calls overlap per worker; a licence
  file (ini `phasync.license`, `PHASYNC_LICENSE_FILE`, or `./phasync.license`) lifts that.

### Added

- `phasync\ext_enabled()` reports whether the root `composer.json` has
  `"extra": {"phasync": {"ext": true}}`. It loads nothing; `phasync\try_enable_ext()` stays the
  explicit call.

## 2.0.0-beta6 (2026-10-04)

### Fixed

- `withAddedHeader()` of the PSR-7 messages threw a `TypeError` when given a list of values for a
  header the message did not have (#81).

## 2.0.0-beta5 (2026-10-04)

### Added

- `phasync\Util\Pool` can hold heavy instances (a booted application): with `window` (seconds), idle
  instances are dropped while more exist than the most that were lent at once within the window, so
  a burst keeps its instances for `window` seconds and memory goes back after that. This is checked
  when `borrow()` or `release()` is called, not by a timer, so memory is returned the next time the
  pool is used. `dispose` is called with each instance the pool lets go of (dropped as idle, or
  given back with `discard()`).
- Context-local state: `phasync::$contextState` is bound, by reference, to the array of the running
  coroutine's context, so contexts do not see each other's writes. `phasync::enableContextState()`
  or `phasync::adoptContextState(array &$state)` (use a caller's array as the context's state) turn
  it on; `phasync::$contextStateDefaults` is what a new context starts with. Until then a switch
  checks one flag.

### Fixed

- A service started after earlier services had ended could not enter a context: `withContext()`
  inside it threw `Object stdClass#N not contained in WeakMap`. The service context lost its root
  context when its last service ended; it now keeps it (#78).

### Changed

- `StreamSelectPoller` always uses PHP's `stream_select()`; it no longer looks for
  `phasync\ext\stream_select()`, which phasync-ext has removed. With the extension loaded the loop
  uses the extension's epoll `Poller` as before, so nothing changes there; without it, descriptors
  numbered `FD_SETSIZE` (1024) or higher still fail as `IOException`.

## 2.0.0-beta4 (2026-10-02)

### Added

- `phasync\Util\Event`: a small event object with `listen()`, `once()`, `off()`, `trigger()` and
  `hasListeners()`.

## 2.0.0-beta3 (2026-10-01)

### Changed

- **Behaviour change:** `phasync::withContext()` waits like `phasync::run()` does, without the
  coroutine: it returns once the coroutines the closure started in the context, and in contexts
  nested in it, have ended. When the closure throws they are cancelled first; when the caller is
  cancelled while waiting, so are they. It used to return at once, leaving them running, so a
  caller could not tell when a request's work was done. `finally()` callbacks registered inside it
  still run when the closure returns, before the wait. Code that relied on background coroutines
  outliving `withContext()` should use `phasync::service()` or a context of its own.

## 2.0.0-beta2 (2026-10-01)

### Added

- `phasync::throw(Fiber $fiber, Throwable $exception)`: interrupts a waiting coroutine with an
  exception, once. It is not sticky: the coroutine can catch it and go on.
- `phasync::awaitContext(object $context, float $timeout)`: waits until every coroutine of a
  context has ended, including nested contexts and coroutines started while waiting.
- `@internal` on `EventLoop` (except `getSlot()`, `park()`, `unpark()`), `Internal\*` and
  `StreamSelectPoller`: they may change in any release. `ApiSurfaceTest` pins the signatures of
  the `phasync` class.

### Changed

- `phasync::cancel()` is always sticky, like tearing down a context: it takes a message (`string|Stringable`), a
  code and a previous exception, and the coroutine gets a `CancelledException` that stays until
  it ends. A custom exception passed as the message is refused; use `throw()`, or pass it as `$previous`.

## 2.0.0-beta1 (2026-10-01)

### Added

- `phasync\Util\ConsoleLogger`: a PSR-3 logger writing `Console::log()` lines, with a minimum
  level. It satisfies psr/log 1, 2 and 3, so it pins no version on its dependants.
- `phasync\Context\ContextFactoryInterface`: `phasync::withContext()` accepts a factory instead
  of a context, and creates the context only when the closure first asks for it
  (`getContext()`, `getRootContext()`, `go()`, `finally()`, a nested `run()` or `withContext()`).
  A request handler that never does costs no context.

### Removed

The `phasync` class keeps what coroutines are made of; what nothing used, or what has a better
home, is gone before the beta:

- `phasync::enqueue()`, `enqueueWithException()` (internal, no callers), `defer()`, `idle()`,
  `onEnter()` and `onExit()`.
- `phasync::io()` and the `AsyncStream` stream wrapper behind it: `phasync::readable()` and
  `writable()` do the job, and phasync-ext makes the blocking calls themselves cooperative.
- `go()`'s `$concurrent` and `$run` parameters: start a coroutine per instance and `await()`
  them. `go()` is now `go(Closure $fn, array $args = [], ?object $context = null)`.
- `phasync\Util\FastCGI\Record` (the FastCGI code lives in swerve) and the unfinished
  `phasync\Wrappers\PDO\MySQL` / `MySQLStatement`, a start on a PDO-compatible mysqli wrapper.
  `phasync\Services\MySQLiPoll` stays: it does for mysqli what `CurlMulti` does for curl, for
  users without phasync-ext.
- `phasync::setPromiseHandler()` and `getPromiseHandler()` moved to the internal
  `phasync\Internal\PromiseHandler`; `await()` of a promise-like object works as before.

### Changed

- Moved to `phasync\Internal`: `Debug`, `DeadmanSwitch`, `DeadmanSwitchTrait`, `DeadmanException`.
  Moved to `phasync\Util`: `Process`, `ProcessInterface` and `ProcessRunner` (from `phasync\Process`).
- A coroutine alone in its context is tracked without a set of its own, which makes
  `withContext()` cheaper (about 45 ns).

### Fixed

- A coroutine that registered a `phasync::finally()` callback outside `withContext()` no longer
  loses its failure: `run()` used to return normally instead of throwing it (#72). The event
  loop now keeps every `finally()` callback and runs them when the coroutine ends, instead of
  `phasync::finally()` keeping its own queue and a helper coroutine that swallowed the failure.
  All of a coroutine's callbacks run even when one throws.
- A `phasync::run()` that fails to start (for example with `ContextUsedException`) no longer leaves
  its context registered in the event loop as the running root (#73). `run()` now ends its run
  in a `finally` block, so every way out of it does. `phasync::isRunning()` and `getLoop()` ask
  the event loop (`EventLoop::isRunning()`) instead of a depth counter in the `phasync` class.

## 2.0.0-alpha27 (2026-09-30)

### Added

- `Console::log($level, $message, $context, $source)`: one log line format everywhere (time,
  source, the level from warning up, the message with its `{placeholders}` filled), written
  without parsing any markup, with control characters escaped.

## 2.0.0-alpha26 (2026-09-30)

### Added

- `phasync\Util\Console`: terminal output from markup, styled on a terminal and plain when piped,
  laid out the same in both. `<!red bold>…<!>` styles a span; `<!pad 20>`, `<!lpad 8>` and
  `<!center 40>` (or `50%` of the terminal) pad it to a width, cutting longer content with "…"
  (or without it given `clip`). Widths count display columns: wide characters and emoji take 2,
  combining marks 0. `NO_COLOR` and `FORCE_COLOR` are honoured.

## 2.0.0-alpha25 (2026-09-30)

### Changed

- Cancellation is sticky (CAN-4): once a coroutine or context is cancelled, every wait in it
  throws the cancellation again until the coroutine ends or leaves the context, `catch` and
  `finally` blocks included. `phasync::finally()` callbacks are shielded, so cleanup that must
  do I/O completes. Timeouts stay one-shot. A coroutine cancelling itself gets the exception at
  once, like a `throw`; a preempted coroutine meets it at its next wait (CAN-2).
- A failed `run()` cancels its scope instead of dropping its coroutines (SCO-3): they unwind
  with a `CancelledException` whose previous exception is the failure, and `run()` throws once
  they have. The main coroutine failing does the same. Their cancellations are no failures;
  anything else they throw is bundled (SCO-4).
- A coroutine that ends with the cancellation it was given did not fail: `run()` no longer
  throws it (CAN-9).
- An `ExceptionHandlerInterface` handler that throws passes the exception on outward, as if it
  had no handler; one that returns has handled it (SCO-3).

- A `run()` owns its coroutines: a failure nobody took fails it when it ends, also while the
  failed coroutine's `Fiber` is still referenced (by the application, or by Xdebug's develop mode,
  which kept it, so `run()` returned normally and the exception surfaced later). Such a
  coroutine can't be awaited after its `run()` (`LogicException`) (ERR-3, #69).

### Removed

- `phasync::fork()`, until it has a design of its own. A forked child could run the parent's
  coroutines and code: a failure pending in the parent was thrown in the child, which then
  returned into the caller instead of ending (#69).

### Added

- `Process::run()` works on Windows again (issue #45), rebuilt as one implementation shared
  with POSIX instead of a separate runner: STDIN/STDOUT/STDERR are `proc_open()` `['socket']`
  descriptors on every platform, not `['pipe', ...]` -- a socket can be made non-blocking and
  polled on Windows, a pipe can't. Windows command resolution follows `PATHEXT`; `proc_open()`'s
  `bypass_shell` keeps "no shell involved" true there too, except for `.bat`/`.cmd` scripts,
  which Windows itself runs through `cmd.exe`. On Windows it needs PHP 8.3 or later, and throws
  `LogicException` before that. CI gained a
  windows-latest job running the process tests.

### Changed

- `phasync\Process\PosixProcessRunner` is renamed `phasync\Process\ProcessRunner` (no
  deprecated alias: it is `final`, returned only via `ProcessInterface`, and nothing in this
  repo or in swerve referenced the concrete class name). `Process::run()` no longer throws on
  Windows.
- `ProcessRunner`'s POSIX signal helpers (`sigkill()`, `sigint()`, `sigstop()`, `sigcont()`,
  `sighup()`) use plain integers instead of the pcntl `\SIG*` constants, which are undefined
  without the pcntl extension (pcntl never builds on Windows). `sendSignal()`'s numeric default
  was already a plain `15`. On Windows, every signal value forcibly ends the process
  (`proc_terminate()` has no way to deliver a specific one there): `sigstop()`/`sigcont()`
  cannot pause or resume a process, and `sigterm()`/`sigint()`/`sighup()` hard-kill instead of
  asking for a graceful shutdown.

## 2.0.0-alpha24 (2026-09-29)

### Fixed

- Preemption acts only on a running coroutine: a checkpoint inside a `Fiber` that a coroutine
  runs itself suspended that Fiber, and the loop crashed.

## 2.0.0-alpha23 (2026-09-29)

### Added

- Preemption with phasync-ext (0.5.0-alpha20 or later): a coroutine that runs 1 ms (`EventLoop::PREEMPT_INTERVAL`) in a
  PHP loop yields to other requests between two iterations. Its root context stays frozen until
  it resumes, so inside one request nothing changes (SCH-5); across requests, loop-free code is
  atomic. Never in C-called PHP code, `#[\phasync\Uninterruptible]` code, or phasync's and
  swerve's own code (SCH-6). Without the extension there is no preemption.

## 2.0.0-alpha22 (2026-09-29)

### Added

- `phasync::getRootContext()`: the root of the running coroutine's context. A `run()`'s context
  is its own root, and so is a context entered from it, such as a server's request context;
  contexts entered from that one share its root. For a root, `getRootContext() === getContext()`.

### Fixed

- A zero or negative timeout (a deadline already past) throws `TimeoutException` at once, without
  waiting (it waited for the next timeout check before).

## 2.0.0-alpha21 (2026-09-29)

### Changed

- Timeouts are kept in 10 ms slots instead of being found by a scan of every waiting coroutine
  every 0.1 s. Checking costs one integer comparison per tick and looks only at the coroutines
  whose timeout expired: with 50 000 coroutines waiting, a scan took 6.4 ms ten times a second.
  Timeouts fire no earlier than their deadline and at most about 10 ms after it, also in an idle
  loop, which slept up to 0.5 s past them before (D11).

### Added

- `benchmarks/ab.php`: a half-minute A/B benchmark of the working tree against a git ref.

## 2.0.0-alpha20 (2026-09-29)

### Removed

- `phasync::preempt()`, `phasync::setPreemptInterval()` and `DEFAULT_PREEMPT_INTERVAL`. A coroutine
  yielding voluntarily when it had run too long was hard to explain and made `go()` and
  `StringBuffer::write()` suspend their caller depending on the clock. `go()` now never
  suspends its caller (SCH-5), and `write()` is not a suspension point. Preemption, with
  phasync-ext, will come from the event loop.

### Fixed

- A `RateLimiter` dropped before its first token was taken made `run()` throw
  `ChannelException: Channel is closed`; its token generator now ends quietly.

## 2.0.0-alpha19 (2026-09-29)

### Removed

- A coroutine that suspended with another `Fiber` (`Fiber::suspend($fiber)`) had the loop run
  that fiber at once, ahead of everything queued. It was a shortcut for channels, which no
  longer use it, and it could starve other coroutines. Suspending with a value is now an
  ordinary suspension.

## 2.0.0-alpha18 (2026-09-29)

### Changed (breaking)

- A context is any object (D4). `ContextInterface`, `ContextTrait`, `DefaultContext`,
  `ServiceContext` and the context's `ArrayAccess` storage are gone; `run()`, `go()` and
  `withContext()` take any object, and `getContext()` returns it. The loop tracks each
  context's coroutines and refuses to use a context twice (`ContextUsedException`).
- A failure nobody awaits goes to the nearest context implementing the new
  `phasync\Context\ExceptionHandlerInterface` (its own, or one it is nested in), and only the
  failed coroutine ends. With no handler it fails the nearest `run()`, which drops all its
  coroutines at once (they are never resumed; PHP destroys them, running their `finally`
  blocks) and throws the failure, or a `phasync\AggregateException` with all of them.
  Before, `run()` threw the first after everything else had run to completion, and logged the
  rest. `logUnhandledException()` is gone: phasync logs nothing. A service's failure goes to
  the outermost `run()`. A failed coroutine whose `Fiber` object is kept past its `run()` is
  thrown when the object is released, instead of being lost.

### Added

- `phasync::cancel($context)` cancels every waiting coroutine of a context and of the
  contexts nested in it, the deepest first, except the caller. `EventLoop::getFibers($context)`.

## 2.0.0-alpha17 (2026-09-29)

### Added

- `phasync\Context\SwitchAwareInterface`: a context with `resume()` and `suspend()`, which the event
  loop calls as the coroutine it runs next belongs to another switch-aware context (also at
  `withContext()`'s entry and exit). A server keeps per-request global variables, static
  properties or the locale swapped in for each request's coroutines. Switches within one context,
  and programs without switch-aware contexts, pay nothing measurable: 1.92M vs 1.93M switches/s.

## 2.0.0-alpha16 (2026-09-29)

### Changed

- `phasync::finally()` called inside `phasync::withContext()` runs as that call returns, in the
  calling coroutine and still in the context, instead of when the coroutine ends: whichever comes
  first. No coroutine is started for it, and it may suspend. A server that runs each request in
  `withContext()` and sends the response inside it gets code that runs after the response, as
  `fastcgi_finish_request()` gives under PHP-FPM, without the cost of a new fiber per request
  (about 11 µs in a Symfony-sized process). `withContext()` itself costs about 0.13 µs more.

## 2.0.0-alpha15 (2026-09-29)

### Fixed

- `Synchronized::run()` and `LockTrait::lock()` hand the lock to whoever waited longest. Before,
  releasing woke every waiter and the first to run took it, often a coroutine that had just
  arrived, so a waiter could lose many times in a row (#53). swerve-laravel, which serves requests
  one at a time through `Synchronized`, had a p99 of 150 ms at 2,500 req/s; now 6.6 ms.

## 2.0.0-alpha14 (2026-09-28)

- The Io\\Poll poller selection, which slipped into alpha13 unreleased, is taken out again.

## 2.0.0-alpha13 (2026-09-28)

### Fixed

- Cyclic garbage is collected also while no coroutine ends: the loop counts the possible cycles
  every 50 ms and collects at PHP's own threshold (10,000 roots), raising it while collections
  find nothing (#52). Before, a server whose connections' coroutines live on collected almost
  never; a swerve worker running CakePHP grew to 1.2 GB.

## 2.0.0-alpha12 (2026-09-28)

### Added

- `phasync\Util\LruCache`: a bounded in-memory cache, by entry count and bytes, with per-entry TTL.
  Every operation is O(1).

## 2.0.0-alpha11 (2026-09-28)

One event loop, pluggable waiting, and a much faster path with phasync-ext 0.5. Measured with
swerve on 56 cores: 4 times the requests per second at 50,000 connections, and twice the
throughput per worker from dropping the coroutine per request.

### Added

- `phasync\EventLoop`, the one event loop, and `phasync::getLoop()` (inside `phasync::run()`).
- `PollerInterface`: how the loop waits for streams. `StreamSelectPoller` without the extension;
  with phasync-ext 0.5 the loop uses the extension's `phasync\ext\Poller` (epoll).
- `EventLoop::getSlot()` / `park()` / `unpark()`: a lightweight wait for code that owns its
  waits (pollers, services). `unpark()` returns false for a vacant slot.
- `phasync::withContext($fn, $context)`: run a closure in the current coroutine as a coroutine
  of a context, without starting a coroutine. Coroutines it starts belong to the context and keep
  running. A server gives each request a context this way.
- phasync-ext 0.5 integration: `run()` hands the extension the loop's poller.

### Changed

- Every wait defaults to waiting forever, `readable()`, `writable()` and `idle()` included.
- `readable()` / `writable()` outside a coroutine honour their timeout on a non-blocking stream.

### Removed

- `phasync::stream()` and `phasync::READABLE`, `WRITABLE`, `EXCEPT`: one coroutine waits per
  direction with `readable()` / `writable()`.
- `phasync::setDefaultTimeout()`, `getDefaultTimeout()` and `DEFAULT_TIMEOUT`.
- `DriverInterface` and `phasync::setDriver()`: `EventLoop` is the only loop; what varies is the
  poller.
- phasync-ext 0.4 is no longer supported; use 0.5.

### Fixed

- The benchmark runner started its scenarios without the parent's `-d extension=`, so results
  "with phasync-ext" measured without it.

## 2.0.0-alpha10 (2026-09-27)

### Fixed

- `Synchronized::run()` refused a second coroutine of the same context ("not reentrant")
  instead of making it wait: it took the context for the holder, and the coroutines of one
  request share a context. The holder is the coroutine now; only the coroutine holding the
  lock is refused when it asks again.

## 2.0.0-alpha9 (2026-09-27)

### Added

- `phasync\Util\Pool`: a bounded pool of interchangeable resources (database connections,
  sockets to a service). `borrow()` waits while all are out, borrowers are served in the order
  they came, `release()` / `discard()` give an instance back (discard: broken, make a new one),
  `use(fn)` borrows and releases around a function. Instances are made on demand up to the
  size. One dropped without being given back is noticed when it is destroyed: a warning, and a
  new one in its place.

## 2.0.0-alpha8 (2026-09-26)

### Removed

- `phasync\Context\ChildContextInterface`, added in alpha7: not needed. A context stays the
  request's; a component framework tracks its parts' coroutines itself.

## 2.0.0-alpha7 (2026-09-26)

### Added

- `phasync\Context\ChildContextInterface`: a context whose coroutines work on behalf of
  another context's (a part of a request that can be cancelled on its own). Code that keys
  per-request state by the current context walks up to the root.

## 2.0.0-alpha6 (2026-09-26)

### Fixed

- A cancellation was lost when it reached a coroutine suspended in `phasync::preempt()`,
  which `go()` also calls: `preempt()` swallowed every exception thrown into it, so the
  cancelled coroutine carried on as if nothing had happened, and a `run()` waiting for it
  never returned. It is a suspension point like any other now (CAN-1): the cancellation is
  thrown there.

## 2.0.0-alpha5 (2026-09-26)

### Fixed

- Subscribing to a publisher after it was closed hung forever: the subscription looked for
  the end of the message list, whose last message is its own next. It now starts at the end,
  and its first read reports end-of-stream.

## 2.0.0-alpha4 (2026-09-26)

### Fixed

- `exit()` inside `phasync::run()` while coroutines waited on channels, publishers or flags
  ended the process with a fatal `LogicException` ("Flag is no longer valid and can't be
  raised", or "Can't enqueue a terminated fiber"), and exit code 255. PHP's shutdown
  destroys every object, also those still referenced, in no particular order, and a
  channel's destructor raised a flag already destroyed. A service cancelled by that shutdown
  no longer reports "ERROR IN SERVICE CONTEXT" either.

## 2.0.0-alpha3 (2026-09-26)

### Changed

- The coroutine that created a publisher (`phasync::publisher()`) may now subscribe to it.
  Subscribing from there threw `ChannelException`, a guard from before the publisher read
  every message at once; it only caught the creator waiting for messages nobody else sends,
  and only until another coroutine subscribed. Such a wait now waits, as a read of a channel
  nobody writes to does.

## 2.0.0-alpha2 (2026-09-25)

### Fixed

- A signal arriving while the event loop waits in `stream_select()` (EINTR, for example
  SIGCHLD from a child process or a `pcntl_signal()` handler) no longer fails every
  coroutine waiting on a stream with `IOException`. `stream_select()` returns `false` then,
  natively and with phasync-ext 0.4.0-alpha11; nothing is ready yet, so the waiters keep
  waiting.
- `StringBuffer::readFixed($n, 0)`, a non-blocking poll, returned `null` even when `$n`
  bytes had been written, unless an earlier read had already moved them into the buffer. A
  parser polling for complete frames in the coroutine that fills the buffer (swerve's
  FastCGI records) then never saw a frame that arrived in pieces.

## 2.0.0-alpha1 (2026-09-25)

### Added

- phasync-ext integration. With the extension loaded (see `phasync\try_enable_ext()`), the
  outermost `phasync::run()` puts its coroutines under `phasync\ext\manage()`: blocking code
  inside a coroutine -- `fread()`/`fwrite()`/`fgets()` on a blocking stream, file reads, DNS
  lookups, `usleep()` -- suspends the coroutine instead of the whole process. The functions
  return exactly what PHP returns, including on a socket timeout (partial data or `false`,
  `stream_get_meta_data()['timed_out']` set, no exception); cancelling a coroutine that is
  blocked in one of them throws out of that call. Needs phasync-ext 0.4.0-alpha9 or later.
- With the extension loaded, the driver uses `phasync\ext\stream_select()`, which has no
  `FD_SETSIZE` limit, so file descriptors numbered 1024 and higher work.
- phasync behaves the same with and without the extension. The only differences: without
  it, file descriptors from 1024 up fail (IO-7), and blocking code blocks the process. The
  suite runs in both modes; `tests/Characterization/ParityTest.php` compares the two.

### Changed

- **Breaking:** at most one coroutine waits on a stream per direction (IO-1). A second
  coroutine waiting to read (or write) a stream that already has a waiting reader (or
  writer) gets `LogicException` at once, without waiting. One reader and one writer at a
  time is fine. Two coroutines reading one stream at once is a data race on PHP's shared
  stream buffer, as with a shared buffered reader in Go.
- **Breaking:** a coroutine waiting on a stream is resumed only by the events it asked for.
  Before, a coroutine waiting to read was also resumed when the stream became writable.
- **Breaking:** `readable()`, `writable()` and `stream()` no longer make a stream
  non-blocking. The blocking mode is the caller's and decides what reads and writes do, as
  in plain PHP: a blocking `fgets()` waits for a whole line, a non-blocking one returns what
  is there. phasync's own helpers (`io::`, `Process`) make their streams non-blocking
  themselves, so they behave as before.
- Waiting on streams is faster with many waiting coroutines: the driver keeps the waiter of
  each direction and the `stream_select()` arrays up to date as waits start and end, instead
  of rebuilding them from every waiting coroutine on each tick. With 400 coroutines waiting
  on quiet streams, a socket ping-pong runs 2.1 times faster (benchmark `stream_idle_400`).

## 2.0.0-alpha0 (2026-09-24)

Early preview of the 2.0.0 line: a major, deliberately breaking redesign pass. Expect
further changes before a stable 2.0.0 -- notably, real global deadlock detection (DLK-3,
see `docs/SEMANTICS.md`) is planned but not yet implemented.

### Added

- `phasync\try_enable_ext()`: a safe, non-throwing probe that opportunistically loads the
  optional `phasync/phasync-ext` C extension if it's installed and available, without ever
  requiring it -- phasync behaves identically whether it returns `true` or `false`. Loading
  the extension can replace the current process (see `phasync\ext\ensure_loaded()`, which
  this wraps), so it must be called explicitly, once, as early as possible in a CLI
  script -- never automatically from inside `phasync::run()` or other library code that
  might run after the application has already done work with side effects. `composer.json`
  now `suggest`s `phasync/phasync-ext`.

### Changed

- `ReadChannelInterface::read()` and `WriteChannelInterface::write()` accept and return
  `mixed` instead of `\Serializable|array|string|float|int|bool|null`. A channel is
  single-process, in-memory communication between coroutines, so there was never a
  serialization step the old union was protecting -- it was a leftover from not having
  decided whether channels needed to survive a process boundary (D2), now resolved by
  moving that concern to a separate, later clustering primitive (see
  `docs/roadmap-2.0.md`). Closures, non-`Serializable` objects and resources now round-trip
  through a channel like any other value.
- `StringBuffer` takes an optional `$maxSize` in its constructor (default `null`, unbounded,
  unchanged). With it set, `write()` blocks once the buffer holds that many unread bytes,
  until the reader has consumed enough to make room -- the same wait/timeout shape as
  `read()`/`readFixed()`.
- `phasync\LockInterface`, `phasync\QueueInterface` and `phasync\LockTrait` moved to
  `phasync\Util\LockInterface`, `phasync\Util\QueueInterface` and `phasync\Util\LockTrait`.
  Their only real consumer was always `phasync\Util\Queue`; the 1.1.0 placement (matching
  `phasync\SelectableInterface`/`phasync\DeadmanSwitchTrait`) didn't fit them the way it fits
  those two, which are used broadly across core, not just by `Util\`. No back-compat shim:
  2.0.0 is where the 1.1.0 shims (`phasync\Interfaces\*`, `phasync\Util\LockTrait`,
  `phasync\Util\Collections\Queue`) are removed rather than kept a second cycle.

### Fixed

- `StringBuffer::readFromResource()`'s own backpressure loop never actually blocked:
  `$totalRead` was only ever decremented (by `unread()`), never incremented by `read()`/
  `readFixed()` consuming data, so the "is the buffer being drained" gap could never close;
  and the loop waited via `await()`/`isReady()`, which return at once whenever *any* data is
  buffered -- already true by definition once the gap is open. Past 1 MB net with a reader
  that isn't keeping up, this was an unyielding busy loop pinning a CPU core, not
  backpressure. Found while building `$maxSize` (no existing test exercised past 1 MB); fixed
  by having `read()`/`readFixed()` track consumption correctly and raise the flag on it, and
  waiting on that flag directly instead of through `await()`.
- `phasync::await()` accepts a `SelectableInterface` (`Channel`, `StringBuffer`, `WaitGroup`,
  `RateLimiter`, ...) in addition to a `\Fiber` or a promise-like object. Previously
  `phasync::await($aChannel, $timeout)` threw, since a `SelectableInterface` doesn't look
  like a promise to the pluggable promise handler.
- Unbuffered `Channel::write()` returned the instant a value was queued instead of waiting
  for a reader to actually consume it (CHN-1): `isReadyForWrite()` had an inverted condition
  for the unbuffered case (`$hasPendingWrite` instead of `!$hasPendingWrite`). It's now a
  true rendezvous. `Subscribers`' internal forwarding service adapted to match: it used to
  defer reading from its underlying channel until a `Subscriber` was waiting, which under
  true rendezvous semantics meant publishing with zero subscribers would suspend the writer
  forever (nothing was there yet to receive it); it now reads unconditionally from
  construction, so it's always available to rendezvous with a `write()`, regardless of
  subscriber count.

### Removed

- `phasync::select()`, and the `SelectorInterface`/`Selector`/`ClosureSelector`/
  `FiberSelector` machinery behind it, are gone -- not reimplemented, removed. An audit
  found real bugs in it (a TOCTOU race between a selectable being reported ready and the
  caller acting on it; the `$write` branch waiting on readability instead of writability;
  losing helper coroutines being `discard()`ed instead of `cancel()`ed, leaking a
  `FiberSelector`'s own background coroutine indefinitely for any raw `\Fiber` candidate
  that didn't win). A full redesign was worked through and would have fixed all three, but
  `select()` itself runs against phasync's actual goal: letting coroutines be written as
  if they weren't async at all. Selecting across several different kinds of things
  correctly requires reasoning explicitly about racing and cancellation -- exactly what
  phasync exists to make unnecessary. It also wasn't proven necessary: absent from
  `README.md` (unlike `Channel`/`WaitGroup`/`Publisher`), unused by `swerve`, and the one
  real consumer (`phasync/server`'s accept loops) only ever needed the narrower "wait
  across several raw stream resources" case -- which is itself better served by one
  coroutine per listening socket than by racing them in one. See `docs/SEMANTICS.md`
  section 8 for the full reasoning. `SelectableInterface` itself is unaffected; it was
  never actually coupled to `select()`.
- `phasync::waitGroup()`, deprecated since 1.1.0-rc6 in favor of `new
  phasync\Util\WaitGroup()` and never actually removed until now. It was a thin factory
  wrapping a `Util` class, exactly the pattern this cleanup pass is removing from core.
- `phasync::streamPoll()`, which had no callers anywhere in phasync, `swerve`, or
  `server`.
- `\phasync\run()`, `\phasync\go()` and `\phasync\await()`, the namespaced-function
  aliases in `src/functions.php`, deprecated since 1.1.0-rc6 in favor of the `phasync::`
  class methods (unaffected by this change) and never actually removed. One of them,
  `\phasync\go()`, had a real bug while it sat there: it spread its variadic `...$args`
  into `phasync::go($fn, ...$args)`, but that method's second parameter takes one array,
  not variadic positions -- `\phasync\go($fn, 'a', 'b')` would have passed `'a'` where an
  array was expected. Moot now that it's gone, but a concrete sign these wrappers were
  never being exercised.
- `\phasync\idle()`. It never fit this file's own stated purpose (functions with the same
  name as their native PHP equivalents, so importing them shadows the blocking native
  function) -- there is no native `idle()` to shadow, it was just another thin alias for
  `phasync::idle()`.
- `phasync\io::fread()`, `fgets()`, `fgetc()`, `fgetcsv()`, `fputcsv()`, `fwrite()`,
  `ftruncate()` and `stream_get_contents()`, and the `\phasync\{fread,fgets,fgetc,
  fgetcsv,fputcsv,fwrite,ftruncate,stream_get_contents}` functions that delegated to
  them. Zero usage anywhere outside phasync's own test suite -- not `swerve`, not
  `server`. `io::fgetc()` also had a real, undetected bug: `return \fread($stream, 1);`
  called the native *blocking* `\fread()` instead of `self::fread()`, so it never
  actually waited at all, and nothing caught it because it was the one function with no
  test coverage. `phasync\io::file_get_contents()`, `file_put_contents()` and `flock()`
  are kept -- these are the two functions most PHP code reaches for out of habit, so a
  coroutine-safe drop-in replacement has real ergonomic value independent of whether it's
  technically redundant with anything else. `file_put_contents()`'s own resource-copying
  path also had a harmless but sloppy duplicate `phasync::readable()` call for one read,
  fixed while this file was being gone through carefully. `flock()`'s busy-poll-via-
  `yield()` wait (there is no fd-based readiness signal for file locks, so some form of
  polling is unavoidable in userland) is left as-is for now, flagged for a closer look
  later rather than addressed in this pass.

## 1.1.0 (2026-09-22)

### Fixed

- `ReadChannelInterface::read()` (and `Channel`, `ReadChannel`, `Subscriber`) takes a new
  `?bool &$eof = null` out-parameter. `write(null)` has always been accepted, so a legitimately
  written `null` and "channel closed" used to be indistinguishable from `read()`'s return value
  alone, and `foreach` silently stopped at the first written `null`, leaving the rest unread.
  `$eof` is `true` only when the channel is closed with nothing left; `getIterator()` uses it,
  so `foreach` now gets everything. Implementing this for `Subscriber` (pub/sub) surfaced two
  further, related bugs, both fixed: its message chain ends in a self-referencing sentinel node
  whose placeholder `null` used to be returned as if it were a real published value; and
  `Subscribers`' internal draining loop used the same "null and closed" heuristic `read()` used
  to have, which could misread a published `null` racing with `close()` as end-of-stream and
  silently drop it before it ever reached a subscriber.
- `StringBuffer::readFixed()` throws `TimeoutException` when a real, positive timeout expires,
  matching `read()`. Before, a real timeout and the buffer genuinely ending with too little data
  were indistinguishable: both returned `null`. A timeout of exactly `0` still never throws
  (a non-blocking poll, also matching `read()`), and the genuine end-of-stream case still
  returns `null`.
- A failed `stream_select()` is reported loudly instead of silently stalling. On a stock POSIX
  build, `stream_select()` fails for the whole batch (not "nothing ready", the call errors)
  once any watched resource's real file descriptor number reaches `FD_SETSIZE` (1024). This was
  suppressed with `@` and treated the same as "nothing ready", so every fiber waiting on stream
  IO that tick hung forever with no trace of why. Every fiber in the batch now gets an
  `IOException` carrying PHP's own warning text. Raising the `FD_SETSIZE` ceiling itself is
  2.0.0 work; see `docs/roadmap-2.0.md`.

### Removed

- Windows support is dropped for 1.1.0 and will return, rebuilt, in 2.0.0.
  `phasync\Process\WindowsProcessRunner` and the bundled `bin/ProcessWrapper[64].exe` binaries
  are removed rather than kept as dead, deprecated code: the class has depended on
  `phasync\Legacy\Loop`, a class that has never existed at any point in phasync's git history,
  since the very first commit that introduced it, including every tagged 1.0.x release. Nobody
  could have had Windows support working through a normal install of any released version.
  `Process::run()` already throws a `LogicException` on Windows (see the `1.1.0-rc6` entry
  below) and continues to.
- `phasync\Psr\FormDataStream`, `phasync\Psr\TempFileStream` and
  `phasync\Psr\MultipartStreamInterface` (the interface `FormDataStream` was its only
  implementer of), none of which were referenced anywhere in phasync, swerve or server.

### Development

- Docker images for all four supported PHP versions (8.2 through 8.5), not just 8.2.
- `docs/roadmap-2.0.md` captures the research behind the two 2.0.0 directions: native IO
  hooking (transparent non-blocking PDO, memcached, etc.) and lifting the `FD_SETSIZE` ceiling,
  both via FFI into the running PHP process's own Zend Engine internals.

## 1.1.0-rc6 (2026-09-22)

### Fixed

- Coroutines waiting for a flag are now woken with a `CancelledException` when the flag object
  is garbage collected. `Flag::__destruct()` was meant to do this but never ran while a
  coroutine waited: `awaitFlag()` held its parameter for the whole wait, `flagGraph` held every
  flag, and `ObjectPoolTrait::popInstance()` left the popped object in the pool. A reused flag
  store could also make cancelling a timed out waiter fail with `Unable to cancel fiber`.
- All expired timeouts are delivered in the same timeout check, in registration order. Before,
  cancelling a fiber while iterating over the pending fibers made the loop skip about half of
  the expired ones, so ten simultaneous 0.2 s timeouts fired between 0.5 s and 2.0 s.
- `StringBuffer::read()` with a timeout throws `TimeoutException` when the timeout expires.
  Before it spun forever, and no other coroutine could run.
- `Process::run()` throws a `LogicException` on Windows. The Windows runner never worked in
  the 1.1 line.

### Added

- `WaitGroup::add(int $delta = 1)`, like Go's `WaitGroup.Add(delta)`. A negative delta marks
  work as done, and a delta that would make the counter negative throws a `LogicException`.

### Changed

- Waiting on a flag object that nothing else references, for example
  `phasync::awaitFlag(new stdClass())`, is undefined behaviour. It may end at once with a
  `CancelledException`.
- `tests/AllroundTests.php` is renamed to `AllroundTest.php` so that it is actually run.
- CI also tests PHP 8.4 and 8.5.

### Deprecated

- `phasync\Util\FastCGI\Record`: the FastCGI protocol code is moving to phasync/swerve and will
  be removed in 2.0.
- `phasync\Process\WindowsProcessRunner`: it does not work and is no longer used.

### Removed

- The dependencies `charm/options` and `laravel/serializable-closure`, which were not used,
  and the `psr/http-client-implementation` entry under `provide`, because phasync has no
  PSR-18 client.
- The internal classes `WorkerPool`, `ClosureStore`, `ChannelBuffered` and `ChannelUnbuffered`,
  which nothing used.
- `WebSocket`, `WebSocketFrame` and `LengthPrefixedFraming` in `phasync\Util\Protocols`. They
  were added during the 1.1.0 release candidates and were never in a stable release.
- `examples/legacy`, which used classes that no longer exist.

### Development

- `tests/Characterization` pins how the core behaves today, `benchmarks/bench.php` measures the
  performance of the core primitives, and `docs/SEMANTICS.md` describes the intended semantics
  with numbered rules.
