# Changelog

Releases before 2.0.0 are listed on the GitHub releases page.

## Unreleased

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
- `vendor/bin/phasync` enables the extension's `phasync.keywords`.
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
