# Changelog

Releases before 2.0.0 are listed on the GitHub releases page.

## Unreleased

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
