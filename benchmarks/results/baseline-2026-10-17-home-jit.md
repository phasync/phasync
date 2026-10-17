# Benchmark results 2026-10-17 (home)

- Date: 2026-10-17 08:46
- PHP: 8.5.11
- JIT: on (-d opcache.enable_cli=1 -d opcache.jit=1255 -d opcache.jit_buffer_size=64M)
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
| spawn-trivial | 24.2 us/op, 2 MB | 3.2 us/op, 2 MB | 26.7 us/op, 2 MB | 1.2 us/op, 2 MB |
| spawn-suspend-once | 37.4 us/op, 2 MB | 51.0 us/op, 2 MB | 47.7 us/op, 2 MB | - |
| sleepers-10k | 0.510 s, 188 MB | 0.671 s, 218 MB | 0.866 s, 332 MB | 0.134 s, 72 MB |
| tcp-echo | 26,923 msg/s | 23,510 msg/s | - | 18,600 msg/s |
| http-fanout | 4,094 req/s, 22 MB | 823 req/s, 104 MB | 2,040 req/s, 82 MB | 2,398 req/s, 60 MB |
