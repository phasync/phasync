# phasync benchmarks

`bench.php` measures the speed of phasync's core operations. Use it to check that a change
doesn't make phasync slower. The `*.js` files in this directory are unrelated (web server
comparisons).

```bash
php benchmarks/bench.php                              # run everything, print a table
php benchmarks/bench.php --save=benchmarks/baseline.json      # record a baseline
php benchmarks/bench.php --compare=benchmarks/baseline.json   # compare with a baseline
php benchmarks/bench.php --compare=... --strict       # exit code 1 if anything is SLOWER
php benchmarks/bench.php --only=chan,select           # scenarios whose name contains one of these
php benchmarks/bench.php --runs=3                     # measured runs per scenario (default 5)
```

A default run takes about a minute. Each scenario runs in its own fresh PHP process, after a
short warm-up, so GC state and phasync's global driver never leak between scenarios. The
table shows the **median** operations per second over the runs, with min, max and the
spread. `--compare` prints the ratio to the baseline and marks a scenario `SLOWER` when it
is more than 15% slower and `FASTER` when it is more than 15% faster.

## Scenarios

| Name | Measures |
|------|----------|
| `run_empty` | overhead of an empty `phasync::run()` |
| `go_await_trivial` | `go()` + `await()` of a trivial closure |
| `go_alive_10k` | 10 000 coroutines alive at once |
| `switch_sleep0`, `switch_yield` | context switches between two coroutines |
| `flag_roundtrip` | `raiseFlag()`/`awaitFlag()` ping-pong |
| `chan_unbuffered_pingpong`, `chan_buffered64` | channel round trips and throughput |
| `select_2ch`, `select_4mixed` | `select()` over channels, a `StringBuffer` and a fiber |
| `waitgroup_1000` | `WaitGroup` with 1000 workers |
| `timers_1000` | 1000 coroutines looping `sleep(0.001)` (timer scheduling) |
| `stream_pair_4k` | non-blocking socketpair echo with 4 KB messages |
| `stream_idle_400` | socketpair ping-pong while 400 other coroutines wait on quiet streams (per-tick cost of waiting streams) |
| `sbuf_frames64`, `sbuf_chunks8k_read100`, `sbuf_frame_parse` | `StringBuffer` throughput and protocol parsing |
| `memory_100k` | 100 000 short coroutines; also reports peak memory and GC runs |

`fork()` is not benchmarked.

## Noise

Timings depend on the machine, so **compare only against a baseline recorded on the same
machine** (the script warns if the CPU differs). Expect 5 to 10 percent noise between runs.
To reduce it:

- close other work, and don't run the test suite at the same time
- pin to one CPU: `taskset -c 2 php benchmarks/bench.php`
- use the default 5 runs (the median absorbs most outliers)

The `select_*` scenarios are the noisiest, because each `select()` call starts helper
coroutines. If a scenario shows `SLOWER` by a small margin, re-run it on its own with
`--only=NAME` before drawing conclusions.
