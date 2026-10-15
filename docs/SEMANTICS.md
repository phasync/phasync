# phasync semantics (v2 draft)

This is the contract for phasync's core: single-process concurrency on PHP Fibers.
Every rule has an ID so that a test can name the rule it verifies. Where Go has an
established answer, it is the reference, and every deliberate difference is marked.

In 2.x, contexts were replaced by two separate things: the ambient `\Scope`
(fiber-interop/scope) is the identity of an operation, and `phasync\Frame` is the lifetime of
a `run()` (section 3). In older issues, a "root context" is now a scope, `withContext()` is a
nested `run()`, and `cancel($context)` is `$frame->cancel()`. `AggregateException` is kept, with
a new shape (ERR-4).

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

In scope: the scheduler and driver, coroutines, frames and scopes, cancellation, timeouts,
channels, `WaitGroup`, `RateLimiter`, flags, non-blocking stream IO, `Process`
pipes, and `StringBuffer`.

Out of scope, and expected to live in other packages: worker pools and cross-process
proxies, serialization, PSR-7 implementations, FastCGI and WebSocket protocol code,
database drivers, HTTP servers. Windows support is deferred, not excluded: the seams
that keep it possible are `PollerInterface` and `Process::run()`.

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
collection, only when the loop has a spare moment: nothing runnable and a `poll(0)` finds no
ready I/O, at most once per `GC_MIN_INTERVAL` (0.5 s). A loop kept busy by always-runnable
coroutines never has one, so as a safety net it collects once `GC_MAX_INTERVAL` (0.5 s) has
passed while there is anything to collect; until then its garbage stays in memory (RT-1).
A collection that frees under 1% of its possible roots doubles both intervals for the next
one, up to 8 times (4 s); one that finds garbage puts them back: collecting costs per root,
and in a long-lived server most roots are its own live structures.
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
`await`, `sleep`, `yield`, `awaitFlag`, `readable`/`writable`/`stream` and channel
operations (with phasync-ext also blocking PHP functions). Code that never suspends blocks
every other coroutine. ⚠️

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
another coroutine of its scope. Every rule below relies on this. ⚠️

**SCH-6. Preemption (with phasync-ext) switches only between scopes, and only between
loop iterations.** A coroutine that runs a whole `EventLoop::PREEMPT_INTERVAL` (20 ms; the
`PHASYNC_PREEMPT_INTERVAL` constant, defined before the loop starts, overrides it) in a PHP
loop yields, so that timers, I/O and other requests get their turn. It is never preempted:
- at any point other than between two iterations of a PHP loop;
- in PHP code called by a C function (callbacks, handlers, destructors), or in
  `#[\phasync\Uninterruptible]` code or what it calls;
- in phasync's or swerve's own code, the event loop's tick included, or in a `Fiber` that a
  coroutine runs itself.

Its scope (the `Scope` current in it when it was preempted, a request for instance) stays frozen
until it resumes: no other coroutine of that scope runs before it, so from inside a request,
SCH-5 holds unchanged. Coroutines of other scopes run meanwhile. Coroutines of that scope that
became ready meanwhile run right after it, unless it was preempted again. A coroutine preempted
before its first suspension returns control to the `go()` that started it; if that caller is of
the same scope, it waits too, so `go()` returns once the scope thaws. Across scopes,
a sequence of statements without a loop and without a suspension point is atomic: code can build
its own atomic operations (test-and-set, compare-and-swap) in plain PHP. Without phasync-ext
there is no preemption. ✅ PreemptionTest

**SCH-7. `idle($after)` wakes one waiter per idle moment, oldest first.** It suspends until
the ready queue and the callback queue are both empty and an immediate poll finds no I/O
ready, for at least `$after` seconds; a busy coroutine that always has something runnable
(a `yield()` loop, for instance) delays it indefinitely. With several waiters, each idle
moment resolves only the oldest registration, checked against its own `$after`; the loop then
measures the next idle moment from scratch for whoever is waiting next, which is what lets an
admission gate call it to let one more in and re-measure, rather than waking everyone at once
like `afterNext()`. Cancelling a waiting coroutine removes it from the queue, so it does not
consume an idle moment that was meant for the next waiter. ✅ IdleTest


## 3. Frames and scopes (structured concurrency)

*Go reference: no equivalent in the language; `errgroup` is the closest tool.*

Two separate things. A frame (`phasync\Frame`) is the lifetime: which `run()` waits for a
coroutine, and what a cancellation or a deadline reaches. A scope (`\Scope`) is the identity:
which operation (a request, a job) the code belongs to. SCO-1 to SCO-6 are about frames, SCO-7
about scopes.

**SCO-1. `run()` creates a frame.** Every coroutine belongs to exactly one frame, except a
service, which has none (SCO-6). A coroutine created by `go()` joins the creator's frame: only
`run()` and `service()` start code outside it. `phasync::frame()` returns the frame of the
running coroutine, that of the innermost `run()` it is in; it throws `LogicException` outside a
coroutine and in a service. ✅ ScopesTest, ScopeFrameTest

**SCO-2. A frame does not end until all its coroutines have ended.** `run()` returns or throws
only after every coroutine of its frame, and of the frames nested in it, has ended. ✅ RunTest,
ScopesTest

**SCO-3. A failure nobody awaits cancels nothing.** The other coroutines of the frame, and
`$fn`, go on; `run()` throws the failure once they have all ended (SCO-4). Awaiting the failed
coroutine before `run()` ends counts as handling it (ERR-2). With an exception handler on the
frame (`Frame::setExceptionHandler()`), every failure nobody awaits goes to it instead, and
`run()` does not throw it. The handler runs in the event loop, outside any coroutine, and must
not wait; what it throws, `run()` throws. Code that wants the rest stopped on a failure cancels
the frame itself (`phasync::frame()->cancel()`, CAN-5). phasync logs nothing. *Differs from
Trio's nurseries and Kotlin's `coroutineScope`, which cancel the siblings on the first failure.*
✅ ScopesTest, ScopeFrameTest

**SCO-4. What `run()` throws.** Once every coroutine of its frame has ended, `run()` throws
every failure: what `$fn` threw and each failure nobody awaited. One is thrown as it is; several
in an `AggregateException`, `$fn`'s first, then the others by the time they happened (not by when
they became known) (ERR-4). Without failures it returns what `$fn` returned. The cancellations its coroutines end with are no failures
(CAN-9); anything else they throw is. A nested `run()` throws into the coroutine that called it,
which can handle it. ✅ ScopesTest, ScopeFrameTest

**SCO-5. A nested `run()` is a child frame.** Inside a coroutine, `run()` runs `$fn` inline in
the calling coroutine (no new coroutine), in a new frame nested in the caller's; the coroutines
`$fn` starts join it, and the call returns once they have ended. `phasync::finally()` callbacks
registered inside it run as `$fn` returns (ERR-5). Cancelling the coroutine blocked in a nested
`run()` cancels the nested frame's coroutines too, waits for them to unwind (shielded), and then
rethrows the cancellation in that coroutine. ✅ ScopesTest "SCO-5: cancelling a coroutine blocked
in a nested run() cancels the nested frame's children too", CancellationTest "CAN-5", NestedRunTest.
Before the 2.x redesign the nested coroutines went on; that divergence is gone.

**SCO-6. Detached work is explicit.** `service()` is the only way to start a coroutine that
outlives its frame. It returns the coroutine, runs it in the root scope (`Scope::getRootScope()`)
whatever scope started it, and gives it no frame. No `run()` waits for a service: when the
outermost `run()`'s frame has no coroutine left, it cancels the services and returns once they
have ended. A service's failure nobody awaits goes to the outermost `run()`'s frame: its
exception handler, or `run()` throws it. ✅ ScopesTest, ScopeFrameTest

**SCO-7. The scope is the identity, the frame the lifetime.** The operation code belongs to is
the ambient `\Scope` of the Composer package `fiber-interop/scope` (the native ext-scope, or its
userland polyfill). phasync requires that package and does not declare `\Scope`.
`Scope::getCurrent()` returns the current scope; `Scope::getRootScope()` is the one for "no
operation". `go()` runs the new coroutine in the scope current where it is called, and the
coroutine keeps it across waits. A coroutine that suspends inside `$scope->run(...)` finds that
scope again when it resumes; other coroutines don't see it. `run()` does not change the current
scope, services run in the root scope, and queued loop callbacks run in the scope they were
queued from. A frame neither creates nor changes a scope: one scope may span several frames, and
one frame hold coroutines of several scopes.

Per-request state is kept in a `WeakMap` keyed by `Scope::getCurrent()`, and is freed with the
scope. State that PHP keeps globally (a locale, a static of a library that is not scope-aware) is
swapped with `phasync::onScopeChange($fn)`: `$fn(Scope $new, ?Scope $old)` is called before a
coroutine runs, on start and on resume, when its scope differs from the one the loop last made
current; never between coroutines of one scope. The hooks run in the loop, outside any coroutine,
and must not wait (a wait throws `LogicException`); an exception from one ends `run()`. They stay
registered for the life of the loop. Entering or leaving `$scope->run()` inside a coroutine calls
nothing: the code doing it makes the change itself, except a nested `run()` entered in another
scope (`$scope->run(fn () => phasync::run($fn))`, a server's request), which makes it current, and
calls the hooks, before `$fn` runs. Under the polyfill, phasync activates the
polyfill's per-scope superglobals (`$_GET`, `$_POST`, `$_COOKIE`, `$_FILES`) whenever the loop
switches to a coroutine of another scope. Without the phasync extension a generator runs in the
scope of the code iterating it (a limit of the polyfill). ✅ ScopeFrameTest, ScopesTest "SCO-7",
NestedRunTest "onScopeChange() keeps a value of its own per scope". With the extension, a
generator keeps the scope it was created in and the hooks fire for it. 🆕


## 4. Errors

**ERR-1. `await` rethrows.** `await($fiber)` returns the coroutine's result or throws the
exception it terminated with, every time it is called, for any number of awaiters.
✅ ExceptionHandlingTest ("awaited 2 times")

**ERR-2. An awaited failure is handled.** If at least one coroutine awaited the failed
coroutine before its `run()` ended, the failure is not reported to the frame. ✅ ScopeFrameTest
"an awaited failure is not rethrown by run()"

**ERR-3. An un-awaited failure reaches its frame (SCO-3).**
It is known when the failed coroutine's `Fiber` object is released (anyone holding it may
still await it), and at the latest when its `run()` ends: a `run()` owns its coroutines, so a
`Fiber` still referenced then (by the application, or by a debugger such as Xdebug) doesn't keep
the failure from reaching it. Such a coroutine can't be awaited afterwards (`LogicException`).
✅ ErrorsTest

**ERR-4. No failure is dropped: one is thrown as it is, several in an `AggregateException`.**
Without a frame exception handler, `run()` counts what `$fn` threw and every failure nobody
awaited. With one, it throws that exception unchanged. With more, it throws
`phasync\AggregateException`: `getExceptions()` has all of them, `$fn`'s first, then the others by
the time they happened; its message lists each with class, message, file and line, so an
uncaught one shows them all; `getExceptions($class)` and `contains($class)` find failures of a
class. None is picked over the others: with several siblings failing, which failed first is a
matter of timing, and picking one could hide the one that matters (a database failure behind a
cache timeout). With `Frame::setExceptionHandler()`, the handler gets each coroutine's failure
on its own, as it becomes known, and `run()` throws only what `$fn` threw (D3).
✅ ErrorsTest, ScopeFrameTest

**ERR-5. `finally` always runs**, including on cancellation and on exceptions thrown into
a suspended coroutine; in a cancelled coroutine, a wait inside it throws again (CAN-4).
✅ FinallyTest. `phasync::finally()` callbacks run when the coroutine
ends, or, when registered inside a `run()` nested in a coroutine, as `$fn` returns, before the
run waits for the coroutines `$fn` started, also when `$fn` threw: in the calling coroutine,
still in the nested frame, and able to suspend, also when it was cancelled (CAN-4).
✅ NestedRunTest

**ERR-6. Exceptions are not used for flow control by the runtime**, except
`CancelledException` and `TimeoutException`, which are part of this contract.


## 5. Cancellation

*Go reference: `context.WithCancel`. Cancelling is idempotent and safe on finished work.*

**CAN-1. Delivered at a suspension point.** `cancel($fiber)` causes `CancelledException`
to be thrown from the coroutine's current or next suspension point. ✅ CancelTest

**CAN-2. Cancelling a running coroutine is legal; `cancel()` never throws in the caller.** A
coroutine that cancels itself (or a frame it is in) finishes its current step and meets the
exception at its next wait. Any other coroutine that isn't waiting meets it at its next wait;
one that was preempted too, never at the preemption itself (SCH-6). (#110) ✅ CancellationTest,
CancelTest

**CAN-3. Cancelling a finished coroutine is a no-op.** ❌ Currently throws
`InvalidArgumentException`.

**CAN-4. Cancellation is sticky.** Once a coroutine or a frame is cancelled, every wait
in it throws the cancellation again, until the coroutine ends or leaves the frame (a nested
`run()` returning). A wait in a `catch` or `finally` block is cancelled too.
`phasync::finally()` callbacks are shielded: their waits aren't, so cleanup that must do I/O
completes, bounded by that I/O's own timeouts. Timeouts are not cancellations: a
`TimeoutException` is thrown once. (D5, revised) ✅ CancellationTest

If that repeated `CancelledException` is what ends up terminating the coroutine, phasync warns
(`E_USER_WARNING`): cleanup in a `finally` block was cut short, and points at
`phasync::shielded()` for cleanup that must wait. A `finally` that catches the repeat and
finishes anyway, or that waits inside `shielded()`, does not warn; nor does a cancellation with
no `finally` wait at all. ✅ CutShortCleanupTest

**CAN-5. `cancel($fiber)` stops that coroutine and nothing else, never its children;
`$frame->cancel()` stops the frame's.** `phasync::cancel()` takes a `Fiber` only.
`Frame::cancel()` cancels every coroutine of the frame and of the frames nested in it, the
deepest in the coroutine tree first; the calling coroutine, if it is in the frame, meets it
at its next wait (CAN-2). A cancelled coroutine blocked in a nested `run()` takes that nested
frame's coroutines with it (SCO-5). (D14) ✅ ScopeFrameTest "$frame->cancel() cancels every
coroutine of the frame and of the frames nested in it", ScopesTest "SCO-7: Frame::cancel()
reaches the deepest coroutines first", CancellationTest "CAN-5"

**CAN-5a. Cancellation can be requested without an exception.**
`Frame::requestCancellation()` sets a flag on the frame and every frame nested in it, those
entered later included, which `Frame::isCancellationRequested()` reads; it throws nothing and
wakes nothing. `Frame::cancel()` and a passed `setTimeout()` deadline set it too. (#110) ✅
ScopeFrameTest

A `run()`'s own `$fn`, having started the frame's other coroutines, can call
`phasync::frame()->requestCancellation()` and return normally to end them and itself cleanly:
the others see `isCancellationRequested()` and end, and `run()` returns what `$fn` returned once
they have. `phasync::frame()->cancel()` from inside the frame instead makes `run()` throw
`CancelledException` (its own wait for the frame's coroutines is the next wait), which is right
when the run as a whole is being cancelled, rather than ending itself on purpose.
✅ Frame.requestCancellation example "2-a-session-tearing-itself-down"

**CAN-6. Cancellation never loses or duplicates data.** A cancelled operation either
completed before the exception, or did not happen. Examples: a cancelled channel write
did not deliver its value; a cancelled channel read did not consume a value. ⚠️

**CAN-7. Cancelling one waiter does not disturb others.** Coroutines waiting on the same
stream, flag or channel keep waiting. ✅ regression fixed in commits c65d5b1 and 575d4b8;
add a dedicated test.

**CAN-8. Cancellation cannot be forced, but can't be ignored while waiting.** A coroutine
may catch `CancelledException` and finish its work, and its `run()` waits for it (SCO-2); any
further wait throws again (CAN-4).

**CAN-9. Ending with the cancellation you were given is not a failure.** A coroutine that
terminates with the exception it was cancelled with (the default `CancelledException` or one
passed to `cancel()`) is no failure of its frame, and `run()` does not throw it; awaiting it
still throws it. *Go, Trio and Kotlin all treat this as normal termination.* (D12)
✅ CancellationTest


## 6. Timeouts

**TMO-1. No implicit timeouts.** Every blocking operation waits forever unless the caller
passes a timeout, IO operations included (D6).

**TMO-2. A timeout throws `TimeoutException` from that operation** and leaves the object
it was waiting on in a consistent state (same guarantee as CAN-6). ⚠️

**TMO-3. A timeout never fires early, and fires at most about 10 ms late** while the loop
runs. Deadlines are kept in 10 ms slots (rounded up) and delivered once their slot has passed;
checking costs one integer comparison per tick, and only coroutines whose timeout expired are
looked at, however many wait. An idle loop wakes at the next slot while a timeout waits (D11).
A zero or negative timeout (a deadline already past) throws `TimeoutException` at once,
without waiting. A coroutine that does not suspend still delays every timer (SCH-1). `sleep()`
is exact.
✅ TimeoutsTest

**TMO-4. A frame-level deadline is a timeout that cancels the frame.**
`phasync::frame()->setTimeout($seconds)`: when the deadline passes, the frame is cancelled with
a `CancelledException` whose previous exception is a `TimeoutException`, and `run()` throws that
`TimeoutException` instead of the cancellation. A `$fn` that catches the cancellation and returns
makes `run()` return normally. Calling it again replaces the deadline. ✅ ScopeFrameTest "a frame
timeout cancels the frame, and run() throws a TimeoutException"

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


## 8. `select` -- removed in 2.0.0

`phasync::select()` and the `SelectorInterface`/`Selector`/`ClosureSelector`/`FiberSelector`
machinery behind it are removed, not fixed. An audit surfaced real bugs (SEL-2: a TOCTOU
race between "readiness detected" and the caller acting on it, inherent to the API shape
of returning identity rather than performing the operation; SEL-5: the `$write` branch
called `readable()` instead of `writable()`; SEL-6: losing helper coroutines were
`discard()`ed, not `cancel()`ed, so their cleanup -- including a `FiberSelector`'s own
nested background coroutine -- never ran, an unbounded leak for raw `\Fiber` candidates).
A full redesign was worked through (closures as wait-functions, `cancel()`-not-`discard()`
for losers, reflection-validated closure shapes) and would have fixed all three. It was
not built.

The reason: phasync's actual goal is letting developers write coroutines as if they were
not async at all -- see section 0. `select()` is the one primitive that breaks that
promise by construction; using it correctly requires thinking explicitly about racing,
per-candidate timeouts and cancelling losers, which is exactly the kind of async-aware
reasoning phasync exists to make unnecessary. Checked against real usage, not just
argued from principle: `README.md` never mentioned it (unlike `Channel`/`WaitGroup`/
`Publisher`, which each get a section), `swerve` never used it, and the one real
consumer (`phasync/server`'s `TcpServer`/`UdpServer` accept loops) only ever needed the
narrow "wait across several raw stream resources" case -- which is itself the same
anti-pattern this section describes: one coroutine racing N listening sockets, instead of
N coroutines each owning one socket's own simple accept loop. The idiomatic phasync
replacement for "wait for whichever of these happens first" is almost always "spawn a
coroutine per thing and let the scheduler interleave them," the same as the rest of this
document's model for concurrency (`SCH-2`, `SCH-4`).

`SelectableInterface` itself is unaffected and stays exactly as useful as before --
`isReady()`/`await()` are real, independently used contracts for `Channel`/`StringBuffer`/
`WaitGroup`/`RateLimiter`'s own direct callers, never coupled to `select()` in the first
place. `phasync::await()` also gained a `SelectableInterface` branch in this same pass
(closing an unrelated, real gap: it previously only accepted a `\Fiber` or a promise-like
object, so `phasync::await($aChannel, $timeout)` used to throw).


## 9. Deadlocks

*Go reference: `fatal error: all goroutines are asleep - deadlock!`. The runtime reports
it exactly, when nothing can ever run again, and uses no timers.*

**DLK-1. No time-based deadlock guessing.** No inserted sleeps, no "wait 100 ms and
assume", no library-imposed 30 or 60 second limits. ❌ Present in `Channel::read()`,
`Channel::write()`, `ensureNotCreatorFiber()`, `BufferedStream`, `UnbufferedStream`.

**DLK-2. Await cycles are detected when they form.** `await($a)` inside `$a`'s own wait
chain throws `LogicException`. ✅ EdgeCaseTest "circular dependency deadlock"

**DLK-3. Global stall is detected exactly.** If no coroutine is runnable, no callback is
queued, and no timer, timeout, `idle()` waiter, signal handler (phasync's or one installed with
`pcntl_signal()`) or wait on the poller (a stream, or with phasync-ext any blocking call it turns into a wait)
is pending, yet coroutines are still blocked, nothing can ever wake them. A wait on a stream always counts,
since its other end may be in another process: a `stream_socket_pair()` deadlock is not
detected. Before deciding, the loop collects cycles once, since a freed flag wakes its
waiters (FLG-1). The outermost `run()` then fails with `DeadlockException`, whose message
names each blocked coroutine, the call it waits in and where: its coroutines and the services
are cancelled and unwind, and then it throws the `DeadlockException`. If its coroutines stall
again while they unwind (a shielded wait), the run fails again and the coroutines still waiting
are dropped. ✅ DeadlockTest (#103), ScopeFrameTest

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
is ready. At most one coroutine waits on a stream per direction: one may wait to read (or
for out of band data) and one to write, which covers full duplex use. A second coroutine
that tries to wait in a direction that is taken gets `LogicException` at once, without
waiting. `stream()` with both directions takes both. A waiting coroutine is resumed only by
the events it asked for. ✅ Changed in 2.0: before, several coroutines could wait on one
stream in the same direction and one event resumed all of them, whichever event each had
asked for.

Two coroutines reading (or writing) one stream at the same time is a data race, as with a
shared buffered reader in Go or Rust: PHP's stream buffer is shared, so each could get part
of the other's data. phasync catches it when the second coroutine has to wait. It does not
catch a second coroutine whose read is satisfied without waiting, because it never reaches
phasync; that coroutine silently takes data the first was waiting for. Give each direction
of a stream to one coroutine at a time; to share a stream, pass it between coroutines (for
example through a channel) or guard it with a lock.

**IO-2. A resource closed while waited on throws `IOException`** in the waiting
coroutine. ⚠️

**IO-3.** (removed: `preempt()` is gone, and `go()` and `StringBuffer::write()` no longer
suspend depending on the clock)

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
see the roadmap in `docs/roadmap-2.0.md` for that. A signal interrupting the wait (EINTR,
for example SIGCHLD from a child process or a `pcntl_signal()` handler) also makes
`stream_select()` return false, but that is not a failure: nothing is ready yet, and the
waiters keep waiting. ✅ Fixed in 2.0.0-alpha2; before, it failed every waiting coroutine.


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

**BUF-6. `StringBuffer` is a `SelectableInterface`.** `isReady()` is
true when a read would not block, when the buffer has ended, or when it has failed. ✅


## 12. Runtime hygiene

**RT-1. Inside `run()` the loop controls GC, and the caller's setting is restored.**
`gc_disable()` while `run()` is active is deliberate (D10): refcount frees are immediate,
and the loop collects cycles between coroutines, never inside one. It collects when it has a
spare moment: nothing runnable and a `poll(0)` finds no ready I/O, at most once per
`GC_MIN_INTERVAL` (0.5 s). A loop kept busy by always-runnable coroutines (a server's
connections making garbage while none ends) never has one, so it collects once
`GC_MAX_INTERVAL` (0.5 s) has passed while there is anything to collect, checked every 50 ms,
and not before: a loop that busy has no time to spare, so its garbage waits. There is no
roots threshold. `PHASYNC_GC_MIN_INTERVAL`/`PHASYNC_GC_MAX_INTERVAL` override the intervals.
✅ RuntimeTest, GarbageCollectionTest
Leaving `run()` must restore the previous state and never enable what the user disabled.
❌ Today `gc_enable()` is unconditional.

**RT-2. No error or exception handlers are installed.**  ⚠️

**RT-3. `run()` may be called at any nesting depth,** inside or outside a coroutine, and
top-level `run()` is what drives the loop. A `Fiber` phasync did not start can drive it in
place with `phasync::getLoop()->tick()`, which restores the running coroutine and its frame when
it returns. `run()` runs `$fn` in the caller's scope and leaves it unchanged. ✅ RunTest,
RuntimeTest "RT-3", ScopeFrameTest "a Fiber phasync did not start drives the loop in place with
tick()"

**RT-4. Unsupported use fails loudly.** `go()` and `await()` outside `run()`
throw `LogicException` with a message that names `phasync::run()`. ✅ AwaitTest,
APIOutsideOfRunTest, RuntimeTest "RT-4"


## 13. Testing rules

1. Every rule above gets at least one test whose name starts with its ID (`CHN-2: ...`).
2. Tests use the injectable clock (TMO-5), `stream_socket_pair()` and in-memory
   resources. No network, no real `sleep` beyond a few milliseconds.
3. Timing assertions compare against the injected clock, never wall-clock ratios.
   (`CurlMultiTest` currently fails this way and is network dependent.)
4. Cancellation and channel close get stress tests with randomised interleavings
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

`phasync\Util\Process::run()` launches a background process and returns a
`ProcessInterface` for interacting with its STDIN, STDOUT and STDERR. It returns the same
class, `ProcessRunner`, on POSIX and on Windows (issue #45; Windows support returned, unified
with POSIX rather than rebuilt separately, superseding the version-floor plan in
`docs/roadmap-2.0.md`). STDIN/STDOUT/STDERR are `['socket']` `proc_open()` descriptors on
every platform, not `['pipe', ...]`: a pipe cannot be made non-blocking or polled by
`stream_select()`/`WaitForMultipleObjects` on Windows, a socket can, on any PHP phasync
supports (>= 8.2, and `['socket']` has existed since 8.0). This is what makes one
implementation possible instead of a POSIX one plus a separate Windows one.

**PRC-1. A command that cannot be launched is detected before spawning, the same way on every
platform.** `Process::run()` resolves the command itself. On POSIX, the way `exec()` would: a
literal path if it contains a slash (resolved against `$cwd` if one is given), otherwise a
search through `PATH` (`$env['PATH']` if `$env` is given, otherwise the inherited one). On
Windows, the way `cmd.exe` would: a literal path (with or without its extension) if it
contains a `\`, `/` or drive letter, otherwise a `PATH` search, trying each `PATHEXT`
extension in turn (default `.COM;.EXE;.BAT;.CMD` if `PATHEXT` is unset); an existing file whose
extension isn't in `PATHEXT` is not considered executable, the Windows equivalent of the POSIX
executable-bit check. Either way, `RuntimeException` is thrown if nothing executable is found,
before `proc_open()` is called at all. ✅ Fixed. This replaced relying on `proc_open()`'s own
error reporting, which is not portable: whether it *reports* a missing executable synchronously
depends on the platform's glibc version and how PHP was built against it (glibc ≥2.24 added
synchronous exec-failure reporting to `posix_spawn()`, over a pipe). Verified failing two
different ways on two machines before the fix (ProcessTest `PRC-1`). A `$env` given without a
`PATH` entry is not pre-checked on either platform, since the platform's own fallback search
path cannot be predicted from here; whatever `proc_open()` and the platform actually do is what
happens (`surprise`). A second, best-effort layer checks the child's status once,
non-blockingly, right after a successful `proc_open()`, to catch what neither the resolution
check nor `proc_open()` itself can: the target existed and was executable, but the child still
failed to start, for example a POSIX `#!` interpreter line pointing at an interpreter that does
not exist.

**PRC-2. No shell is involved at any point, on either platform.** Arguments reach the child
exactly as given, with no metacharacter expansion and nothing to escape, and a bare command
name is found the way `execvp` (POSIX) or a direct `CreateProcess()` call (Windows) finds it,
not the way a shell would. ✅ Verified: a spawned process's immediate parent is `php` itself,
never a shell, and an argument containing shell metacharacters (`$HOME && echo x` on POSIX,
`%HOMEPATH% && echo x` on Windows) arrives at the child unexpanded, character for character.
On Windows this needs `proc_open()`'s `bypass_shell` option: without it, `proc_open()` always
wraps the command in `cmd /c`, which is exactly the shell involvement this rule rules out.
`bypass_shell` calls `CreateProcess()` directly. A `.bat`/`.cmd` command is the exception: Windows
runs batch files through `cmd.exe` itself, and PHP escapes the arguments for it (ProcessWindowsTest
`PRC-1` `[SURPRISE]`). On Windows, processes need PHP 8.3 or later; before that, starting one throws
`LogicException`. STDOUT and STDERR are separate streams
(`read(ProcessInterface::STDOUT)` / `read(ProcessInterface::STDERR)`), and `read()` behaves
the same inside and outside a coroutine: inside, it suspends and lets other coroutines run;
outside, it blocks.

**PRC-3. The exit code is available once the process has stopped running, not before.**
`getExitCode()` returns `false` while the process runs and the real exit code once it has
exited on its own; after `stop()` or a delivered signal ended it, it returns `-1` on POSIX
(PRC-5). ✅

**PRC-4. `write()` feeds STDIN; `getStream($fd)` exposes the STDIN, STDOUT and STDERR sockets
directly**, as non-blocking stream resources, for the three standard descriptors, and `null`
for anything else. ✅

**PRC-5. `stop()` sends SIGTERM and returns once the process is gone, is idempotent, and
works both inside and outside a coroutine.** Once a process has stopped or been signalled
away, `write()` and `sendSignal()` become no-ops returning `false`, and a later `stop()`
returns `true` immediately. ✅ On POSIX, `sendSignal()` and `sigterm()`/`sigkill()`/`sigint()`/
`sigstop()`/`sigcont()`/`sighup()` use plain integers (15/9/2/19/18/1), not the pcntl `\SIG*`
constants: those are undefined without the pcntl extension, which never builds on Windows, so
referencing them directly would make this class fail to load a signal method there. **On
Windows, `sendSignal()` (what every `sig*()` helper calls, via `proc_terminate()`) cannot
deliver a specific signal: the value passed is accepted for interface compatibility but
ignored, and every call forcibly ends the process outright**, the same as `sigkill()` on POSIX
(ProcessWindowsTest `PRC-5`). This means `sigstop()`/`sigcont()` cannot pause or resume a
Windows process -- `sigstop()` ends it instead, and `isStopped()` stays `false` -- and
`sigterm()`/`sigint()`/`sighup()` cannot ask a Windows child to shut down gracefully; they
hard-kill it too.

**PRC-6. Once an exit has been observed, the sockets are closed, so output that was never read
is lost and a later `read()` throws `IOException`.** ❌ `isRunning()` and `getExitCode()` poll
and close the sockets as soon as the process is seen to have terminated, whether or not
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
| SCH-5 | `go()` suspends its caller once the preempt interval has elapsed, using the process-wide `$lastPreemptTime`, so it depends on wall-clock time | `go()` returns to the caller without depending on the clock. Done: `preempt()` is removed |
| SCO-3 | An un-awaited child failure does not cancel siblings or interrupt the parent. `run()` throws at the end and the parent's return value is lost | **Settled (2.x redesign):** no fail-fast. It cancels nothing; `run()` throws it once the rest have ended, or the frame's exception handler takes it. (From 2026-09-29 to the redesign it failed the run with a hard teardown.) Done |
| SCO-5 | Cancelling a coroutine blocked in a nested `run()` does not cancel the nested children | **Changed (2.x redesign):** the nested frame's coroutines are cancelled and waited for. Done |
| SCO-7 | Context storage: a missing key gives a "returned by reference" notice, object keys throw `Error`, `isset` with a null key throws `TypeError` | Removed with `ContextInterface` (D4); contexts themselves were replaced by `Scope` and `Frame` in 2.x. Done |
| ERR-3 | Un-awaited failures surface only when `run()` ends, and are lost if the failing `Fiber` is still referenced then | A retained `Fiber`'s failure reaches its `run()` when it ends. Done |
| ERR-4 | Later failures are only logged, and a child failure is dropped when the main coroutine also fails | **Settled (2.x redesign):** `run()` throws one failure as it is, several in an `AggregateException` (`$fn`'s first, then by time of failure); a frame exception handler gets every child failure instead. Done |
| CAN-2, CAN-3 | Cancelling a running coroutine throws `RuntimeException`; a finished one throws `InvalidArgumentException` | Recorded, or no-op |
| CAN-5 | There is no way to cancel a group of coroutines | `Frame::cancel()` (D14). Done |
| CAN-9 | `run()` throws the `CancelledException` of a child the parent cancelled on purpose | Not a failure (D12) |
| CAN-10 *new* | Cancelling twice before the child resumes delivers only the second exception; the first escapes from `run()` even when the child caught the second | Every cancel is handled once, none escapes |
| TMO-6 *new* | `checkTimeouts()` skips about half of the expired waiters per pass: ten simultaneous 0.2 s timeouts fire at 0.5, 1.0, 1.5 and 2.0 s, and equal timeouts wake in the order 0, 2, 1, 3. Plain PHP shows `SplObjectStorage` skipping every other entry when entries are removed during `foreach`, which matches. Verified in a scratch copy: iterating a snapshot of `pending` makes all ten fire in the same pass. The loop is unchanged since the first release, and nothing in the history says the skipping is deliberate | **Fixed:** all expired waiters fire in the same pass, in registration order |
| TMO-7 *new* | A zero or negative timeout waits for the next timeout check, so its delay depends on when the last check ran (0 to about 0.5 s) | Fires promptly |
| TMO-1 | `readable()` and `stream()` apply the default timeout | **Fixed:** infinite unless given; the default timeout and `setDefaultTimeout()` are gone (D6) |
| DLK-1 | Creator-fiber heuristic (100 ms) and an extra `sleep()` in `Channel::read()` and `write()` | Removed |
| DLK-3 | A global stall is not detected: `tick()` idles in 0.5 s sleeps forever | `DeadlockException`. **Done** (#103) |
| CHN-1 | An unbuffered `write()` returns before any reader has read, and its timeout is ignored; `isReadyForWrite()` looks inverted | Rendezvous |
| CHN-5 / D1 | `foreach` over a `ReadChannel` stops at a written `null` and leaves the rest unread | **Fixed:** `read()` takes `?bool &$eof = null`; `getIterator()` uses it. Also fixed in `Subscriber`/`Subscribers` (two further bugs found in the same family, see CHN-5) |
| CHN-9 / D2 | `stdClass`, `Closure`, `DateTime` and resources throw `TypeError` | **Fixed:** `read()`/`write()` are `mixed`. Resolved by recognizing cross-process transport as a separate concern (clustering, `docs/roadmap-2.0.md`), not something in-process channels need to be typed around |
| WG-1 | `add()` takes no argument, so `add(3)` silently counts as 1 (PHP ignores extra arguments). Not a bug, a difference from Go | **Fixed** (D15): `add(int $delta = 1)` |
| RL-1 | `RateLimiter::await($timeout)` ignores the timeout; dropping a limiter with an untaken token makes `run()` throw `ChannelException` | **Fixed:** the timeout throws `TimeoutException` (#75); no throw |
| FLG-1 | A flag freed while waited on does not wake its waiters: `awaitFlag()`'s parameter, the `flagGraph` value and `ObjectPoolTrait::popInstance()` keep it alive or keep its store undestroyed | Waiters woken with `CancelledException` when the flag is freed **Fixed**, see FlagsTest |
| SEL-2, SEL-5, SEL-6 | `select()`'s TOCTOU race, the `$write` branch calling `readable()` instead of `writable()`, and `discard()`-not-`cancel()`-ed losers leaking a `FiberSelector`'s own background coroutine | **Removed, not fixed** (2.0.0): `select()` and the `Selector`/`ClosureSelector`/`FiberSelector` machinery are gone; see section 8 |
| BUF-2 *new* | `StringBuffer::read($n, $timeout > 0)` on an empty buffer **hangs the whole process** once the timeout expires: `await()` returns at once and `read()` loops without suspending | **Fixed:** `read()` throws `TimeoutException` at the deadline. An infinite wait with no writer still blocks, and an unread buffer still grows without limit, by design (maintainer) |
| BUF-1 *new* | `readFromResource()`'s own backpressure loop (`while (totalWritten - totalRead > $bufferSize) { $this->await(); }`) never actually blocks: `$totalRead` was only ever decremented (by `unread()`), never incremented by `read()`/`readFixed()` consuming data, so the gap never closes; and `await()`/`isReady()` return at once whenever *any* data is buffered, which is already true here. Past 1 MB net, this is an unyielding busy loop, not backpressure -- found while building `$maxSize` (2.0.0), unexercised by any existing test (largest prior test: 100 KB) | **Fixed:** `read()`/`readFixed()` now increment `$totalRead` and raise the flag on every consumption; the loop waits directly on the flag instead of through `await()`, matching the pattern `readFixed()` already used for its own "not just any data, *enough* data" wait |
| IO-4 *new* | `readable()` or `writable()` on `php://memory` makes `run()` throw `ValueError: No stream arrays were passed` | **Fixed:** the waiting coroutine gets that `ValueError`, with and without the extension, and the loop goes on (#77) |
| IO-5 *new* | Outside a coroutine, `stream()` on a non-blocking stream ignores its timeout and throws `TimeoutException` after about 1 s (inverted loop condition); a timeout under 1 s loops until data arrives | Honours the timeout |
| IO-6 *new* | `io::fgets` (removed, 2.0.0, see below) could return partial lines, and `io::fgetc` (removed) never waited | Moot: `phasync\io` is now `file_get_contents`/`file_put_contents`/`flock` only |
| PRC-1 | A command that could not be launched used to be reported inconsistently across platforms, because it relied on `proc_open()`'s own (glibc-version-dependent) error reporting | **Fixed:** resolved and checked before `proc_open()` is called, the same way on every platform |
| PRC-6 | Once `isRunning()` or `getExitCode()` has seen the process exit, the pipes are closed and unread output is lost | Output remains readable |
| RT-1 | `gc_enable()` is called unconditionally at exit even if the user had disabled GC; a channel end in a reference cycle is not released by `unset()` until the loop next collects (deliberate, P3) | Previous state restored |
| RT-5 *new* | `logUnhandledException()` passes `$exception->getCode()` to `error_log()` as the message *type*, so a code of 1 would send email | Moot: `logUnhandledException()` is gone (phasync logs nothing, SCO-3) |
| RT-6 *new* | `go(run: true)` outside a coroutine returned a Fiber that never started | Moot: the `run` parameter is gone, `go()` outside a coroutine throws `LogicException` |

Not covered: `await()` on a still-pending fiber from outside a coroutine cannot be reached
through the public API.


## Open decisions

| # | Question | Options | My recommendation |
|---|----------|---------|-------------------|
| D1 | `ReadChannelInterface::read()` / `Channel::read()`: `write(null)` is accepted today (the value union includes `null`), so a legitimately-written `null` and "channel closed" are indistinguishable, and `ReadChannel::getIterator()`'s `while (null !== $this->read())` truncates `foreach` at the first written `null` (pinned, `CHN-5`) | keep as is; throw on close; `read(float $timeout = PHP_FLOAT_MAX, ?bool &$eof = null): mixed` | **Decided (maintainer) and done:** the `&$eof` out-parameter (inspired by C#'s `out` pattern; `feof()`-style naming). `getIterator()` uses it, fixing `CHN-5` for free with no second method. `Subscriber`/`Subscribers` got the same fix and two further bugs it surfaced in that class specifically (see CHN-5) |
| D2 | Which values can a channel carry? Was `\Serializable\|array\|string\|float\|int\|bool\|null` | keep the union; any `mixed` | **Decided (maintainer) and done (2.0.0):** `mixed`. The restriction existed only because cross-process channel support was undecided; resolved by recognizing that as a separate concern -- a future clustering primitive (string broadcast, `docs/roadmap-2.0.md`) owns cross-process transport with its own explicit serialization at that layer, so in-process channels have no reason to be constrained by it. PHP ZTS builds remain a separate, still-open question, orthogonal to this |
| D3 | Shape of multiple failures in one frame | first only (Go's `errgroup`); `AggregateException` holding all | **Settled (2.x redesign, owner):** `AggregateException` holding all when there are several, `$fn`'s first, then by time of failure; one failure is thrown as it is. "First only" was rejected: among sibling failures the first is arbitrary and can hide the important one |
| D4 | Keep `ArrayAccess` context storage? | keep; remove; replace with a small explicit coroutine-local API | **Decided (maintainer) and done (2.0.0-alpha18):** `ContextInterface`, `ContextTrait`, `DefaultContext`, `ServiceContext` and the storage are gone. **Superseded (2.x redesign):** contexts are gone too. Callers keep their own `WeakMap` keyed by `Scope::getCurrent()` (SCO-7) |
| D5 | Cancellation delivery | once per request (edge); every suspension while cancelled (level, as in Trio) | **Decided (maintainer):** cancellation is one exception thrown into the coroutine. Cleanup is done by catching it. No shielding |
| D6 | Default timeout for IO operations | infinite everywhere; keep a finite default for IO | **Decided (maintainer) and done:** infinite everywhere. `setDefaultTimeout()`, `getDefaultTimeout()` and `DEFAULT_TIMEOUT` are removed. A finite default is a hidden timer |
| D7 | Choice order when several selectables are ready | argument order; random like Go | **Moot (2.0.0):** `select()` was removed, not fixed; see section 8 |
| D8 | `RateLimiter` semantics | token bucket; leaky bucket; something else | Write down what it does today, then test it |
| D9 | `StringBuffer` readers | one reader, enforced; several allowed | One reader, enforced by a cheap check |
| D10 | GC handling in `run()` | leave GC on; keep manual control but restore state | **Decided (maintainer):** keep manual control, it is deliberate design. Still open: restore the user's previous GC state on exit. A long-lived coroutine is collected by the roots threshold (2026-09-28: without it a swerve CakePHP worker grew to 1.2 GB) |
| D13 | Should there be a guard helper for coroutines that own helpers? | none; a small `phasync::guard(Fiber ...$fibers)` object | **Decided (maintainer): no.** A guard would be a symptom of channels and other primitives not guarding their dependants. Teardown comes from channel ends closing (CHN-8) |
| D17 | `readFixed($n, $timeout)` returns `null` on timeout, and also `null` at end-of-stream with too little data. `read()` now throws `TimeoutException` | keep `null`; throw `TimeoutException` like `read()`; distinguish the two cases | **Decided (maintainer) and done:** throws `TimeoutException` on a real timeout, matching `read()`. Matches `read()`'s other rule too: `$timeout` of exactly `0` is a non-blocking poll and never throws, only a real positive timeout that expires does. Only the genuine "buffer ended with too little data" case (`$this->ended`, no timeout involved) still returns `null` |
| D16 | Should `StringBuffer` get an optional maximum length? | none; opt-in `?int $maxLength = null` that makes `write()` throw when the unread bytes would exceed it | **Deferred to 2.0.0 (maintainer).** Opt-in, throwing, default unbounded remains the shape if it happens |
| D15 | Should `WaitGroup::add()` take a delta like Go's `Add(delta int)`? | keep `add()` with no argument; `add(int $delta = 1)` | **Decided (maintainer) and done:** `add(int $delta = 1)`. A `go(Closure)` method like Go's `WaitGroup.Go` may come later |
| D14 | Cancelling a group of coroutines | implement; defer | **Decided (maintainer, 2026-09-29) and done; reshaped in 2.x:** `Frame::cancel()`, the deepest coroutines first; the caller, if in the frame, gets it at once. `phasync::cancel()` takes a `Fiber` only. A failure no longer cancels anything (SCO-3) |
| D12 | Does a cancelled child that ends with `CancelledException` fail its frame? | yes (today); no, treat as normal termination | No. Otherwise every deliberate `cancel()` needs a `try/catch` in the child to keep `run()` from throwing |
| D11 | How long may an idle loop sleep past the earliest pending timeout? | keep the fixed 0.5 s idle sleep; cap the idle sleep at the earliest pending deadline | **Done (2.0.0-alpha21):** timeouts in 10 ms slots; the idle sleep is capped at the next slot while a timeout waits, computed only when nothing is runnable |
