# Benchmark results 2026-10-17 (home)

- Date: 2026-10-17 08:41
- PHP: 8.5.11
- JIT: off
- CPU: Intel(R) Xeon(R) CPU E5-2697 v3 @ 2.60GHz
- phasync commit: 8f0ba6d (origin/main, includes the CurlMulti fix 2c48fc3)
- Runs per cell: median of 3 (churn: 1)
- Packages:
- amphp/amp 3.1.3
- amphp/byte-stream 2.1.2
- amphp/cache 2.0.1
- amphp/dns 2.4.1
- amphp/hpack 3.2.2
- amphp/http 2.1.2
- amphp/http-client 5.3.6
- amphp/parser 1.1.1
- amphp/pipeline 1.2.7
- amphp/process 2.1.0
- amphp/serialization 1.1.0
- amphp/socket 2.4.1
- amphp/sync 2.3.0
- phasync/phasync dev-main
- react/async 4.3.0
- react/cache 1.2.0
- react/dns 1.14.0
- react/event-loop 1.6.0
- react/http 1.11.1
- react/promise 3.3.0
- react/socket 1.17.0
- react/stream 1.4.0
- revolt/event-loop 1.0.9

| benchmark | phasync | amp | react-fiber | react-callback |
|---|---|---|---|---|
| spawn-trivial | 23.3 us/op, 4 MB | 4.3 us/op, 2 MB | 22.0 us/op, 2 MB | 1.6 us/op, 2 MB |
| spawn-suspend-once | 41.5 us/op, 4 MB | 45.5 us/op, 2 MB | 46.1 us/op, 2 MB | - |
| sleepers-10k | 0.508 s, 188 MB | 0.659 s, 210 MB | 0.850 s, 326 MB | 0.144 s, 72 MB |
| tcp-echo | 29,660 msg/s | 27,375 msg/s | - | 23,037 msg/s |
| http-fanout | 4,354 req/s, 24 MB | 842 req/s, 118 MB | 2,100 req/s, 80 MB | 2,890 req/s, 58 MB |

90 s churn, 1,000 workers sleeping 1 ms, one run each (last sample):

| runtime | RSS MB | gc runs | gc collected | gc roots | ops | p50 ms | p99 ms | max ms |
|---|---|---|---|---|---|---|---|---|
| phasync | 82 | 25 | 0 | 1015 | 20186355 | 4.09 | 4.77 | 376.64 |
| amp | 67.6 | 1 | 0 | 7019 | 11036792 | 7.58 | 10.74 | 184.94 |
| react (callback) | 103.8 | 0 | 0 | 2015 | 55754476 | 1.27 | 1.57 | 1029.45 |
