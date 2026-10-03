![phasync](docs/phasync-illustration2.webp)

# phasync: High-concurrency PHP
[![🧪 CI](https://github.com/phasync/phasync/actions/workflows/ci.yaml/badge.svg?branch=main)](https://github.com/phasync/phasync/actions/workflows/ci.yaml)
[![Latest Stable Version](https://img.shields.io/packagist/v/phasync/phasync)](https://img.shields.io/packagist/v/phasync/phasync)
![GitHub](https://img.shields.io/github/license/phasync/phasync)
[![PHP Version Require](https://img.shields.io/packagist/dependency-v/phasync/phasync/php)](https://img.shields.io/packagist/dependency-v/phasync/phasync/php)
[![codecov](https://codecov.io/gh/phasync/phasync/graph/badge.svg?token=UUB02FXQH4)](https://codecov.io/gh/phasync/phasync)

**Async PHP that is still just PHP.** phasync runs thousands of coroutines in one PHP process,
on PHP's own fibers. No promises, no `->then()`, no special runtime to install: a function
that waits on the network reads top to bottom, returns a value and throws exceptions like any
other. Use it in one function of an existing PHP-FPM application, or as the engine of a
server that holds tens of thousands of connections.

```php
// In any controller, under PHP-FPM or anywhere else: three HTTP calls at once, not one after another
[$user, $orders, $recommendations] = phasync::run(fn () => array_map(phasync::await(...), [
    phasync::go(fn () => fetch("https://api.example.com/users/$id")),
    phasync::go(fn () => fetch("https://api.example.com/users/$id/orders")),
    phasync::go(fn () => fetch("https://api.example.com/users/$id/recommendations")),
]));
```

The request takes as long as the slowest of the three calls, not their sum. `fetch()` is an
ordinary function (below); nothing else in the application changes.

## Why phasync

- **No colored functions.** A coroutine is a plain closure. Code that waits looks exactly like
  code that doesn't, so async stays an implementation detail of the function that needs it,
  instead of spreading `Promise` return types through your codebase.
- **Structured, not fire-and-forget.** `phasync::run()` returns only when every coroutine it
  started has finished. An exception in a coroutine surfaces where you wait for it. Cancellation
  and timeouts are exceptions thrown into the coroutine, so `finally` blocks run.
- **Starts small.** One `phasync::run()` in one function is a complete phasync program. There is
  no application-wide event loop to adopt first, no framework to switch to.
- **Built for load.** One event loop per process, with waiting built on PHP streams:
  `stream_select()` out of the box, epoll with [phasync-ext](https://github.com/phasync/phasync-ext).
  Waits on sockets cost no objects of their own, pools (`phasync\Util\Pool`) reuse database
  connections across coroutines, and hot paths leave nothing for the garbage collector.
- **Legacy code joins in.** With phasync-ext loaded, the code you already have (MySQL through PDO or
  mysqli, curl and Guzzle, `file_get_contents()`, `http://` streams, DNS lookups, `sleep()`) waits
  cooperatively inside coroutines instead of blocking the process. No rewrite.
- **Yours to own.** MIT, and no dependencies beyond PHP and PSR interfaces: small enough for you,
  or your coding agent, to read whole and maintain for a decade. See
  [the Ennerd philosophy](PHILOSOPHY.md).

## One library, two ways to run it

**Under PHP-FPM or any SAPI: concurrency inside a request.** FPM stays exactly as it is, one
request per process. Inside the request, `phasync::run()` overlaps independent work: API calls,
queries on separate connections, file and DNS work. The response goes out when the slowest part
is done, not when the sum of them is.

**Under [swerve](https://github.com/phasync/swerve): an asynchronous server, end to end.** Your
PSR-15 application stays loaded, and every worker serves thousands of requests at once, each in
a phasync context of its own. The same code that overlapped three API calls under FPM now also
overlaps requests, WebSocket connections and background work.

## The path from PHP-FPM to real-time

Each step is useful on its own, and none requires the next.

| Step | Add | What you get |
|---|---|---|
| 1 | `phasync/phasync` | Concurrent I/O inside one request, on your existing FPM setup. Wait with phasync's APIs: `phasync::readable()`, `CurlMulti`, `MySQLiPoll`. |
| 2 | [`phasync/phasync-ext`](https://github.com/phasync/phasync-ext) | Libraries you did not write (MySQL through PDO or mysqli, Guzzle, `curl_exec()`, files, `http://` streams, DNS) cooperate inside coroutines, unchanged. Epoll instead of `stream_select()`. |
| 3 | [`phasync/swerve`](https://github.com/phasync/swerve) | A long-running PSR-15 server: the app boots once, each worker serves thousands of connections, streaming bodies, Server-Sent Events, WebSockets. Slim, mini and other PSR-15 frameworks run as they are. |
| 4 | [`phasync/tether`](https://github.com/phasync/tether) | Live server-side components over one WebSocket per tab, in the style of Blazor Server and Phoenix LiveView. PHP 8.3. |

For your own protocols, [`phasync/net`](https://github.com/phasync/net) has TCP, UDP and Unix
socket servers and clients on the same loop.

### How far that goes

On one 56-thread server, each at its fastest worker count, a hello-world PSR-15 app on swerve
served 157,000 to 285,000 requests per second from 64 to about 28,000 connections: in the same
range as Go's net/http (162,000 to 317,000) and Node's http module (135,000 to 246,000), and
ahead of both at 10,000 connections. A Slim app on swerve served 2 to 3 times what an Express
app served on Node. [Method and raw results](https://github.com/phasync/swerve/tree/main/benchmarks).

## Getting started

```bash
composer require phasync/phasync
```

phasync needs PHP 8.2 or later and nothing else. It runs under PHP-FPM, Apache's mod_php, the
CLI, and long-running servers.

```php
use phasync\Services\CurlMulti;

function fetch(string $url): string
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    // Inside a coroutine this waits without blocking the others; outside, it just runs
    return CurlMulti::await($ch);
}

$pages = phasync::run(function () {
    $a = phasync::go(fn () => fetch('https://www.php.net/'));
    $b = phasync::go(fn () => fetch('https://getcomposer.org/'));

    return [phasync::await($a), phasync::await($b)];
});
```

- `phasync::run($fn)` runs `$fn` as a coroutine, and returns its result once it and every
  coroutine it started have finished. Called inside a coroutine, it is a nested scope.
- `phasync::go($fn)` starts a coroutine and returns it at once; `phasync::await($coroutine)`
  waits for its result, or throws its exception.
- `phasync::sleep($seconds)` pauses only the current coroutine; `phasync::sleep()` lets the others
  run for a moment.
- `phasync::cancel($coroutine)` throws a `CancelledException` into it; every wait accepts a
  timeout and throws a `TimeoutException` when it runs out. Without one, a wait waits for as long
  as it takes.

## Making your own I/O cooperative

Without the extension, a coroutine waits on a stream by asking phasync first:

```php
stream_set_blocking($socket, false);

$data  = fread(phasync::readable($socket), 65536);       // waits until there is something to read
$wrote = fwrite(phasync::writable($socket), $response);  // waits until the socket takes more
```

Both work outside coroutines too, so a library written this way runs in any PHP program. One
coroutine at a time may wait to read a given stream, and one to write to it.

For HTTP, `phasync\Services\CurlMulti::await($ch)` runs a curl handle cooperatively. For MySQL,
`phasync\Services\MySQLiPoll` does the same for mysqli's asynchronous queries.

## phasync-ext: existing code joins in

[phasync-ext](https://github.com/phasync/phasync-ext) is an optional PHP extension. Inside
`phasync::run()`, blocking I/O in code that knows nothing about phasync suspends the coroutine
instead of the process, and returns exactly what PHP would have returned, timeouts and warnings
included. That covers sockets and TLS, MySQL through mysqli or PDO, curl and Guzzle, pipes
and child processes, `sleep()`, DNS lookups, files and filesystem calls, and more; the
extension's README has the full list. Clients with their own network code, such as PostgreSQL's
libpq and phpredis, still block.

```bash
composer require phasync/phasync-ext
```

```php
phasync\try_enable_ext(); // first line of a CLI script: loads the bundled binary, restarting once
```

Under PHP-FPM, add `extension=phasync` to `php.ini` instead. Prebuilt binaries cover PHP 8.2 to
8.5 on Linux (x86-64 and ARM64, glibc and musl). The extension waits with epoll instead of
`stream_select()`, so a process can watch any number of sockets: without it, PHP's `stream_select()`
cannot use file descriptors numbered 1024 and up.

phasync behaves the same with and without the extension. The extension only lets more code wait
cooperatively and makes waiting cheaper.

## Coordinating coroutines

**Channels** pass values between coroutines. A read waits for a writer, and a write into a full
channel waits for a reader:

```php
phasync::run(function () {
    phasync::channel($reader, $writer, 10);

    phasync::go(function () use ($writer) {
        foreach (['a.txt', 'b.txt', 'c.txt'] as $file) {
            $writer->write($file);
        }
        $writer->close();
    });

    foreach ($reader as $file) { // ends when the writer closes
        echo "processing $file\n";
    }
});
```

**WaitGroup** waits for a set of coroutines to finish:

```php
use phasync\Util\WaitGroup;

phasync::run(function () {
    $group = new WaitGroup();
    foreach (range(1, 5) as $i) {
        $group->add();
        phasync::go(function () use ($group, $i) {
            try {
                phasync::sleep(0.1 * $i);
            } finally {
                $group->done();
            }
        });
    }
    $group->await();
});
```

**Publishers** deliver every message to every subscriber, in order:

```php
phasync::run(function () {
    phasync::publisher($subscribers, $publisher);

    foreach (range(1, 3) as $i) {
        $subscription = $subscribers->subscribe();
        phasync::go(function () use ($subscription, $i) {
            foreach ($subscription as $event) {
                echo "subscriber $i got $event\n";
            }
        });
    }
    $publisher->write('deployed');
    $publisher->close();
});
```

**Pool** lends interchangeable resources, such as database connections, to one coroutine at a
time, up to a limit:

```php
use phasync\Util\Pool;

$db   = new Pool(fn () => new PDO($dsn, $user, $password), 10);
$rows = $db->use(fn (PDO $pdo) => $pdo->query('SELECT 1')->fetchAll());
```

For heavy instances, `idleTimeout` gives memory back after a burst, `dispose` closes what the pool
lets go of, and `warm()` makes the next instance before anyone waits for it:

```php
$apps = new Pool(fn () => bootApplication(), 8, idleTimeout: 60, dispose: fn ($app) => $app->flush());
$apps->warm();
```

Also in `phasync\Util`: `RateLimiter`, `Synchronized` (a lock per coroutine) and `StringBuffer`
(a fast byte buffer for protocol parsers).

## Documentation

- [INTRO: `phasync::run()` and `phasync::go()`](docs/run-and-go.md)
- [Using phasync in existing projects](docs/use-in-existing-projects.md)
- [Asynchronous I/O](docs/async-io-basics.md)
- [Concurrent HTTP requests with CurlMulti](docs/curl-multi.md)
- [WaitGroup](docs/wait-group.md) · [RateLimiter](docs/rate-limiter.md)
- [Write a basic web server](docs/build-async-server.md)
- [API reference](docs/API.md)
- [Semantics: how each part behaves, exactly](docs/SEMANTICS.md)

## Compared with other async PHP

- **Swoole and OpenSwoole** are PHP extensions with their own server and runtime model; many of
  their features require it. phasync is a Composer library on standard PHP; phasync-ext is
  optional, and an application written for phasync runs the same with or without it.
- **ReactPHP and AMPHP** are async-first ecosystems: code that waits uses their APIs (ReactPHP's
  promises; AMPHP's futures, which read sequentially on fibers) and their own libraries, such as
  an async HTTP client or MySQL driver in place of curl or PDO. phasync is closer to Go's model:
  plain functions, and with phasync-ext the libraries you already use wait cooperatively.

## Contributing

phasync is in active development toward 2.0. [docs/SEMANTICS.md](docs/SEMANTICS.md) describes
how every part is meant to behave, and the tests in `tests/Characterization` pin how it behaves
today. Tests, documentation and bug reports are welcome.

## License

*phasync* is open-sourced software licensed under the MIT license.
