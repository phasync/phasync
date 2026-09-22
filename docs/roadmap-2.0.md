# Roadmap: 2.0.0

This is where the research from the 1.1.0 cleanup cycle that doesn't belong in a patch release
gets captured, so it survives past the conversation that produced it. 1.1.0 is the small,
disciplined release: bug fixes, dead code removed, no new capability classes. 2.0.0 is where
phasync grows a new capability that couldn't exist without breaking things: native code
integration, deep enough to solve two problems at once.

## Status update: a real extension exists now, not just FFI research

Everything from "Why FFI, and why now" through "The two directions" below was written when the
only working proof of the underlying mechanism was FFI-into-live-Zend-internals, verified in a
throwaway script. That's no longer the situation. **A real, compiled PHP extension --
`phasync-ext` -- now exists, implements almost everything directions A and B describe, and is
tested**: 7 `.phpt` tests, run directly against the built module in this session (`php
run-tests.php -d extension=.../modules/phasync.so tests/`), **all 7 pass**, not taken on the
maintainer's word. It's now public: **`github.com/phasync/phasync-ext`, MIT-licensed, PHP 8.3+**
(confirmed live in the `phpsrcstreamselect` chatter room by the session that published it, not
just the local checkout at `/home/frode/dev/phasync-ext` this section was originally verified
against) -- treat the GitHub repo as the canonical home going forward.

It's a real Zend extension, not FFI: it has full Zend API access at compile time, so it never
needs the hand-copied-struct-layout risk the FFI section below spent so much effort de-risking.
It gets real fds the same way `stream_select()`'s own C implementation does --
`php_stream_cast(stream, PHP_STREAM_AS_FD_FOR_SELECT | PHP_STREAM_CAST_INTERNAL, ...)`, called
directly, no FFI, no layout guessing. That makes the entire FFI mechanism section below
(`FFI::cdef`, the struct-layout risk, `z-engine`'s version-matching discipline) moot for anything
this extension covers -- it would only still matter for a pure-userland fallback that needs no
compiled extension installed at all, which is not what's built here.

What it does, verified in this session:

- **Tier 1 -- `phasync\stream_select()`**: a `poll(2)`-based replacement for `stream_select()`,
  growable, no `FD_SETSIZE` ceiling, works *today* on any current PHP -- doesn't need
  php/php-src#14452 to merge into core at all. Confirmed via the extension's own
  `stream_select_past_fd_setsize.phpt` (passing). This delivers direction A's actual goal
  immediately, as an installable extension, independent of the core patch's timeline.
- **Tier 2 -- transparent async socket I/O**: `enable_hooks()` re-registers the `tcp://` and
  `unix://` stream transports so that any `fread()`/`fwrite()` on a socket they create --
  ordinary userland code, unmodified -- transparently suspends a Fiber on a would-block instead of
  blocking the process. Mechanism, read straight from `phasync.c`: `register_read_handler()`/
  `register_write_handler()` register a plain PHP callable; on `EAGAIN`/`EWOULDBLOCK` from a raw
  `read()`/`write()` on the extracted fd, the C code calls that handler synchronously with the fd
  and *blocks its own C call frame waiting for it to return*. This only works because the whole
  chain is running on a Fiber's own stack: the handler calls `Fiber::suspend()`, which is a
  stack-context switch, not a return -- exactly the "`Fiber::suspend()` can be called from any C
  frame on a Fiber's stack" mechanism the original "Direction B" section below theorized about, now
  confirmed in working, tested code, not theory. The C side "never touches the fiber API," per its
  own top-of-file comment -- userland owns all suspension.
- **Tier 2b -- `proc_open()` pipes wrapped too**: confirmed via `hook_proc_open_async.phpt`
  (passing) -- a coroutine's `fread()` on a child process's pipe suspends and resumes correctly.
  This is a third, better-than-either path to what the `Process`/Windows addendum further below
  already concluded needed no custom launcher -- except this doesn't even need `Process` to wait
  for a PHP core version floor, it works now, on whatever PHP is running, once the extension is
  loaded.
- **Tier 2c -- TLS transports** (`ssl://`, `tls://`, and siblings): hooked in "delegate mode"
  since encrypted bytes can't be read/written raw off the fd -- it sets the stream non-blocking and
  retries the *original* `SSL_read`/`SSL_write` op, waiting on the handler when that returns
  0-without-EOF. Confirmed via `hook_tls_async.phpt` (passing, real self-signed cert, real
  `stream_socket_server`/`client` over `tls://`). The extension's own comment is honest about a
  known v1 gap: "TLS renegotiation wanting the opposite direction is a known v1 limitation" --
  worth remembering, not a blocker. Two more v1 gaps confirmed by the publishing session, not
  visible from reading `phasync.c` alone: the `ssl://` **handshake itself is still synchronous**
  (only post-handshake reads/writes are hooked), and `stream_socket_enable_crypto()` -- the
  STARTTLS-style in-place upgrade of an already-open plain socket to TLS -- **is not yet
  re-wrapped**, so only sockets created directly via an `ssl://`/`tls://` URL get Tier 2c's
  treatment today.
- **`sleep()`/`usleep()`/`time_nanosleep()`/`time_sleep_until()` hooked too**: confirmed via
  `hook_sleep.phpt` (passing). This reaches further than sockets/pipes -- any third-party library
  code that calls these directly for retry/backoff/polling becomes cooperative automatically, with
  zero code changes, as long as it runs inside a Fiber.

Real, current limitations, not glossed over:

- **POSIX-only right now** -- there is a `config.m4` (Autoconf/Unix build) but no `config.w32`
  (Windows build) anywhere in the tree. This doesn't extend to Windows on its own; it would need
  its own Windows-side implementation (the socket-hooking approach is plausible there too, but
  unbuilt).
- **The wait handlers are process/request-global**, one slot each for read/write/sleep, not
  per-stream or per-scheduler -- fine for phasync's own single-scheduler-per-process model, a real
  constraint for anything wanting two independent event loops in one process.
- **Requires running inside a Fiber** -- the handler must call `Fiber::suspend()` and the whole
  call chain from the triggering `fread()`/`fwrite()`/`sleep()` down through the extension's C code
  must be on that Fiber's own stack, or `Fiber::suspend()` throws. Not a new constraint for
  phasync specifically (everything already runs inside fibers via `phasync::go()`), but a hard
  requirement for anyone else adopting the extension directly.

**What this changes for the rest of this document:** the FFI mechanism research, the prior-art
survey, and directions A/B below are kept as-is beneath this section -- they're still the accurate
record of the reasoning that led here, and direction B's "real cost" section (fiber-suspend-mid-
native-call reentrancy) still applies to `phasync-ext` exactly as written, since it's the same
mechanism. But treat `phasync-ext` as the primary 2.0.0 path for both directions now, with the FFI
route as a fallback only if a no-compiled-extension story turns out to matter. The "Sequencing"
section further below needs a corresponding rewrite once the GitHub repo is up and this is a
decision the maintainer has actually made, not assumed here.

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

## What changed since this was first written

Two PHP core proposals surfaced after the research above, and they materially change the plan.
Read their actual text before trusting a summary of a summary -- the numbers below came from
fetching the RFC and the pre-RFC discussion directly, not from search snippets.

**`Io\Poll` (wiki.php.net/rfc/poll_api) is real, already voted (33-1-4), already merged,
targeting PHP 8.6 (19 Nov 2026).** `Context`/`Watcher`/`StreamPollHandle` wrap epoll (Linux),
kqueue (BSD/macOS), event ports (Solaris/illumos), WSAPoll (Windows), falling back to `poll()`.
Two things from the actual design:

- **Registration is persistent and epoll-style**, confirmed directly from the RFC text:
  "resources remain registered until explicitly removed via `watcher->remove()`." This is
  exactly the structural property that made `select()` competitive with epoll for phasync's own
  workload in this session's earlier discussion (high churn, short-lived, per-operation
  registrations, not long-lived stable ones). `Io\Poll` does not change that tradeoff -- it
  inherits it. Its own stated motivation ("`stream_select()` is O(n)... epoll/kqueue maintain
  performance beyond 10,000 connections") is the standard C10K argument for *stable* connection
  sets and says nothing about phasync's actual per-operation churn pattern. Adopting it as a
  drop-in inside `StreamSelectDriver`, which rebuilds its whole interest set every tick, would
  likely regress phasync's own benchmarks (`benchmarks/bench.php`), not improve them -- a win
  only comes from redesigning the driver around register-once-reuse-many, which is real
  architectural work, not a swap. **Benchmark before adopting, exactly as already planned for
  epoll; do not adopt reflexively when 8.6 lands.**
- **It solves the fd-extraction problem safely, for free**, on PHP 8.6+: `StreamPollHandle`
  wraps a PHP stream resource and extracts its fd "internally through C-level operations tables"
  (the same `php_stream_cast` machinery this document verified via FFI, just exposed officially).
  Once 8.6 is a viable floor, there is no need for the FFI-into-live-Zend-internals technique
  this document spent real effort verifying, at least for readiness polling. That risk (hand-
  maintained struct layouts, version-matching discipline, `z-engine`'s own "experimental, not
  for production" warning) only still matters for what `Io\Poll` does *not* cover: direction B
  below, actual blocking-call interception.

**The Async Scheduler ABI (discourse.thephp.foundation, "Preparing the PHP engine for true
concurrency") is the more exciting, more speculative one.** Pre-RFC, no vote, dated July 2026 --
treat as a watch item, not something to plan around yet, especially given the "True Async" RFC's
outright rejection (9 against, 1 abstain) shows core is cautious about big async changes. The
design: a pluggable scheduler *extension*; "the core itself gains no scheduler, no reactor, and
no event system," only hook points (`bindEntry`, `switchTo`, `currentCoroutine`). Its stated
long-term vision is direction B below, verbatim, from the core side: "standard blocking functions
(sleep, file and socket I/O, DNS, streams) become non-blocking transparently, without any changes
to existing userland code." If this lands, it becomes the *official, sanctioned* version of what
direction B builds via FFI now.

**What this means for the plan:** build direction B (native IO hooking) via FFI now, as planned
below -- don't wait on a pre-RFC with no timeline. But keep `DriverInterface` (already exists) as
the seam, so a future `IoPollDriver` or an ABI-based implementation can replace
`StreamSelectDriver` later without touching the rest of phasync. This also reduces urgency on the
maintainer's own `frodeborli/php-src` `stream_select_unlimited` patch (github.com/php/php-src#14452)
independently of the maintainer's own separate move to continue that work directly -- `Io\Poll`
may make a patched `stream_select()` moot for anyone on 8.6+ regardless of how that PR resolves.

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
  the maintainer's own patch to core `stream_select()`, upstreamed as
  [php/php-src#14452](https://github.com/php/php-src/pull/14452), actively being developed
  through this same conversation (verified live via `gh api`, not just cited from memory) --
  as of 2026-09-22, open, 24 commits, 89 review comments, retitled
  **"Fix stream_select() failing unpredictably (and crashing on Windows) with many descriptors."**
  Scope, from the PR's own description: **POSIX** replaces the fixed `fd_set` bitmap with a heap
  bitset sized to the descriptors actually in use, no recompilation needed, no cap. **Windows**
  now covers *both* halves of the problem, not just one: the winsock socket path gets a growable
  `fd_set` that doubles on demand (parity with POSIX -- no socket cap left at all, superseding the
  build-time `--enable-fd-setsize=256` default entirely), and the separate pipe/file
  (`WaitForMultipleObjects`) path, which previously overran a fixed 64-entry stack array and
  **crashed the process** (`STATUS_STACK_BUFFER_OVERRUN`) past 64 handles, now fails cleanly
  (warning + `false`) instead -- a real stability fix, not just a feature gap closed. `php_select`'s
  exported `fd_set *` ABI is unchanged either way. **Explicitly out of scope, per the PR body**:
  `socket_select()`, mysqlnd, and the FPM `select` backend are untouched and still bounded by
  `FD_SETSIZE` -- this fix is `stream_select()` only, which is what `StreamSelectDriver` uses, but
  worth remembering if phasync ever touches those other call paths directly. Still open PHP-side:
  the pipe/file 64-handle cap itself (`MAXIMUM_WAIT_OBJECTS`) is a genuine Win32 API ceiling this
  PR does not and cannot lift, only makes safe to exceed.

  **Live status update, from the `phpsrcstreamselect` chatter room where the actual work
  happened** (two coordinating sessions, `claude-linux` and `claude-windows`, read directly, not
  summarized secondhand): the PR is **code-complete and rebased onto current php-src master
  (currently tracking 8.7 -- 8.6 has evidently already branched off)**. The Windows half was
  verified twice, not just in CI: build clean on the real `vs18`/VS2026 toolset with
  warnings-as-errors, and separately confirmed on **real Windows hardware**, both new `.phpt`
  passing. CI on the final commit (`2fa18f0f975`) showed one red job
  (`WINDOWS_X64_ZTS`) -- diagnosed, not assumed: a single unrelated failing test
  (`ghsa-9f67-6fw4-hpfp-win32.phpt`, a Windows-reserved-device-name filesystem test whose own
  cleanup leaves a file undeletable), confirmed **also currently red on php-src's own master
  branch**, independent of this PR. Net: nothing in the PR itself is broken. Title/description
  were finalized deliberately as a plain bug-fix framing -- no CVE/security-advisory treatment (an
  explicit call, not an oversight: this is a correctness/reliability bug, not something to
  advertise as an exploitable crash), no AI-attribution footer. **Still not merged** -- code and
  verification are done; it's now waiting on a php-src maintainer's review, which this document
  has no visibility into or control over. Target PHP version still genuinely unconfirmed for the
  same reason as before (unlikely to be backported to an already-released branch), but with
  master now at 8.7, "8.6-or-later" undersells it slightly -- realistically this lands no earlier
  than whatever master is building toward next once reviewed.
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

The real fd numbers still have to come from somewhere -- except for `Process`, which turns out
not to need this at all; see the addendum below, which supersedes what this section originally
proposed for it. For everything else:

- **Arbitrary externally-supplied resources** (the general case: a user's own
  `stream_socket_client()`, a plain `fopen()`): needs the Zend-internals fd extraction described
  above. This is what makes it possible to lift the ceiling for `readable()`/`writable()`/
  `stream()` on resources phasync didn't create, not only ones it did.

*(Superseded plan, kept for the record: this section originally proposed replacing `proc_open()`
with a direct `fork()`/`exec()`/`pipe()` implementation for `Process`, to get real fds "for free"
and to unify with a `CreateProcess`-plus-named-pipe-bridge Windows story, echoing the old, removed
`ProcessWrapper.exe`. The addendum below explains why neither half of that is needed once
php/php-src#14452 lands.)*

### A addendum: PHP core fixed the reason `ProcessWrapper.exe` existed -- no custom process runner needed

Confirmed directly against a local php-src checkout (`/home/frode/dev/php-src-stream-select`):
[GH-16889](https://github.com/php/php-src/issues/16889), fixed by
`b614b4a69ae7bab13c39af2f4a01dea846dfa307` and corrected by
`6972612e1e5bdbae1fe8431a4e45b62f675719d5`, **landed in PHP 8.5.0 only** (`git tag --contains`
against both commits: earliest is `php-8.5.0`; not on the `PHP-8.4` branch).

Before this, `win32/select.c`'s `php_select()` always reported every pipe handle as ready for
read, regardless of whether data was actually available -- the direct reason `stream_select()`
timeouts were useless for process pipes on Windows, and the reason the old, now-removed
`bin/ProcessWrapper[64].exe` existed at all (bridging process pipes to something actually
pollable). The fix makes `php_select()` call `PeekNamedPipe()` and report a read pipe ready only
when it genuinely has data, matching POSIX blocking-select semantics (with a short internal sleep
to avoid busy-looping when nothing's ready).

**The scope limit, precise rather than hand-waved:** it only takes effect when *every* native
handle across `rfds`/`wfds`/`efds` in that one `php_select()` call is a read-pipe present in
`rfds` (`num_read_pipes == n_handles` in the diff). Mix in a write-pipe (writing to a child's
stdin) or a plain file handle in the same call, and that call falls back to the old
always-ready behavior for every handle in it, pipes included. phasync's own
`StreamSelectDriver::tick()` (`src/Drivers/StreamSelectDriver.php:330`) makes one combined
`stream_select($reads, $writes, $excepts, ...)` call per tick -- so this benefits a tick that's
purely waiting on child-process output, but not a tick that's simultaneously waiting to write to
a child's stdin, which is a common `Process` usage shape (pipe to stdin, read stdout/stderr).
Sockets are unaffected either way -- they route through native `select()`/`WaitForMultipleObjects`
interleaving separately from the pipe-handle bucket this fix touches.

**Updated conclusion, now that php/php-src#14452 also covers Windows sockets (see "Prior art"
above): direction A's custom process-launching plan for `Process` is dropped, not just
de-prioritized.** Checked what `PosixProcessRunner` actually does today, rather than assume from
the original plan (`src/Process/PosixProcessRunner.php:338,354`): it already just calls stock
`proc_open()` plus phasync's own `\phasync::readable()`/`writable()`, i.e. stock `stream_select()`
-- it was never a `fork()`/`exec()`/`pipe()` layer, that was only ever this document's *proposed*
replacement. Both reasons that replacement was proposed are now handled inside core itself, not
by phasync:

- `FD_SETSIZE` on POSIX: solved by the `fd_bigset` half of #14452.
- Windows pipe polling: solved by GH-16889 (already shipped, PHP 8.5) for the pure-read case, and
  Windows socket-side `FD_SETSIZE` (relevant to `Process` when a child's IO is proxied over a
  socket rather than a pipe) solved by the growable-`fd_set` half of #14452.
- Real fd extraction for `Process`'s own pipes specifically was never actually blocked in the
  first place -- confirmed earlier that `proc_open()`'s pipes already cast fine via
  `php_stdiop_cast` (plain stdio ops), on both platforms. The FFI/fd-extraction work above is
  motivated by *arbitrary external resources a user hands phasync*, not by `Process`'s own pipes.

So once phasync's floor can assume PHP versions carrying both fixes, `PosixProcessRunner`'s
existing code should work unmodified on Windows too -- likely just a rename (it stops being
POSIX-specific) plus re-enabling the path currently blocked by the `LogicException` on Windows
(`Process::run()`, since 1.1.0). No bespoke `CreateProcess`/named-pipe/socket bridge, no FFI, no
new C layer -- this collapses from an engineering project to a version-floor decision. Two things
that stay genuinely open, not resolved by this:

1. **The exact PHP version floor** -- GH-16889 is confirmed 8.5.0+; #14452 is unmerged with no
   confirmed target (see "Prior art" above for why 8.6-or-later is the realistic assumption).
2. **The pipe/file 64-handle cap on Windows is still real** (`WaitForMultipleObjects`'s own API
   ceiling, not something #14452 lifts, only makes safe to exceed). Only matters if a single
   phasync process watches 65+ concurrent child processes' pipes in the same tick -- an edge case,
   and the same longest-waited-first rotation idea discussed earlier in this document's history
   would be how to lift it in `StreamSelectDriver` itself, if it's ever needed. Not blocking.

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

## Clustering: one primitive, and what it settles

The maintainer's clustering idea, stated plainly: a real multi-process cluster only needs one
primitive -- **string broadcast**, optionally channel-named, one worker sends a string, every
other worker receives it. The reasoning behind why that's enough is the same reasoning behind why
phasync's single-process primitives need no locking at all: nothing preempts mid-operation, so
every in-process data structure is already safe to touch from cooperative code. Cross-process,
the only thing that actually needs a kernel-mediated transport is getting bytes from one process
to another; everything built on top of that byte transport -- typed channels, pub/sub, request/
response, work queues -- is ordinary single-process phasync code in each participating process,
symmetric with how `Subscribers`/`Subscriber` already implement one-to-many fan-out from a single
in-process linked-list chain. A cross-process broadcast primitive is the same shape one level up:
each worker process runs its own `Subscribers`-style fan-out, fed by messages arriving over the
shared transport instead of a local `write()` call.

This directly resolves **D2** (`docs/SEMANTICS.md`): the reason `ReadChannelInterface::read()`
was typed to return `\Serializable|array|string|float|int|bool|null` instead of plain `mixed` was
never settled indecision about serialization mechanics -- it was this exact unresolved question of
whether channels needed to survive a process boundary at all. If cross-process communication
becomes clustering's job, built on a narrow string-broadcast transport with its own explicit
serialization at that layer, in-process channels have no reason to be constrained by
cross-process concerns and can go back to plain `mixed`, matching point 3 of the maintainer's
brainstorm directly. **This still needs the maintainer's explicit go-ahead to mark D2 resolved in
`docs/SEMANTICS.md`** -- flagged here rather than assumed, consistent with how every other
semantic decision this project has made got a conscious sign-off first, not inferred from a
design conversation.

Scope note: designing the actual broadcast transport (shared memory ring buffer? Unix domain
sockets between a supervisor and workers? something else?) is real 2.0.0 design work in its own
right, not settled by this paragraph -- this section only captures *why* the primitive is small
and *what it resolves*, not *how* it's built.

## Narrowing the core (points 5 and 7 together)

The maintainer's "consistent standard library, less redundancy" and "reduce complexity generally"
points are really one checklist: things phasync currently carries that either don't belong in a
*pure, disciplined, single-process async IO library* (the project's own stated 2.0.0 identity) or
will be made obsolete by the other work in this document.

- **`phasync\Psr\*`** -- PSR-7/PSR-17 HTTP message implementations are a general-purpose HTTP
  library concern, not an async-IO-primitive concern. `swerve` is the natural home; phasync
  should depend on an existing PSR-7 implementation like any other consumer, not ship its own.
- **FastCGI** (`phasync\Util\FastCGI\Record`) -- already deprecated in 1.1.0-rc6 for exactly this
  reason (see `CHANGELOG.md`); 2.0.0 is where the deprecation is honored and the code actually
  leaves.
- **The PDO wrapper** (`Wrappers/PDO/MySQL.php`) -- if native IO hooking (direction B above) ships
  for PDO_MySQL/mysqlnd, the hand-written wrapper becomes redundant with the whole point of that
  work: transparent concurrency needing no wrapper class at all. Don't remove it before direction
  B actually covers the same ground, but track it as the wrapper's natural retirement condition,
  not something to maintain in parallel indefinitely.
- **`phasync::fork()`** -- worth a deliberate design pass rather than continuing to be whatever it
  currently is by default: what its contract actually promises once clustering (above) exists as
  the sanctioned multi-process story, so `fork()` and clustering don't end up as two
  half-overlapping ways to get more than one process.

This is a checklist to work through during 2.0.0 design, not a batch of removals to do now --
several items are explicitly gated on other 2.0.0 work landing first (the PDO wrapper on
direction B, `fork()`'s contract on clustering's shape).

## Sequencing

1. 1.1.0 ships first, clean, with the loud-failure fix (`IO-7`) as the extent of what stock PHP
   allows -- no FFI, no new runtime dependency, nothing in this document blocks it. **Done,
   tagged, released.**
2. `Process` on Windows needs no FFI work at all, per the "A addendum" above -- it's now a
   version-floor decision (wait for php/php-src#14452 to land and settle on a target PHP version)
   plus re-enabling and likely renaming `PosixProcessRunner`, not an engineering project. Track
   #14452 to merge; nothing to build in phasync until then.
3. Extend fd extraction to arbitrary external resources (the general `readable()`/`writable()`/
   `stream()` case, for resources phasync didn't create), once the fd-extraction layer has real,
   tested, version-matched coverage for phasync's supported PHP range (8.2 through at least 8.5)
   -- broader than any existing community project's matrix today. Lower priority than originally
   scoped: the maintainer is continuing `stream_select_unlimited` directly, and `Io\Poll` may
   solve the general case for free on PHP 8.6+ regardless (see "What changed since this was first
   written," above) -- worth revisiting once one of those two lands, rather than building a third
   parallel path now.
4. Native IO hooking (`php_stream_ops`, then whichever drivers matter most -- PDO/mysqlnd is the
   obvious first target given it usually already routes through PHP streams) as the 2.0.0
   flagship, built on the same version-matched-bindings foundation, with the reentrancy question
   treated as the real, expected cost of each driver integration rather than something solved
   once for all of them. Unaffected by `Io\Poll` -- that RFC covers readiness polling, not
   blocking-call interception, so this remains FFI/Zend-internals work regardless of its outcome.
5. Independent of the FFI track and safe to do any time: channels typed `mixed` (point 3, pending
   D2 sign-off per the clustering section above), `StringBuffer` max-size option (point 4, a
   small, self-contained addition), and the "narrowing the core" checklist above -- none of these
   need native code and could land in an earlier 2.0.0 alpha than the FFI-dependent items.
6. Clustering's actual transport design (string broadcast, above) is its own dedicated
   design-and-build effort, sequenced independently of the FFI track -- it doesn't need direction
   A or B to exist first.

None of this is a small undertaking. It needs its own dedicated design-and-build session(s), not
something absorbed into a cleanup pass.
