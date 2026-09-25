<?php

/*
 * Integration tests for phasync-ext under phasync::run(), which puts the process under
 * phasync\ext\manage() when the extension is loaded. Load it with a full path:
 *
 *   php -d extension=/path/to/phasync-ext/modules/phasync.so vendor/bin/pest
 *
 * Skipped entirely if the extension isn't loaded.
 */

uses()->beforeEach(function () {
    if (!\extension_loaded('phasync')) {
        $this->markTestSkipped('phasync extension not loaded');
    }
})->in(__DIR__);

/**
 * @return array{0: resource, 1: int} [server socket, port]
 */
function exthooks_server(): array
{
    $server = \stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if (false === $server) {
        throw new RuntimeException("Unable to start server: $errstr");
    }
    $name = \stream_socket_get_name($server, false);
    $port = (int) \substr($name, \strrpos($name, ':') + 1);

    return [$server, $port];
}

test('a hooked fread() on a real TCP socket suspends the coroutine and resumes with the data', function () {
    phasync::run(function () {
        [$server, $port] = exthooks_server();

        $accepted = null;
        phasync::go(function () use ($server, &$accepted) {
            $accepted = phasync::readable($server); // server accept itself isn't hooked; use core phasync
            $accepted = \stream_socket_accept($accepted);
        });

        $client = \stream_socket_client("tcp://127.0.0.1:$port", $errno, $errstr, 5);
        expect($client)->not->toBeFalse();

        phasync::sleep(0.02); // let the accept coroutine run
        \fwrite($accepted, 'hello from server');

        // Plain, unmodified fread() -- no phasync::readable() call here at all. If the
        // hook is really wired in, this suspends the coroutine and resumes once data
        // arrives, exactly like phasync::readable()+fread() would, but transparently.
        $data = \fread($client, 100);
        expect($data)->toBe('hello from server');

        \fclose($client);
        \fclose($accepted);
        \fclose($server);
    });
});

test('a hooked fread() that would block genuinely lets other coroutines run while it waits', function () {
    phasync::run(function () {
        [$server, $port] = exthooks_server();
        $log             = [];

        $accepted = null;
        phasync::go(function () use ($server, &$accepted) {
            $accepted = \stream_socket_accept(phasync::readable($server));
        });
        $client = \stream_socket_client("tcp://127.0.0.1:$port", $errno, $errstr, 5);
        phasync::sleep(0.02);

        $reader = phasync::go(function () use ($client, &$log) {
            $log[] = 'reader: about to fread (nothing written yet)';
            $data  = \fread($client, 100); // must suspend: no data yet
            $log[] = 'reader: got ' . $data;
        });

        // This sibling coroutine should get to run WHILE the reader is suspended,
        // proving the wait is real cooperative suspension, not a blocking call.
        phasync::go(function () use (&$log) {
            $log[] = 'sibling: ran while reader was waiting';
        });

        phasync::sleep(0.05);
        $log[] = 'main: about to write, reader still waiting';
        \fwrite($accepted, 'payload');

        phasync::await($reader);

        expect($log)->toBe([
            'reader: about to fread (nothing written yet)',
            'sibling: ran while reader was waiting',
            'main: about to write, reader still waiting',
            'reader: got payload',
        ]);

        \fclose($client);
        \fclose($accepted);
        \fclose($server);
    });
});

test('several hooked sockets waiting concurrently each wake with their own data, not a mix-up', function () {
    phasync::run(function () {
        [$server, $port] = exthooks_server();
        $accepted        = [];
        phasync::go(function () use ($server, &$accepted) {
            for ($i = 0; $i < 3; ++$i) {
                $accepted[] = \stream_socket_accept(phasync::readable($server));
            }
        });

        $clients = [];
        for ($i = 0; $i < 3; ++$i) {
            $clients[] = \stream_socket_client("tcp://127.0.0.1:$port", $errno, $errstr, 5);
        }
        phasync::sleep(0.03);

        $results = [];
        $readers = [];
        foreach ($clients as $i => $client) {
            $readers[] = phasync::go(function () use ($client, $i, &$results) {
                $results[$i] = \fread($client, 100);
            });
        }

        // Write to them out of order, after a delay, to make sure each reader really
        // is independently suspended and wakes for ITS OWN socket, not the first one.
        phasync::sleep(0.02);
        \fwrite($accepted[2], 'third');
        phasync::sleep(0.01);
        \fwrite($accepted[0], 'first');
        phasync::sleep(0.01);
        \fwrite($accepted[1], 'second');

        foreach ($readers as $r) {
            phasync::await($r);
        }

        // Insertion order depends on which fd becomes ready first (deliberately out of
        // order, by design above) -- only key=>value pairs matter, not insertion order.
        \ksort($results);
        expect($results)->toBe([0 => 'first', 1 => 'second', 2 => 'third']);

        foreach ($clients as $c) {
            \fclose($c);
        }
        foreach ($accepted as $a) {
            \fclose($a);
        }
        \fclose($server);
    });
});

test('the sleep() hook routes through phasync::sleep(), not a real blocking sleep', function () {
    phasync::run(function () {
        $log = [];
        phasync::go(function () use (&$log) {
            $log[] = 'sleeper: before';
            \usleep(50_000); // plain, unmodified usleep() -- should route through the hook
            $log[] = 'sleeper: after';
        });
        phasync::go(function () use (&$log) {
            $log[] = 'sibling: ran while sleeper was "sleeping"';
        });
        phasync::sleep(0.1);

        expect($log)->toBe([
            'sleeper: before',
            'sibling: ran while sleeper was "sleeping"',
            'sleeper: after',
        ]);
    });
});

test('a FIFO rendezvous through fopen() works via the extension\'s thread pool, without blocking the process', function () {
    // FIFO open() blocks in-kernel until the OTHER end is also opened -- there is no fd to
    // poll yet, so this is structurally unsolvable by cooperative/Fiber scheduling alone;
    // it needs real OS thread concurrency (phasync-ext's thread pool). A naive/blocking
    // fallback would deadlock this test outright (the writer's go() would never get a
    // scheduler turn while the reader's fopen() blocks the single thread), so simply not
    // hanging is itself part of what this test proves -- the sibling-coroutine log below
    // makes that explicit rather than relying on "it didn't time out".
    $pipePath = \sys_get_temp_dir() . '/phasync_exthooks_test_' . \getmypid() . '.fifo';
    if (\file_exists($pipePath)) {
        \unlink($pipePath);
    }

    try {
        phasync::run(function () use ($pipePath) {
            \posix_mkfifo($pipePath, 0600);
            $log = [];

            $reader = phasync::go(function () use ($pipePath, &$log) {
                $log[] = 'reader: about to fopen (no writer yet)';
                $fp    = \fopen($pipePath, 'r');
                $log[] = 'reader: fopen returned';
                $data  = \stream_get_contents($fp);
                \fclose($fp);

                return $data;
            });

            // Should get a scheduler turn WHILE the reader's fopen() is still pending,
            // proving the open is a real cooperative wait, not a blocking call.
            phasync::go(function () use (&$log) {
                $log[] = 'sibling: ran while reader\'s fopen() was pending';
            });

            $writer = phasync::go(function () use ($pipePath, &$log) {
                phasync::sleep(0.05); // let the reader register first
                $log[] = 'writer: about to fopen';
                $fp    = \fopen($pipePath, 'w');
                $log[] = 'writer: fopen returned';
                \fwrite($fp, 'Hello, World!');
                \fclose($fp);
            });

            phasync::await($writer);
            $data = phasync::await($reader);

            expect($data)->toBe('Hello, World!');
            expect(\array_slice($log, 0, 3))->toBe([
                'reader: about to fopen (no writer yet)',
                'sibling: ran while reader\'s fopen() was pending',
                'writer: about to fopen',
            ]);
            // Both opens are released by the same rendezvous; which fiber wakes first is timing.
            expect(\array_slice($log, 3))->toEqualCanonicalizing(['reader: fopen returned', 'writer: fopen returned']);
        });
    } finally {
        if (\file_exists($pipePath)) {
            \unlink($pipePath);
        }
    }
});

test('a stream created inside run() behaves natively once run() has returned', function () {
    [$server, $port]     = exthooks_server();
    [$client, $accepted] = phasync::run(function () use ($server, $port) {
        $client = \stream_socket_client("tcp://127.0.0.1:$port", $errno, $errstr, 5);

        return [$client, \stream_socket_accept(phasync::readable($server))];
    });

    \stream_set_blocking($client, false);
    $start = \microtime(true);
    expect(\fread($client, 100))->toBe('');                // non-blocking: empty, at once
    expect(\microtime(true) - $start)->toBeLessThan(0.1);

    \fwrite($accepted, 'native');
    \stream_set_blocking($client, true);
    expect(\fread($client, 100))->toBe('native');

    \fclose($client);
    \fclose($accepted);
    \fclose($server);
});
