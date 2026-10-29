# Changelog

Releases before 2.0.0 are listed on the GitHub releases page.

## 2.0.7-RC1 (2026-10-29)

Bundles ext-phasync 2.0.7-RC1: Scope-bound coroutines leave the request that started them, one reader per stream and one coroutine at a time per PDO, mysqli or phpredis connection, writers queue on a stream, persistent connections refused unless `phasync.allow_persistent=1`, and select() up to 4096 watched descriptors before epoll.

- A channel's creator may wait on it like any coroutine: the 100 ms "likely deadlock" guess and its `ChannelException` are gone, and so is the busy `yield()` loop behind it. A wait nothing can ever end fails the outermost `run()` with a `DeadlockException`, exactly, when the last coroutine that could have ended it is done. `activate()` does nothing and is deprecated, removed in 2.1; on a subscriber it no longer throws. (#153)
- `phasync::await()` of a promise sleeps until it settles instead of yielding in a loop; a promise nothing can settle any more fails `run()` with a `DeadlockException`. (#152)
- Channel `read()` and `write()` no longer start with a `phasync::sleep()`: one that need not wait returns at once, without letting other coroutines run first (SEMANTICS CHN-10). An unbuffered `write()` still waiting when the reading end is closed or dropped throws `ChannelException('Channel is closed')`; the value was never read. (#158)
- `BufferedStream` and `UnbufferedStream` wait until the other side acts by default, instead of throwing a "possible deadlock" `TimeoutException` after 60 seconds; a stall nothing can end is an exact `DeadlockException`. `$deadlockTimeout` remains for a timeout of your own. The coroutine that created an `UnbufferedStream` may convert it to a string. (#158)
- `ResourceStream::__toString()` leaves the waiting to `read()`: no `yield()` between reads. (#154)
- A long ready queue no longer makes timers late: a tick resumes ready coroutines until the next timer is due or 10 ms have passed (1 ms at least), leaves the rest queued in order, and the timers due then run before them. A timer is late by about one coroutine's turn instead of a whole queue's (100 coroutines with 1 ms of work each: 184 ms late before, under 2 ms now). Also after a preemption with ext-phasync.
- A coroutine's scope, and what only it holds, is freed as the coroutine ends, also when nothing else runs: when the loop has nothing to run it switches to the scope `run()` began in, instead of keeping the last coroutine's scope live until another coroutine runs. A request's resources in an idle worker were held until its next event. (#159)
- The loop collects cyclic garbage at every idle moment (nothing runnable, and a `poll(0)` finds no ready I/O), then waits for what remains of its timeout. Idle collections are paced by their own cost: the next waits 20 times the last one's duration (under 5 % of the time), so a big application's heap isn't collected at each short wait of a request, such as every database round trip. It collected at most once per 0.5 s, and up to 4 s apart after collections that found little: an idle worker kept garbage that held a resource, such as a connection in a reference cycle, for that long. `PHASYNC_GC_MIN_INTERVAL` is gone; a loop that never idles still collects every 0.5 s (`GC_MAX_INTERVAL`), and no longer backs off. Within 30 s of a second with nothing to run, a busy loop waits up to 15 s for an idle moment instead: a site's bursts (a page and its assets) collect in the quiet after them. A destructor that runs in the collection and wakes a coroutine wakes it at once. (#160)
- Several coroutines may wait to write one stream (`phasync::writable()`, a log written by many): they queue, and each time the stream is writable the one that waited longest goes on. A second reader still gets `LogicException`. (#161)
- `Frame` is `Countable`: `count(phasync::frame())` is how many coroutines belong to the frame now, the one running it included. A server tells by it whether a request left coroutines running.
- CI runs the suite on a thread-safe PHP 8.5 with ext-parallel: each thread runs a phasync loop of its own; `docs/use-in-existing-projects.md` tells how to wait for a thread without blocking the loop. (#145, #149)

## 2.0.6 (2026-10-28)

Bundles ext-phasync 2.0.5, with ext-scope 0.5.0: cheaper scope switches, and a per-tick poll as cheap as 2.0.3's.

- With ext-scope 0.5 (bundled in ext-phasync), the loop runs its ticks in a scope of its own through `Scope::__run_without()` (coroutines still run in the scope they were started in): resuming coroutines of one scope switches no state, and moving between two scopes switches once instead of twice. `onScopeChange()` hooks no longer see a switch back to the caller's scope between coroutines.
- `StringBuffer::read()` and `readFixed()` are 30-50% faster: only a read that waits claims the single reader slot, and the deadline is computed only then; 2.0.5 claimed it for every read (15-24% slower than 2.0.4).
- `StringBuffer` keeps written chunks as they are and reads them with `substr()`; a read that spans chunks is assembled with one `implode()`. `readFixed()` of a 4 MB frame from 4 KB writes takes 6 ms (2.0.5: 906 ms, 2.0.4: 10 ms), and 16 MB written at once and read in 4 KB pieces takes 16 ms (2.0.4: 9.8 s). Small reads from a long chunk split its unread rest into pieces aligned to the read size once more than `BUFFER_WASTE_THRESHOLD` (64 KB) is read; `BUFFER_WASTE_LIMIT` is its deprecated alias, removed in 2.1. `unread()` of the bytes just read steps back instead of queueing a copy. Frame benchmarks 8-30% faster than 2.0.4.
- With ext-phasync, the loop resumes and cancels every coroutine with `Fiber::resume()`/`throw()`, including one a blocking call suspended: no lookup per resume (buffered channels 10%, socket round trips 8% faster).

## 2.0.5 (2026-10-27)

Bundles ext-phasync 2.0.4, with ext-scope 0.4.4. With the extension, coroutines wait through its
`Suspension`; `run`, `begin` and `finish` become keywords for `phasync::run()`, `go()` and
`await()` under `vendor/bin/phasync`; `sapi()` runs PHP's own request startup, so php.ini's limits
and `filter_input()` apply per request; memcached and redis session locks wait in the coroutine;
and `session_start()` throws instead of blocking when another scope of the process holds that
session's lock.

Requires fiber-interop/scope 0.4. A new `Scope` starts with empty `$_GET`, `$_POST`, `$_COOKIE`,
`$_FILES`, `$_REQUEST` and `$_SERVER`, PHP's ini timezone and locale, and no session; whoever
creates it seeds what it needs. Coroutines still share their creator's scope. `Scope::bind()`
wraps a callback to run in a given scope.

- `phasync::cancel()` on a coroutine that already ended does nothing, as documented; it threw `InvalidArgumentException`.
- A second coroutine reading a `StringBuffer` while another is reading throws `LogicException`; the reads interleaved.
- `awaitFlag()` and `park()` timeouts under 10 ms fire at their real time, not in 10 ms steps.
- `RateLimiter` documents its token-bucket contract.
- `ResourceStream::getSize()` is null for a stream with no size (a socket, a pipe, php://input), and casting such a stream to string reads it; it reported 0, with a warning for php://input, and the cast returned ''.
- Reading a large `StringBuffer` chunk in pieces takes linear time; every read copied the unread rest (16 MB in 64 KB reads: 1.2 s, now 2 ms). `BUFFER_WASTE_LIMIT` is unused and deprecated.
- Needs ext-phasync 2.0.4 or later 2.0.x when the extension is loaded: the extension's minor version must match phasync's. With it, a blocking call suspends its coroutine through the extension's `Suspension`, and a wait in a Fiber the coroutine runs itself (Drupal's renderer) suspends the whole coroutine instead of running the loop in place. A coroutine busy in such a Fiber is preempted too.
- phasync replaces `phasync/fiber-interop-facade`.
- `phasync\ext\current_fiber()` is deprecated: use `Fiber::getCurrent()`.

## 2.0.4 (2026-10-20)

Bundles ext-phasync 2.0.2, with ext-scope 0.3.1: loading the extension no longer slows code
that doesn't use it. Generators now switch scope through calls compiled into their own bodies,
so ordinary function calls cost what they cost without the extension (they were 15-20% slower).

## 2.0.3 (2026-10-19)

Bundles ext-phasync 2.0.1: the Linux glibc binaries need at most glibc 2.34, so they load on
Debian 12, Ubuntu 22.04 and RHEL 9; `curl_exec()` in a coroutine resumes after
`curl_pause(CURLPAUSE_CONT)`.

## 2.0.2 (2026-10-19)

Documentation corrections.

## 2.0.1 (2026-10-19)

phasync no longer declares fiber-interop's exception interfaces; the fiber-interop phasync
adapter translates phasync's exceptions, as adapters do for every runtime. This also lets
phasync load alongside TrueAsync, whose `Async\` namespace collides with the old `async\`.

## 2.0.0 (2026-10-19)

Coroutines on stock PHP Fibers. Release packages carry prebuilt binaries of the optional
phasync extension, ext-phasync 2.0.0 (also `pie install phasync/ext-phasync`), with the
`Scope` class from `fiber-interop/ext-scope` bundled inside it. Upgrading from 1.x:
[UPGRADING.md](UPGRADING.md).
