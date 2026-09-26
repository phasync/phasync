# Changelog

Earlier releases are listed on the GitHub releases page.

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
