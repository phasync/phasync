# Roadmap: 2.0.0

This is where the research from the 1.1.0 cleanup cycle that doesn't belong in a patch release
gets captured, so it survives past the conversation that produced it. 1.1.0 is the small,
disciplined release: bug fixes, dead code removed, no new capability classes. 2.0.0 is where
phasync grows a new capability that couldn't exist without breaking things: native code
integration, via PHP's FFI extension, deep enough to solve two problems at once.

## Why FFI, and why now

phasync's async model depends on two things PHP's stream API does not give userland code:

1. **The real OS file descriptor number behind a stream resource.** There is no documented,
   public way to get it. Verified exhaustively: `(int)` casting a resource returns PHP's own
   internal resource-id counter, not the kernel fd -- proven by casting a `php://memory`
   resource (which has no real fd at all) and getting a plausible-looking small integer anyway,
   identical to `get_resource_id()`. Nothing in `stream_get_meta_data()`, nothing reflectable on
   `ext-sockets`' `Socket` object, no `socket_*` function returns it either. There is a stalled
   PHP RFC proposing a native `file_descriptor()` function (`wiki.php.net/rfc/file-descriptor-function`)
   that would make this whole section unnecessary if it ever lands.
2. **A way past `stream_select()`'s `FD_SETSIZE` ceiling.** Stock `stream_select()` fails
   outright once any watched resource's fd reaches 1024 on a typical POSIX build. Confirmed by
   reproduction: `stream_select(): You MUST recompile PHP with a larger value of FD_SETSIZE`, and
   the call returns `false` for the *entire* batch, not just the one high-numbered resource. This
   was already a live, if quiet, phasync bug -- `StreamSelectDriver` suppressed the warning with
   `@` and treated `false` the same as "nothing ready yet", so every stream-waiting coroutine in
   the process would hang forever with no trace of why. Fixed for 1.1.0 by making that failure
   loud (see `docs/SEMANTICS.md`, rule `IO-7`) -- but the underlying ceiling is still there. A
   real fix means removing the ceiling itself, which stock PHP cannot do.

Both problems have the same shape: PHP's own C implementation already has the answer
(`php_stream_cast(stream, PHP_STREAM_AS_FD, ...)` is literally how `stream_select()` gets a real
fd from an arbitrary stream internally), userland just isn't allowed to ask for it. FFI is the
way in, but only when it's given access to the Zend Engine's own already-loaded internals, not
merely used to load a separate library.

## The mechanism (verified working, this session, PHP 8.5.1)

`FFI::cdef($header)` with no library path resolves declared symbols against the *already
running* PHP binary's own symbol table (effectively `dlsym(RTLD_DEFAULT, ...)`), not a
separately `dlopen()`ed library. This works reliably on any PHP build that can load extensions
at all, because PHP's own extension-loading mechanism depends on exactly these same core Zend
API symbols being resolvable -- if they weren't, no compiled extension (`curl`, `mysqli`,
anything) could load either. So symbol *availability* is not the risk.

The risk is the struct layouts. `zval`, `zend_array`, `zend_resource` and friends are internal,
unstable-across-versions Zend structures with no ABI guarantee. A hand-copied header that
doesn't exactly match the running PHP build's real memory layout doesn't crash cleanly -- it
silently misinterprets memory. This is real, not theoretical: PHP's zval structure alone has
changed shape across major and minor releases historically.

Proof this works, end to end, cross-validated against independently-known ground truth:

| Resource | FFI-extracted fd | `get_resource_id()` |
|---|---|---|
| Regular file | 4 | 6 |
| Socket pair | 5 | 7 |
| A resource pushed past the real 1024 threshold | **1024** | 1027 |

The last row is the decisive check: for a resource independently known (from
`stream_select()`'s own warning, a completely different code path) to have real fd 1024, the
FFI extraction returned exactly 1024. This also directly refutes the smaller community
project's README claim that the technique doesn't work for network streams (it does, at least
on this PHP build) -- though that project's own hand-maintained, single-version header is
exactly the fragile part described below, not something to depend on as-is.

### The mistake worth remembering

Mid-session, a first attempt to write an end-to-end test for the `FD_SETSIZE` fix used `(int)`
casting as a stand-in for "have we reached fd 1024 yet" -- the exact mistake this document just
said not to make, made again out of habit. It produced a plain timeout instead of the expected
exception. Instrumenting with the real FFI extraction showed why: `resource_id=1061` while the
real fd was still only `1024` -- a 37-descriptor divergence once phasync's own autoloader (and
FFI) had opened other files first. In a bare, single-purpose script the two counters happen to
move in lockstep (nothing else is allocating resources to offset them); inside a real
application they don't. The permanent 1.1.0 regression test (`StreamIOTest.php`, rule `IO-7`)
avoids the whole problem by not trying to detect the boundary at all -- it opens a wide,
deliberately generous margin of resources instead, which needs no FFI and cannot be fooled by
this divergence.

## Prior art

- **`frodeborli/php-src`, branch `stream_select_unlimited`** (github.com/frodeborli/php-src) --
  the maintainer's own unfinished patch to core `stream_select()`, replacing the fixed-size
  `fd_set` bitmap with a dynamically sized `fd_bigset`. Upstreamed as
  [php/php-src#14452](https://github.com/php/php-src/pull/14452): still open, 18 commits, 89
  review comments from real php-src reviewers (arnaud-lb, TimWolla, withinboredom, bukka,
  mvorisek), last active March 2026. Windows complication found in review: WinSock's `select()`
  uses a completely different `fd_set` representation (an array of `SOCKET` handles with a
  count, not a bitmap, default cap 64), so the fix doesn't carry over directly. Relevant as
  reference for the *reason* the ceiling exists and what a from-scratch unlimited select/poll
  needs to handle, not as something phasync depends on -- it requires a patched, recompiled PHP,
  which is the whole problem FFI avoids.
- **`ppelisset/php-fileno`** (github.com/ppelisset/php-fileno) -- the small demo that first
  showed this session the "FFI into already-loaded Zend symbols" mechanism concretely (`zval()`
  via `zend_rebuild_symbol_table()`, `get_resource_from_zval()`, `zend_fetch_resource2()`,
  `php_stream_cast()`). Minimal, single hand-maintained header, no stated multi-version support.
  Good as a worked example, not as a dependency.
- **`Encritary/streamfd`** (github.com/Encritary/streamfd) -- the same idea as a real compiled
  Zend extension instead of FFI. The traditional path: full Zend API access, but needs
  `php.ini` registration and a build per PHP ABI version.
- **`lisachenko/z-engine`** (github.com/lisachenko/z-engine, packagist `lisachenko/z-engine`) --
  the mature reference for doing this *safely*. Ships **generated, version-exact** FFI
  definitions per PHP minor version and **refuses to boot if the definitions don't match the
  running interpreter down to the byte**, rather than silently trusting a stale layout. This is
  the discipline any narrower phasync-owned version of the technique should copy. Its own
  README, prominently: "Experimental -- not for production. Z-Engine operates on raw engine
  memory. Segfaults are a feature of the territory, not a bug in your code." Support matrix
  currently covers PHP 8.4 and 8.5 (linux-x64, darwin-x64/arm64, windows-x64, both NTS and ZTS),
  with 8.0 frozen as legacy -- **PHP 8.1, 8.2 and 8.3 are not covered**, a real gap against
  phasync's own `^8.2` requirement that would need closing, either upstream or in phasync's own
  narrower implementation. It's a broad, general-purpose engine-manipulation library (operator
  overloading, custom object handlers, AST rewriting, opcode handlers); phasync would only need
  a narrow slice of what it does, so vendoring the technique narrowly (informed by its
  version-matching discipline) fits phasync's own "don't overengineer" instinct better than
  depending on the whole library.
- **`ircmaxell/ffime`** (github.com/ircmaxell/ffime, packagist `ircmaxell/ffime`) -- not a
  competing library, complementary tooling: a header parser that generates FFI struct/function
  bindings from real C header files at build time, instead of hand-transcribing them (which is
  exactly the fragile part of the `php-fileno`-style approach). Relevant for *generating* the
  version-exact bindings this whole approach depends on, from PHP's own real `Zend/zend_types.h`
  and related headers, rather than hand-copying them per version.

## The two directions

### A. Unlimited-fd polling (supporting infrastructure)

A small C library (built once per OS/arch, no PHP-version dependency since it touches no Zend
internals itself) implementing `select()`/`poll()` with no fd-count ceiling -- `poll()`'s
`struct pollfd[]` array has no `FD_SETSIZE`-style limit to begin with, so this may not even need
to reproduce the `frodeborli/php-src` patch's bitmap approach; a plain `poll()` wrapper might be
simpler and sufficient.

The real fd numbers still have to come from somewhere. Two paths:

- **Resources phasync creates itself** (the clean case): if the same layer that does the
  `socket()`/`pipe()`/`fork()`+`exec()` calls also does the polling, the fd is already known --
  nothing needs extracting. `Process` is the obvious first target: replacing `proc_open()` with
  a direct `fork()`/`exec()`/`pipe()` implementation gives real fds for free, *and* is
  essentially the same mechanism the Windows process story needs (`CreateProcess` + named pipes
  bridged to a pollable socket, the same idea as the old, deprecated `ProcessWrapper.exe`, just
  unified into one properly sourced, CI-built, auditable piece of infrastructure instead of an
  unexplained binary in `bin/`). One C layer, two platforms, solves both the `FD_SETSIZE`
  ceiling and Windows process support together.
- **Arbitrary externally-supplied resources** (the general case: a user's own
  `stream_socket_client()`, a plain `fopen()`): needs the Zend-internals fd extraction described
  above. This is what makes it possible to lift the ceiling for `readable()`/`writable()`/
  `stream()` on resources phasync didn't create, not only ones it did.

### B. Native IO hooking (the flagship feature)

The bigger, more transformative direction, and the reason 2.0.0 is worth its major version bump.
Not just reading a stream's fd -- intercepting the blocking IO native C libraries (PDO drivers,
memcached, etc.) do internally, and turning it into a coroutine suspension, transparently, with
no per-library wrapper class needed.

**The mechanism.** `Fiber::suspend()` is a native stack-context switch (PHP's Fiber
implementation swaps the C-level stack and registers), not a bytecode-level yield instruction
the Zend VM has to recognize. It can therefore be called from *any* C frame currently executing
on top of a Fiber's own stack -- including from inside a `php_stream_ops.read`/`.write` callback
that a driver's C code invokes with zero awareness that Fibers, or phasync, exist. From that
C code's point of view, the call simply took a while and returned, exactly as if the blocking
syscall it expected had completed normally.

**The hook point.** `php_stream_ops` (`main/php_streams.h`) is the vtable every PHP stream
implementation registers -- `read`, `write`, `close`, `flush`, and more. It's lower-level and
more general than replacing individual internal PHP functions one at a time: anything routing
through PHP's stream abstraction is caught in one place. `mysqlnd` (PDO_MySQL's usual transport)
typically does route through it. Not everything does -- `libpq` (PostgreSQL) and some memcached
client libraries make their own raw socket syscalls directly, bypassing PHP streams entirely,
and would need a different interception point (replacing entries in PHP's own internal function
table, or process-wide `LD_PRELOAD` interposition of libc's `connect()`/`read()`/`write()` --
the latter only settable as an environment variable before the process starts, an external
deployment step, not something a running script can opt into for itself the way the FFI/Zend
technique can).

This is, in shape, what Swoole's "one-click coroutine" already does, and is the direct answer to
the "What color is your function?" framing in phasync's own README: it would let PDO, curl, and
similar libraries become concurrent without the developer rewriting anything, and would make
phasync's existing hand-written, one-driver-at-a-time wrappers (`Wrappers/PDO/MySQL.php`,
`Services/CurlMulti.php`) unnecessary rather than something to maintain forever, one library at a
time.

**The real cost, named honestly rather than glossed over.** Suspending a fiber mid-way through a
native library's own internal call means the scheduler is free to run *other* fibers before this
one resumes -- including ones hitting the same driver, possibly the same connection. Whether
that's safe depends entirely on whether the C library's own internal state tolerates being
paused there. This is exactly the class of problem Swoole's own per-driver integrations have
needed real, sometimes painful, hardening to get right over years, not something a single
general hook mechanism resolves for free just by existing.

## Sequencing

1. 1.1.0 ships first, clean, with the loud-failure fix (`IO-7`) as the extent of what stock PHP
   allows -- no FFI, no new runtime dependency, nothing in this document blocks it.
2. 2.0.0 alpha: unlimited-fd polling for `Process` first (the self-contained, lower-risk case: no
   external-resource fd extraction needed, and it also carries the Windows process story).
3. Extend to arbitrary external resources (the general `readable()`/`writable()`/`stream()`
   case), once the fd-extraction layer has real, tested, version-matched coverage for phasync's
   supported PHP range (8.2 through at least 8.5) -- broader than any existing community
   project's matrix today.
4. Native IO hooking (`php_stream_ops`, then whichever drivers matter most -- PDO/mysqlnd is the
   obvious first target given it usually already routes through PHP streams) as the 2.0.0
   flagship, built on the same version-matched-bindings foundation, with the reentrancy question
   treated as the real, expected cost of each driver integration rather than something solved
   once for all of them.

None of this is a small undertaking. It needs its own dedicated design-and-build session(s), not
something absorbed into a cleanup pass.
