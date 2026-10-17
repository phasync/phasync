# phasync benchmarks

Purpose: track phasync's performance across versions, and keep an honest record of how it compares
with Amp and ReactPHP on the same machine. It is not a leaderboard and not a marketing benchmark.
Numbers are only comparable between runs on the same machine, PHP build and JIT setting.
(`bench.php` and `ab.php` in this directory are older micro-benchmarks of phasync's own operations;
the `*.js` files are unrelated web-server comparisons.)

## Run

```
cd benchmarks && composer install && cd ..
php benchmarks/run.php                 # JIT off, median of 3 runs per cell, about 15 minutes
php benchmarks/run.php --jit           # opcache.jit=1255, jit_buffer_size=64M
php benchmarks/run.php --steady        # adds the 90 s churn test (one run per runtime)
php benchmarks/run.php --filter=http   # only benchmarks whose name contains "http"
```

`composer install` links the local phasync checkout (path repository, symlink), so the suite measures
whatever is checked out one directory up. Each run writes `results/<date>-<host>[-jit].md` with PHP version,
JIT setting, CPU, phasync commit, package versions and the table. Local results are git-ignored;
to keep one, rename it to `results/baseline-*.md`.

Each case is one script in `cases/<benchmark>/<runtime>.php`; `cases/churn.php` takes the runtime as an argument.
`run.php` starts and stops the `php -S` server for http-fanout and the echo servers for tcp-echo.

## What each benchmark measures

| benchmark | measures | does not measure |
|---|---|---|
| spawn-trivial | 1,000,000 times: start a coroutine whose body is `fn() => 1`, await it. Time per operation. | A realistic coroutine. The body never suspends, which is the case Revolt optimizes (see below). |
| spawn-suspend-once | 100,000 times: start a coroutine that suspends once (yields to the loop, resumes), await it. | Concurrency: one coroutine is alive at a time. |
| sleepers-10k | 10,000 coroutines each sleeping 1 ms, all started before any is awaited. Wall time, peak memory. | Timer accuracy. |
| tcp-echo | One blocking client does 100,000 round trips of 64 bytes against an echo server written for each runtime. | Many connections. It is one connection, so it mostly shows per-wake-up cost. |
| http-fanout | 1,000 concurrent GET requests to a local `php -S` server that returns "hello world". | A like-for-like runtime comparison: the HTTP clients differ (phasync `CurlMulti`, amphp/http-client, react/http). The server is the same for all. |
| churn (`--steady`) | 90 s with 1,000 workers looping on a 1 ms sleep. Samples RSS, `gc_status()` and wake-up latency every 5 s. | Throughput under real load. |

Columns: `phasync`; `amp` (amphp/amp 3 on revolt/event-loop); `react-fiber` (react/async, the fiber-style
ReactPHP API, the comparable row for a coroutine runtime); `react-callback` (plain promises and timer callbacks).
The `react-callback` rows measure the loop floor, not a coroutine runtime: no fiber, no coroutine, no await.
They show what the event loop costs without the layer phasync and Amp add on top, and are not a like-for-like
competitor. Where ReactPHP has no fiber-style API for a test (tcp-echo), only the callback row exists.

## Machine and JIT notes

Baseline machine: Intel Xeon E5-2697 v3 (2014), PHP 8.5.11 CLI, Linux. Absolute numbers will be better on a newer
CPU; the ratios are what carry over. The JIT variant uses
`php -d opcache.enable_cli=1 -d opcache.jit=1255 -d opcache.jit_buffer_size=64M`. JIT changed little here:
these tests spend their time in fiber switches, allocation and the event loop. http-fanout and churn vary more between
runs than the others.

## Current results

Baseline of 2026-10-17, phasync `8f0ba6d` (origin/main), median of 3 runs. "MB" is peak memory of the benchmarked
process (not measured for tcp-echo, where that process is the client).

JIT off ([full file](results/baseline-2026-10-17-home.md)):

| benchmark | phasync | amp | react-fiber | react-callback |
|---|---|---|---|---|
| spawn-trivial | 23.3 us/op, 4 MB | 4.3 us/op, 2 MB | 22.0 us/op, 2 MB | 1.6 us/op, 2 MB |
| spawn-suspend-once | 41.5 us/op, 4 MB | 45.5 us/op, 2 MB | 46.1 us/op, 2 MB | - |
| sleepers-10k | 0.508 s, 188 MB | 0.659 s, 210 MB | 0.850 s, 326 MB | 0.144 s, 72 MB |
| tcp-echo | 29,660 msg/s | 27,375 msg/s | - | 23,037 msg/s |
| http-fanout | 4,354 req/s, 24 MB | 842 req/s, 118 MB | 2,100 req/s, 80 MB | 2,890 req/s, 58 MB |

JIT on ([full file](results/baseline-2026-10-17-home-jit.md)); churn was not run with JIT:

| benchmark | phasync | amp | react-fiber | react-callback |
|---|---|---|---|---|
| spawn-trivial | 24.2 us/op, 2 MB | 3.2 us/op, 2 MB | 26.7 us/op, 2 MB | 1.2 us/op, 2 MB |
| spawn-suspend-once | 37.4 us/op, 2 MB | 51.0 us/op, 2 MB | 47.7 us/op, 2 MB | - |
| sleepers-10k | 0.510 s, 188 MB | 0.671 s, 218 MB | 0.866 s, 332 MB | 0.134 s, 72 MB |
| tcp-echo | 26,923 msg/s | 23,510 msg/s | - | 18,600 msg/s |
| http-fanout | 4,094 req/s, 22 MB | 823 req/s, 104 MB | 2,040 req/s, 82 MB | 2,398 req/s, 60 MB |

90 s churn, 1,000 workers sleeping 1 ms, one run each (last sample):

| runtime | RSS MB | gc runs | gc collected | gc roots | ops | p50 ms | p99 ms | max ms |
|---|---|---|---|---|---|---|---|---|
| phasync | 82 | 25 | 0 | 1015 | 20186355 | 4.09 | 4.77 | 376.64 |
| amp | 67.6 | 1 | 0 | 7019 | 11036792 | 7.58 | 10.74 | 184.94 |
| react (callback) | 103.8 | 0 | 0 | 2015 | 55754476 | 1.27 | 1.57 | 1029.45 |

The churn run was JIT off, once per runtime, so treat small differences as noise. The React row is the callback loop.

## Where phasync loses, and why

- **Trivial spawn.** spawn-trivial is 23.3 us/op for phasync against 4.3 for Amp (JIT on: 24.2 vs 3.2). Revolt runs a
  callback that never suspends in a reused fiber and allocates a fiber only when the callback suspends.
  `phasync::go()` always allocates a fiber. A bare `new Fiber` plus `start()` costs about 12 us on this CPU, because the
  engine maps a fresh stack for every fiber; that is most of phasync's cost per spawn. `go()` is declared to return a
  `Fiber`, and the decision is not to change that (for example to a Task that creates its fiber lazily) to win this
  benchmark. When the coroutine suspends once, which is the usual case, phasync is ahead of Amp in spawn-suspend-once
  (41.5 vs 45.5 us/op; 37.4 vs 51.0 with JIT). Code that spawns many coroutines that finish without waiting will still be faster on Amp.
- **Steady-state GC.** In the 90 s churn run phasync ran the cycle collector 25 times and collected 0 cycles
  (Amp: 1 run, React: 0). The roots come from the wait structures of live coroutines, so the runs are wasted work. This is
  a known open item, not yet fixed; the numbers are here so a fix can be seen in them.
- **Wake-up latency in churn.** With 1,000 workers on a 1 ms sleep, phasync's median wake-up was 4.09 ms against 1.27 ms for the
  React callback loop and 7.58 ms for Amp. The worst single wake-up was 377 ms for phasync (185 ms Amp, 1,029 ms React callbacks).
  Wake-ups completed in 90 s: 20.2 M phasync, 11.0 M Amp, 55.8 M React callbacks.
- **Against the loop floor.** react-callback is faster than every coroutine runtime in sleepers-10k and spawn-trivial. A coroutine runtime pays for a fiber per coroutine.
- **tcp-echo** is one connection; the differences between runtimes are small and may be within run-to-run noise.

## Where it wins

Only what the baseline shows:

- sleepers-10k: 0.51 s against 0.66 (Amp) and 0.85 (react-fiber), with lower peak memory (188 MB against 210 and 326).
- spawn-suspend-once: faster than Amp and react-fiber, with and without JIT.
- http-fanout: 4,354 req/s against 842 (Amp), 2,100 (react-fiber) and 2,890 (react-callback), with the lowest peak memory (24 MB).
  Before the `CurlMulti` fix (commit 2c48fc3: re-run immediately instead of a 20 ms poll) this was about 46 req/s. The
  comparison also covers different HTTP client libraries.
- tcp-echo: the highest msg/s of the three, by a small margin for a single connection.
- churn: more wake-ups completed than Amp in the same 90 s, at a higher RSS (82 vs 68 MB).
