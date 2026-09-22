# phasync semantics (v2 draft)

This is the contract for phasync's core: single-process concurrency on PHP Fibers.
Every rule has an ID so that a test can name the rule it verifies. Where Go has an
established answer, it is the reference, and every deliberate difference is marked.

**Status legend**

| Mark | Meaning |
|------|---------|
| ✅ | Implemented and covered by an existing test |
| ⚠️ | Implemented, but not verified against this rule |
| ❌ | Known divergence from the rule (details given) |
| 🆕 | New rule, not implemented yet |

Statuses come from reading the code and test names, not from running each rule.
Treat ⚠️ as "write the test and find out".

**Decisions still open** are listed at the end (D1 to D17). Rules that depend on one say so.


## 0. Scope

phasync is an async IO and concurrency library for one PHP process.

In scope: the scheduler and driver, coroutines and scopes, cancellation, timeouts,
`select`, channels, `WaitGroup`, `RateLimiter`, flags, non-blocking stream IO, `Process`
pipes, and `StringBuffer`.

Out of scope, and expected to live in other packages: worker pools and cross-process
proxies, serialization, PSR-7 implementations, FastCGI and WebSocket protocol code,
database drivers, HTTP servers. Windows support is deferred, not excluded: the seams
that keep it possible are `DriverInterface` and `Process::run()`.

`StringBuffer` is in core on purpose. It is a protocol-level byte buffer that must stay
as fast as PHP allows, and it may later use shortcuts that a decoupled package could not
(see section 11).


## 1. Principles

**P1. Don't expose the event loop.** A coroutine reads like synchronous code. Suspension
is hidden inside blocking calls (`sleep`, `await`, IO, channel operations).

**P2. Build on the core mechanisms.** Higher-level tools (`WaitGroup`, `CurlMulti`,
channels) suspend and wake through `awaitFlag()` and `raiseFlag()`, so exception
propagation and cancellation work the same everywhere.

**P3. Rely on refcounting; treat cycle collection as bounded cleanup.** Destructors that
run when the last reference is dropped are immediate and deterministic, and may be relied
on (channel ends, flag objects and the primitives' own objects, see FLG-1). Cyclic garbage is freed by the event loop's own
periodic collection, at most every 0.5 s after some coroutine has terminated (RT-1).
Correct behaviour must not depend on cyclic garbage being freed at a particular moment.
❌ See ERR-3.

**P4. Prefer loops to callbacks.** One coroutine with a loop is cheaper and easier to
reason about than a callback per event.

**P5. No global side effects.** phasync must not change process-wide PHP state (GC,
error handlers, ini settings) and must restore anything it touches. ❌ See RT-1.

**P6. Deadlocks are prevented by construction where that is free, detected exactly
where possible, and otherwise the developer's responsibility.** Never guessed with
timers. See section 9.

**P7. Simple beats clever.** Native PHP resources are used directly, not wrapped.


## 2. Scheduling

**SCH-1. Cooperative.** A coroutine runs until it suspends. Suspension points are:
`await`, `sleep`, `yield`, `idle`, `awaitFlag`, `readable`/`writable`/`stream`, channel
and `select` operations, and `preempt()` (which suspends only after the configured
interval). Code that never suspends blocks every other coroutine. ⚠️

**SCH-2. `go()` runs the child immediately.** The child runs synchronously up to its
first suspension, then control returns to the caller. *Differs from Go, where a new
goroutine is queued.* This follows from how Fibers work and is kept. ✅ ExecutionOrderTest

**SCH-3. No spurious wake-ups.** A suspended coroutine resumes only when the condition it
waits for holds, its timeout expires, or an exception is thrown into it. Code does not
need to re-check in a loop. ⚠️

**SCH-4. FIFO.** Coroutines that become runnable in the same tick resume in the order in
which they became runnable. ⚠️

**SCH-5. Operations are atomic between suspension points.** Because scheduling is
cooperative, a sequence of statements that does not suspend cannot be interleaved with
another coroutine. Every rule below relies on this. ⚠️


## 3. Scopes (structured concurrency)

*Go reference: no equivalent in the language; `errgroup` is the closest tool.*

**SCO-1. `run()` opens a scope.** Every coroutine belongs to exactly one scope. A coroutine
created by `go()` joins the creator's scope. ✅ RunTest

**SCO-2. A scope does not exit until all its coroutines have finished.** `run()` returns
or throws only after every coroutine in its scope has terminated. ✅ RunTest

**SCO-3. A failure cancels nothing by itself.** Most requests are one coroutine running
synchronous code, so a server handles failures in the request's own coroutine
(`try`/`catch`/`finally` around `handle()`, log, send a 500, close). An exception nobody
handles propagates up the coroutine tree (ERR-3) and ends the program if it reaches the
top. Stopping related coroutines is the developer's job: catch and cancel them, or let
channel ends close (CHN-8). ✅ Today's "does not cancel siblings" already matches; delivery timing is
ERR-3.

**SCO-4. `run()` throws the scope's failure.** After the scope has drained, `run()`
throws the first unhandled failure. Later failures are not lost (ERR-4). ✅ for the first
failure (RunTest "lost exception in an orphaned go()")

**SCO-5. Nested `run()` does not cascade.** Cancelling a coroutine that is blocked in a
nested `run()` stops that coroutine only. The outer `run()` still waits for the nested
children. ✅ Matches today.

**SCO-6. Detached work is explicit.** `service()` is the only way to start a coroutine
that outlives its scope. It has no scope to fail into, so its unhandled exceptions go to
`logUnhandledException()` and are never silent. ❌ Currently prints "FATAL" to STDERR and
continues.

**SCO-7. A context is any object.** `run()` and `go()` take an optional context object
and `phasync::getContext()` returns the one the current coroutine belongs to, so an
application can keep per-request services in a `WeakMap` keyed by it. phasync tracks the
member coroutines itself and must not keep the object alive after its coroutines finish,
so that such services are freed with the request. Setting up contexts is the job of
servers (FastCGI, HTTP), not of application code. There is no storage API on the
context (D4). ❌ Today it must implement `ContextInterface`, which carries the storage.


## 4. Errors

**ERR-1. `await` rethrows.** `await($fiber)` returns the coroutine's result or throws the
exception it terminated with, every time it is called, for any number of awaiters.
✅ ExceptionHandlingTest ("awaited 2 times")

**ERR-2. An awaited failure is handled.** If at least one coroutine awaited the failed
coroutine, the failure is not additionally reported to the scope. ⚠️

**ERR-3. An un-awaited failure reaches the parent coroutine (or the top-level `run()`).**
It is delivered when the failing coroutine terminates, not at the next context switch of
an ancestor and not when its `Fiber` object happens to be released. ❌ Currently delivered
by `FiberExceptionHolder::__destruct`, i.e. when the `Fiber` is released. That is
immediate, but if application code still holds the `Fiber` (a variable or an array), the
failure is neither thrown nor logged.

**ERR-4. No failure is dropped silently.** If a scope sees more than one failure, all are
retained and reachable from the exception `run()` throws (shape: D3). ❌ Currently the
first is kept and later ones are only logged.

**ERR-5. `finally` always runs**, including on cancellation and on exceptions thrown into
a suspended coroutine. ✅ FinallyTest

**ERR-6. Exceptions are not used for flow control by the runtime**, except
`CancelledException` and `TimeoutException`, which are part of this contract.


## 5. Cancellation

*Go reference: `context.WithCancel`. Cancelling is idempotent and safe on finished work.*

**CAN-1. Delivered at a suspension point.** `cancel($fiber)` causes `CancelledException`
to be thrown from the coroutine's current or next suspension point. ✅ CancelTest

**CAN-2. Cancelling a running coroutine is legal.** If the target is not suspended, the
cancellation is recorded and delivered at its next suspension. ❌ Currently the docblock
says the fiber MUST be suspended and throws otherwise.

**CAN-3. Cancelling a finished coroutine is a no-op.** ❌ Currently throws
`InvalidArgumentException`.

**CAN-4. Delivered once per request.** A cancellation is thrown once. A coroutine that
catches it can keep running, and its cleanup code can suspend without being cancelled
again. (D5)

**CAN-5. `cancel($fiber)` stops that coroutine and nothing else, never its children.**
There is no `cancelContext()` for now (D14). Related coroutines are torn down by channel
ends closing (CHN-8), not by broadcasting a cancellation. ✅ Matches today.

**CAN-6. Cancellation never loses or duplicates data.** A cancelled operation either
completed before the exception, or did not happen. Examples: a cancelled channel write
did not deliver its value; a cancelled channel read did not consume a value. ⚠️

**CAN-7. Cancelling one waiter does not disturb others.** Coroutines waiting on the same
stream, flag or channel keep waiting. ✅ regression fixed in commits c65d5b1 and 575d4b8;
add a dedicated test.

**CAN-8. Cancellation cannot be forced.** A coroutine may catch `CancelledException`. The
scope still waits for it (SCO-2). This is a documented property, not a bug.

**CAN-9. Ending with the cancellation you were given is not a failure.** A coroutine that
terminates with the `CancelledException` it was cancelled with does not fail its scope,
and `run()` does not throw it. *Go, Trio and Kotlin all treat this as normal
termination.* ❌ Verified: cancelling a child that does not catch `CancelledException`
makes `run()` throw `CancelledException`, even though the parent did the cancelling on
purpose. (D12)


## 6. Timeouts

**TMO-1. No implicit timeouts.** Every blocking operation waits forever unless the caller
passes a timeout. ⚠️ Commit 8669da8 says IO operations are an exception (D6).

**TMO-2. A timeout throws `TimeoutException` from that operation** and leaves the object
it was waiting on in a consistent state (same guarantee as CAN-6). ⚠️

**TMO-3. A timeout never fires early, and may fire late.** It fires no sooner than its
deadline. No upper bound is promised: a coroutine that does not suspend delays every
timer (SCH-1), and deadlines are checked coarsely (at most every 0.1 s) on purpose,
because checking more often costs more than it is worth. ⚠️ Measured lateness of a 0.2 s
flag timeout: about 300 ms when the loop is idle or only waiting on quiet streams (the
idle sleep is 0.5 s), about 100 ms while a sibling loops on `yield()` (the check
interval), and about 1 ms while a sibling loops on `sleep(0.05)`. `sleep()` itself is
exact. Whether to tighten the idle case is D11.

**TMO-4. A scope-level deadline is a timeout that cancels the scope.** 🆕 (e.g.
`phasync::timeout($seconds, $fn)`.)

**TMO-5. The clock is injectable.** The driver takes a clock so that tests can advance
time without sleeping. 🆕 This is what makes TMO and DLK tests fast and deterministic.


## 7. Channels

*Go reference: channels and `close()`. Ends are separate objects here.*

**CHN-1. Unbuffered channel is a rendezvous.** `write()` returns only after a reader has
received the value. ⚠️ (code suggests yes; test)

**CHN-2. Buffered channel of capacity N.** `write()` returns immediately while fewer than
N values are queued, and blocks when full. ✅ ChannelsTest "buffered channel tests"

**CHN-3. FIFO, exactly-once.** Values are delivered in write order. Each value goes to
exactly one reader. ⚠️

**CHN-4. `close()` is idempotent.** *Differs from Go, where a second close panics.* ⚠️

**CHN-5. After close, reads drain, then report end-of-stream.** Buffered values are still
delivered after close. Once empty, `read()` reports end-of-stream instead of blocking.
✅ ChannelsTest "reader starts after writer is closed". `read(float $timeout = PHP_FLOAT_MAX,
?bool &$eof = null)` (D1, done) is the signalling shape: `$eof` is `true` only when the
channel is closed with nothing left, `false` otherwise, including when the returned value
happens to be `null` (a legitimately written value). `getIterator()` uses the same parameter,
so `foreach` no longer stops early at a written `null`. ✅ ChannelsTest "foreach ... now gets a
written null too". `Subscriber` (the per-listener pub/sub reader) got the same treatment,
since it implements the same `ReadChannelInterface` contract, and that surfaced two further
bugs in the same family, both fixed: its message chain ends in a self-referencing sentinel
node whose `->message` is an uninitialized default (`null`), which used to be returned as if
it were a real value on the call where the chain transitions to it (✅ ChannelsTest "not
confused with the sentinel end-of-stream node"); and `Subscribers`' internal draining loop
used the same `null === $message && $readChannel->isClosed()` heuristic `read()` itself used
to have, which could misread a published `null` racing with `close()` as end-of-stream and
drop it before it ever reached a subscriber -- now uses `$eof` from the underlying channel's
`read()` instead.

**CHN-6. Writing to a closed channel throws `ChannelException`.** This includes writers
blocked at the moment of close, which wake up and throw. ⚠️ for the blocked writers.

**CHN-7. Closing wakes every blocked reader and writer.** ⚠️

**CHN-8. Dropping one end closes the other.** When the last reference to a reader end is
released, writers throw (CHN-6). When the last reference to a writer end is released,
readers see end-of-stream (CHN-5). This relies on refcount destructors (P3). Cyclic
references may delay it and this is documented. **This is the primary way separate
coroutines are torn down** (maintainer). The aim is to make this guarantee complete
instead of adding cancellation broadcasts. ⚠️ ChannelsTest "channels memory leaking"

**CHN-9. Any PHP value can be sent.** ✅ (2.0.0). Was
`Serializable|array|string|float|int|bool|null`, a restriction left over from not having
decided (D2) whether channels needed to survive a process boundary. Resolved: they don't --
a channel is single-process, in-memory coroutine communication, so there was never a
serialization step to justify the restriction. Cross-process transport is a separate,
later concern (the clustering primitive, `docs/roadmap-2.0.md`).

**CHN-10. No timing heuristics.** `read()` and `write()` do not insert `phasync::sleep()`
calls or time-based "likely deadlock" checks. ❌ See DLK-1.


## 8. `select`

*Go reference: `select`. Go performs the chosen operation itself. phasync returns which
selectable is ready, so the contract below is what makes that safe.*

**SEL-1. Returns one selectable, or `null` on timeout.** `null` after the timeout means
none was ready; a timeout of `0` is Go's `default:` case. ✅ SelectTest "with a timeout"

**SEL-2. Ready at the moment of return.** The returned selectable is ready when
`select()` returns. By SCH-5, an immediate non-suspending operation on it (a channel
read, `StringBuffer::read`, `await` on a finished fiber) cannot block, even if other
coroutines were also interested in it. ❌ Currently a helper coroutine reports readiness
and the selector resumes later, so another coroutine can consume the value in between.
The fix is to re-check `isReady()` on resume and keep waiting if it no longer holds.

**SEL-3. First ready in argument order wins.** *Differs from Go, which chooses randomly.*
Deterministic order makes behaviour testable. Documented risk: an always-ready first
argument starves the others. (D7)

**SEL-4. A failing selectable is selected, not thrown.** If a selected fiber threw, the
exception is observed by the following `await`, not by `select()`. ⚠️ SelectTest "with a
fiber that throws an exception"

**SEL-5. `$read` and `$write` resources select on readability and writability
respectively.** ❌ The `$write` branch calls `readable()` (`phasync.php`, in the `$write`
loop). Reproduced: selecting an already-writable socket returned `null` after the full
timeout.

**SEL-6. No helper outlives the call.** After `select()` returns, throws, or is cancelled,
no coroutine, flag, pooled selector or stream watcher created by it remains. ⚠️ Pooled
selectors are currently returned only for the winner.

**SEL-7. Cancelling the selecting coroutine is safe** and follows CAN-6. ⚠️

**SEL-8. Empty input returns `null` immediately.** ✅ SelectTest "with an empty array"


## 9. Deadlocks

*Go reference: `fatal error: all goroutines are asleep - deadlock!`. The runtime reports
it exactly, when nothing can ever run again, and uses no timers.*

**DLK-1. No time-based deadlock guessing.** No inserted sleeps, no "wait 100 ms and
assume", no library-imposed 30 or 60 second limits. ❌ Present in `Channel::read()`,
`Channel::write()`, `ensureNotCreatorFiber()`, `BufferedStream`, `UnbufferedStream`.

**DLK-2. Await cycles are detected when they form.** `await($a)` inside `$a`'s own wait
chain throws `LogicException`. ✅ EdgeCaseTest "circular dependency deadlock"

**DLK-3. Global stall is detected exactly.** If no coroutine is runnable, and no timer,
stream watcher or child process is pending, yet coroutines are still blocked, nothing can
ever wake them. `run()` throws `DeadlockException` carrying who is blocked on what.
🆕 ❌ Currently `tick()` idles in 0.5 s sleeps forever.

**DLK-4. Partial stalls are not detected.** A subset of coroutines blocked while others
make progress is the developer's responsibility. This is the accepted limit of P6.

**DLK-5. Deadlocks are prevented by construction where free.** Paired ends close each
other (CHN-8). Every primitive that waits on a flag is responsible for waking its waiters
when the last party that could raise the flag is gone. A flag object is protected by
garbage collection only while its waiters do not hold it (FLG-1). ❌ Not true of every primitive today. For example a `WaitGroup` whose
workers all vanish without calling `done()` is held only by its waiter, so its waiter
waits forever.


## 10. Other primitives

**WG-1. `WaitGroup` follows Go.** `add()` (no argument, see D15), `done()`, `await()` (Go's `Wait`); `await()`
returns when the counter reaches zero and returns immediately when it is already zero; a
negative counter throws. ✅ WaitGroupTest

**FLG-1. `raiseFlag($object)` wakes every coroutine waiting on `$object`;** it returns the
number woken. Flags are the fundamental primitive and are protected by garbage collection:
when the last reference to the flag object goes away, every waiter is woken with
`CancelledException('The flag no longer exists')` (`Flag::__destruct`), because nothing can
raise it any more. A waiter never keeps its flag alive. ✅ Fixed (tests in FlagsTest). Three defects
used to prevent it: `awaitFlag()` held its `$signal` parameter for the whole wait,
`flagGraph` held every flag as a `WeakMap` value (it now only holds fibers, which is all
that await-cycle detection follows), and `ObjectPoolTrait::popInstance()` left the popped
object in the pool array, so a reused `Flag` store was never destroyed. Waiting on a flag object that nothing else
references, for example `awaitFlag(new stdClass(), $t)`, is undefined behaviour: it may end
at once with `CancelledException` or later with `TimeoutException` (decided by the
maintainer, nobody has a reason to write it). To wait for a timeout, hold the flag in a
variable.

**RL-1. `RateLimiter`.** Contract to be written; it is not yet specified. 🆕 (D8)

**IO-1. Waiting on a stream.** `readable`/`writable`/`stream` suspend until the resource
is ready. Several coroutines may wait on the same resource, and all are resumed. ✅
regression fixed in 575d4b8

**IO-2. A resource closed while waited on throws `IOException`** in the waiting
coroutine. ⚠️

**IO-3. `preempt()` never changes results,** only when other coroutines get to run. ⚠️

**IO-7. A `stream_select()` failure that isn't a timeout is reported, loudly, to every fiber
that was waiting that tick.** `stream_select()` fails outright (not "nothing is ready yet",
the whole call errors) on a stock POSIX build once any watched resource's real file
descriptor number reaches `FD_SETSIZE` (1024), and this is unrelated to how many resources
phasync itself is watching -- a process's fd numbers climb over its lifetime regardless. Every
fiber that was waiting on stream IO that tick gets `IOException` with PHP's own warning text
verbatim (for example naming `FD_SETSIZE`), not just the one fiber whose resource happened to
be the culprit, since the whole batch failed together. ✅ Fixed (StreamIOTest `IO-7`). Before,
`@\stream_select(...)` suppressed the warning and a `false` result was silently treated the
same as "nothing ready" (`if (false !== $result && $result > 0)`), so every stream-waiting
coroutine in the process hung forever with no trace of why, for as long as any watched
resource kept that high fd number. This does not raise the `FD_SETSIZE` ceiling itself --
see the roadmap in `docs/roadmap-2.0.md` for that.


## 11. `StringBuffer`

`StringBuffer` is a byte buffer for protocol code, where one coroutine appends bytes and
another reads fixed or variable-sized frames. It is fast on purpose, and it is
deliberately not a safe channel (use `Channel` for that).

**BUF-1. `write()` never blocks by default.** There is no backpressure unless the
constructor is given `$maxSize` (2.0.0). With no `$maxSize`, memory is bounded only by
the writer, and the intended pattern is a reader that drains faster than the writer
fills. With `$maxSize`, `write()` blocks once the buffer holds that many unread bytes,
until the reader has consumed enough to make room -- the same wait/timeout shape as
`read()`/`readFixed()` (`TimeoutException` on a real timeout, a `0` timeout never blocks
or throws and instead writes past the limit once).

**BUF-2. `read()` and `readFixed()` block until data is available or the buffer ends.**
At end they return what is left, then empty or `null`.

**BUF-3. `end()` is required.** A writer that stops without `end()` leaves its reader
waiting. The deadman switch (`getDeadmanSwitch()`) is an optional safety net, and its
behaviour is `DeadmanException` from a blocking read, after buffered data has been read.
✅ StringBufferTest

**BUF-4. One reader at a time.** ⚠️ Not enforced or tested. State it or enforce it (D9).

**BUF-5. Shortcuts allowed.** Any change may bypass core APIs (direct fiber handling,
inlined flag logic) as long as BUF-1 to BUF-4 and the tests hold. Performance
regressions are tested by benchmark (see section 13).

**BUF-6. `StringBuffer` is a `SelectableInterface`** and follows SEL-2: `isReady()` is
true when a read would not block, when the buffer has ended, or when it has failed. ✅


## 12. Runtime hygiene

**RT-1. Inside `run()` the loop controls GC, and the caller's setting is restored.**
`gc_disable()` while `run()` is active is deliberate (D10): refcount frees are immediate,
and the loop collects cycles at most every 0.5 s after a coroutine has terminated (P3).
Leaving `run()` must restore the previous state and never enable what the user disabled.
❌ Today `gc_enable()` is unconditional. Known limit: a long-lived coroutine that creates
cycles while no other coroutine terminates is never collected. In a test, 300 000 cyclic
objects grew memory by 119 MB inside `run()`.

**RT-2. No error or exception handlers are installed.**  ⚠️

**RT-3. `run()` may be called at any nesting depth,** inside or outside a coroutine, and
top-level `run()` is the only thing that drives the loop. ✅ RunTest

**RT-4. Unsupported use fails loudly.** `go()`, `await()` and `select()` outside `run()`
throw `LogicException` with a message that names `phasync::run()`. ✅ AwaitTest,
APIOutsideOfRunTest


## 13. Testing rules

1. Every rule above gets at least one test whose name starts with its ID (`SEL-2: ...`).
2. Tests use the injectable clock (TMO-5), `stream_socket_pair()` and in-memory
   resources. No network, no real `sleep` beyond a few milliseconds.
3. Timing assertions compare against the injected clock, never wall-clock ratios.
   (`CurlMultiTest` currently fails this way and is network dependent.)
4. `select`, cancellation and channel close get stress tests with randomised interleavings
   (seeded, so failures reproduce).
5. `StringBuffer` keeps a benchmark that CI compares against a stored baseline.
6. Every ❌ in this document becomes a failing test first, then a fix.
7. `tests/AllroundTests.php` is not run today (the suite only matches `*Test.php`), so
   rename it or fold it into the per-rule tests.
8. **Pin first, then change.** `tests/Characterization/` pins today's behaviour and
   `benchmarks/bench.php` keeps a performance baseline. A change that breaks a
   characterization test or slows a benchmark noticeably is reported to the maintainer
   before it is accepted. See `tests/Characterization/README.md`.


## 14. Process

`phasync\Process\Process::run()` launches a background process and returns a
`ProcessInterface` for interacting with its STDIN, STDOUT and STDERR. On POSIX it returns a
`PosixProcessRunner`; on Windows it throws. Windows support is dropped for 1.1.0 and will
return, rebuilt, in 2.0.0 (section 0, `docs/roadmap-2.0.md`, issue #45). These rules describe
`PosixProcessRunner`.

**PRC-1. A command that cannot be launched is detected before spawning, the same way on every
platform.** `Process::run()` resolves the command itself, the way `exec()` would: a literal
path if it contains a slash (resolved against `$cwd` if one is given), otherwise a search
through `PATH` (`$env['PATH']` if `$env` is given, otherwise the inherited one), and throws
`RuntimeException` if nothing executable is found, before `proc_open()` is called at all. ✅
Fixed. This replaced relying on `proc_open()`'s own error reporting, which is not portable:
whether it *reports* a missing executable synchronously depends on the platform's glibc
version and how PHP was built against it (glibc ≥2.24 added synchronous exec-failure
reporting to `posix_spawn()`, over a pipe). Verified failing two different ways on two
machines before the fix (ProcessTest `PRC-1`). A `$env` given without a `PATH` entry is not
pre-checked, since the platform's own fallback search path cannot be predicted from here;
whatever `proc_open()` and the platform actually do is what happens (`surprise`). A second,
best-effort layer checks the child's status once, non-blockingly, right after a successful
`proc_open()`, to catch what neither the resolution check nor `proc_open()` itself can: the
target existed and was executable, but `exec()` still failed inside the child, for example a
`#!` interpreter line pointing at an interpreter that does not exist.

**PRC-2. No shell is involved at any point.** Arguments reach the child exactly as given, with
no metacharacter expansion and nothing to escape, and a bare command name is found the way
`execvp` finds it, not the way a shell would. ✅ Verified: a spawned process's immediate parent
is `php` itself, never a shell, and an argument containing `$HOME && echo x` arrives at the
child unexpanded, character for character. STDOUT and STDERR are separate streams
(`read(ProcessInterface::STDOUT)` / `read(ProcessInterface::STDERR)`), and `read()` behaves
the same inside and outside a coroutine: inside, it suspends and lets other coroutines run;
outside, it blocks.

**PRC-3. The exit code is available once the process has stopped running, not before.**
`getExitCode()` returns `false` while the process runs and the real exit code once it has
exited on its own; after `stop()` or a delivered signal ended it, it returns `-1` (PRC-5). ✅

**PRC-4. `write()` feeds STDIN; `getStream($fd)` exposes the STDIN, STDOUT and STDERR pipes
directly**, as non-blocking stream resources, for the three standard descriptors, and `null`
for anything else. ✅

**PRC-5. `stop()` sends SIGTERM and returns once the process is gone, is idempotent, and
works both inside and outside a coroutine.** Once a process has stopped or been signalled
away, `write()` and `sendSignal()` become no-ops returning `false`, and a later `stop()`
returns `true` immediately. ✅

**PRC-6. Once an exit has been observed, the pipes are closed, so output that was never read
is lost and a later `read()` throws `IOException`.** ❌ `isRunning()` and `getExitCode()` poll
and close the pipes as soon as the process is seen to have terminated, whether or not
anything had read from them yet. A caller that checks `isRunning()` before draining output can
lose it silently.


## Findings from the characterization tests

The tests in `tests/Characterization/` pinned today's behaviour. Where they disagree with a
⚠️ above, this table wins. The `IDs` marked *new* are proposed rules with no text above.
Every row is pinned by a test in group `divergence` or `surprise`, so fixing it makes a test
fail on purpose and is reported before it is accepted.

| ID | Behaviour today | Contract expects |
|----|-----------------|------------------|
| SCH-3 | A coroutine waiting for readability is resumed when the resource only becomes writable | No spurious wake-ups |
| SCH-5 | `go()` suspends its caller once the preempt interval has elapsed, using the process-wide `$lastPreemptTime`, so it depends on wall-clock time | `go()` returns to the caller without depending on the clock |
| SCO-3 | An un-awaited child failure does not cancel siblings or interrupt the parent. `run()` throws at the end and the parent's return value is lost | No cascade (decided). Delivery timing is ERR-3 |
| SCO-5 | Cancelling a coroutine blocked in a nested `run()` does not cancel the nested children | No cascade (decided). Already matches |
| SCO-7 | Context storage: a missing key gives a "returned by reference" notice, object keys throw `Error`, `isset` with a null key throws `TypeError` | (supports D4, removal) |
| ERR-3 | Un-awaited failures surface only when `run()` ends, and are lost if the failing `Fiber` is still referenced then | Delivered when the coroutine terminates |
| ERR-4 | Later failures are only logged, and a child failure is dropped when the main coroutine also fails | None dropped |
| CAN-2, CAN-3 | Cancelling a running coroutine throws `RuntimeException`; a finished one throws `InvalidArgumentException` | Recorded, or no-op |
| CAN-5 | There is no way to cancel a whole context | Deferred (D14). Teardown comes from channel ends (CHN-8) |
| CAN-9 | `run()` throws the `CancelledException` of a child the parent cancelled on purpose | Not a failure (D12) |
| CAN-10 *new* | Cancelling twice before the child resumes delivers only the second exception; the first escapes from `run()` even when the child caught the second | Every cancel is handled once, none escapes |
| TMO-6 *new* | `checkTimeouts()` skips about half of the expired waiters per pass: ten simultaneous 0.2 s timeouts fire at 0.5, 1.0, 1.5 and 2.0 s, and equal timeouts wake in the order 0, 2, 1, 3. Plain PHP shows `SplObjectStorage` skipping every other entry when entries are removed during `foreach`, which matches. Verified in a scratch copy: iterating a snapshot of `pending` makes all ten fire in the same pass. The loop is unchanged since the first release, and nothing in the history says the skipping is deliberate | **Fixed:** all expired waiters fire in the same pass, in registration order |
| TMO-7 *new* | A zero or negative timeout waits for the next timeout check, so its delay depends on when the last check ran (0 to about 0.5 s) | Fires promptly |
| TMO-1 | `readable()` and `stream()` apply the default timeout | Infinite unless given (D6) |
| DLK-1 | Creator-fiber heuristic (100 ms) and an extra `sleep()` in `Channel::read()` and `write()` | Removed |
| DLK-3 | A global stall is not detected | `DeadlockException` |
| CHN-1 | An unbuffered `write()` returns before any reader has read, and its timeout is ignored; `isReadyForWrite()` looks inverted | Rendezvous |
| CHN-5 / D1 | `foreach` over a `ReadChannel` stops at a written `null` and leaves the rest unread | **Fixed:** `read()` takes `?bool &$eof = null`; `getIterator()` uses it. Also fixed in `Subscriber`/`Subscribers` (two further bugs found in the same family, see CHN-5) |
| CHN-9 / D2 | `stdClass`, `Closure`, `DateTime` and resources throw `TypeError` | **Fixed:** `read()`/`write()` are `mixed`. Resolved by recognizing cross-process transport as a separate concern (clustering, `docs/roadmap-2.0.md`), not something in-process channels need to be typed around |
| WG-1 | `add()` takes no argument, so `add(3)` silently counts as 1 (PHP ignores extra arguments). Not a bug, a difference from Go | **Fixed** (D15): `add(int $delta = 1)` |
| RL-1 | `RateLimiter::await($timeout)` ignores the timeout; dropping a limiter with an untaken token makes `run()` throw `ChannelException` | Timeout honoured; no throw |
| FLG-1 | A flag freed while waited on does not wake its waiters: `awaitFlag()`'s parameter, the `flagGraph` value and `ObjectPoolTrait::popInstance()` keep it alive or keep its store undestroyed | Waiters woken with `CancelledException` when the flag is freed **Fixed**, see FlagsTest |
| SEL-2 | Two selectors on one channel both get it back ready; the second `read()` then times out | Ready at the moment of return |
| SEL-5 | `$write` on a writable-but-not-readable socket returns `null` after the full timeout; on a readable one it returns at once | Selects on writability |
| SEL-6 | Helpers of a finished or timed-out `select()` stay in the driver until `gc_collect_cycles()`; only the winner's `ClosureSelector` goes back to the pool | No helper outlives the call |
| SEL-1 | `select(..., 0)` is not prompt (0 to about 0.5 s). A closure over an already-terminated fiber throws while the bare fiber works | Prompt; consistent |
| BUF-2 *new* | `StringBuffer::read($n, $timeout > 0)` on an empty buffer **hangs the whole process** once the timeout expires: `await()` returns at once and `read()` loops without suspending | **Fixed:** `read()` throws `TimeoutException` at the deadline. An infinite wait with no writer still blocks, and an unread buffer still grows without limit, by design (maintainer) |
| BUF-1 *new* | `readFromResource()`'s own backpressure loop (`while (totalWritten - totalRead > $bufferSize) { $this->await(); }`) never actually blocks: `$totalRead` was only ever decremented (by `unread()`), never incremented by `read()`/`readFixed()` consuming data, so the gap never closes; and `await()`/`isReady()` return at once whenever *any* data is buffered, which is already true here. Past 1 MB net, this is an unyielding busy loop, not backpressure -- found while building `$maxSize` (2.0.0), unexercised by any existing test (largest prior test: 100 KB) | **Fixed:** `read()`/`readFixed()` now increment `$totalRead` and raise the flag on every consumption; the loop waits directly on the flag instead of through `await()`, matching the pattern `readFixed()` already used for its own "not just any data, *enough* data" wait |
| IO-4 *new* | `readable()` or `writable()` on `php://memory` makes `run()` throw `ValueError: No stream arrays were passed` | Documented or handled |
| IO-5 *new* | Outside a coroutine, `stream()` on a non-blocking stream ignores its timeout and throws `TimeoutException` after about 1 s (inverted loop condition); a timeout under 1 s loops until data arrives | Honours the timeout |
| IO-6 *new* | `io::fgets` can return partial lines, and `io::fgetc` never waits | Same result as the PHP function, without blocking the loop |
| PRC-1 | A command that could not be launched used to be reported inconsistently across platforms, because it relied on `proc_open()`'s own (glibc-version-dependent) error reporting | **Fixed:** resolved and checked before `proc_open()` is called, the same way on every platform |
| PRC-6 | Once `isRunning()` or `getExitCode()` has seen the process exit, the pipes are closed and unread output is lost | Output remains readable |
| RT-1 | `gc_enable()` is called unconditionally at exit even if the user had disabled GC; a channel end in a reference cycle is not released by `unset()` until the loop next collects (deliberate, P3) | Previous state restored |
| RT-5 *new* | `logUnhandledException()` passes `$exception->getCode()` to `error_log()` as the message *type*, so a code of 1 would send email | Fixed message type |
| RT-6 *new* | `go(run: true)` outside a coroutine returns a Fiber that never started (`getReturn()` throws `FiberError`, `await()` throws `LogicException`) | Returns a usable result |

Not covered: `await()` on a still-pending fiber from outside a coroutine cannot be reached
through the public API.


## Open decisions

| # | Question | Options | My recommendation |
|---|----------|---------|-------------------|
| D1 | `ReadChannelInterface::read()` / `Channel::read()`: `write(null)` is accepted today (the value union includes `null`), so a legitimately-written `null` and "channel closed" are indistinguishable, and `ReadChannel::getIterator()`'s `while (null !== $this->read())` truncates `foreach` at the first written `null` (pinned, `CHN-5`) | keep as is; throw on close; `read(float $timeout = PHP_FLOAT_MAX, ?bool &$eof = null): mixed` | **Decided (maintainer) and done:** the `&$eof` out-parameter (inspired by C#'s `out` pattern; `feof()`-style naming). `getIterator()` uses it, fixing `CHN-5` for free with no second method. `Subscriber`/`Subscribers` got the same fix and two further bugs it surfaced in that class specifically (see CHN-5) |
| D2 | Which values can a channel carry? Was `\Serializable\|array\|string\|float\|int\|bool\|null` | keep the union; any `mixed` | **Decided (maintainer) and done (2.0.0):** `mixed`. The restriction existed only because cross-process channel support was undecided; resolved by recognizing that as a separate concern -- a future clustering primitive (string broadcast, `docs/roadmap-2.0.md`) owns cross-process transport with its own explicit serialization at that layer, so in-process channels have no reason to be constrained by it. PHP ZTS builds remain a separate, still-open question, orthogonal to this |
| D3 | Shape of multiple failures in one scope | first only (Go's `errgroup`); `AggregateException` holding all | `AggregateException`, with the first failure as the primary |
| D4 | Keep `ArrayAccess` context storage? | keep; remove; replace with a small explicit coroutine-local API | **Decided (maintainer):** `ContextInterface` and its storage go. A context is any object, optional on `run()`/`go()`. Callers keep their own `WeakMap<context, service>`, so `getContext()` is the only lookup needed |
| D5 | Cancellation delivery | once per request (edge); every suspension while cancelled (level, as in Trio) | **Decided (maintainer):** cancellation is one exception thrown into the coroutine. Cleanup is done by catching it. No shielding |
| D6 | Default timeout for IO operations | infinite everywhere; keep a finite default for IO | Infinite, stated in one place. A finite default is a hidden timer |
| D7 | Choice order when several selectables are ready | argument order; random like Go | Argument order, for testability |
| D8 | `RateLimiter` semantics | token bucket; leaky bucket; something else | Write down what it does today, then test it |
| D9 | `StringBuffer` readers | one reader, enforced; several allowed | One reader, enforced by a cheap check |
| D10 | GC handling in `run()` | leave GC on; keep manual control but restore state | **Decided (maintainer):** keep manual control, it is deliberate design. Still open: restore the user's previous GC state on exit, and whether a long-lived coroutine that never ends needs a collection trigger |
| D13 | Should there be a guard helper for coroutines that own helpers? | none; a small `phasync::guard(Fiber ...$fibers)` object | **Decided (maintainer): no.** A guard would be a symptom of channels and other primitives not guarding their dependants. Teardown comes from channel ends closing (CHN-8) |
| D17 | `readFixed($n, $timeout)` returns `null` on timeout, and also `null` at end-of-stream with too little data. `read()` now throws `TimeoutException` | keep `null`; throw `TimeoutException` like `read()`; distinguish the two cases | **Decided (maintainer) and done:** throws `TimeoutException` on a real timeout, matching `read()`. Matches `read()`'s other rule too: `$timeout` of exactly `0` is a non-blocking poll and never throws, only a real positive timeout that expires does. Only the genuine "buffer ended with too little data" case (`$this->ended`, no timeout involved) still returns `null` |
| D16 | Should `StringBuffer` get an optional maximum length? | none; opt-in `?int $maxLength = null` that makes `write()` throw when the unread bytes would exceed it | **Deferred to 2.0.0 (maintainer).** Opt-in, throwing, default unbounded remains the shape if it happens |
| D15 | Should `WaitGroup::add()` take a delta like Go's `Add(delta int)`? | keep `add()` with no argument; `add(int $delta = 1)` | **Decided (maintainer) and done:** `add(int $delta = 1)`. A `go(Closure)` method like Go's `WaitGroup.Go` may come later |
| D14 | `cancelContext($context)` | implement; defer | **Deferred (maintainer).** Broadcasting a cancellation would hide the fact that the deadlock protection does not tear things down correctly. If it is ever added, it should skip the calling coroutine |
| D12 | Does a cancelled child that ends with `CancelledException` fail its scope? | yes (today); no, treat as normal termination | No. Otherwise every deliberate `cancel()` needs a `try/catch` in the child to keep `run()` from throwing |
| D11 | How long may an idle loop sleep past the earliest pending timeout? | keep the fixed 0.5 s idle sleep; cap the idle sleep at the earliest pending deadline | Keep the 0.1 s check as is. Cap only the idle sleep, computed only when nothing is runnable, so it costs nothing while busy |
