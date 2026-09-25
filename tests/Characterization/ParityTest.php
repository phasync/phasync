<?php

/*
 * Parity: phasync behaves identically with and without phasync-ext. The only allowed
 * differences are the FD_SETSIZE limit and blocking code staying blocking without the
 * extension. Run the suite both ways:
 *
 *   vendor/bin/pest
 *   php -d extension=/path/to/phasync.so vendor/bin/pest
 *
 * Writers run in a separate process: without the extension a blocking call blocks the
 * whole process, so a writer coroutine in the same process could never run.
 */

uses()->group('parity');

/**
 * Start a PHP child whose stdout is a blocking pipe, and return [process, pipe].
 *
 * @return array{0: resource, 1: resource}
 */
function parity_child(string $code): array
{
    $process = \proc_open([\PHP_BINARY, '-r', $code], [1 => ['pipe', 'w']], $pipes);

    return [$process, $pipes[1]];
}

test('a second coroutine reading a blocking stream while the first waits in fgets(): blocked behind it without the extension, LogicException with it', function () {
    // The child keeps the pipe open afterwards: at EOF fgets() would return the buffered rest anyway
    [$process, $pipe] = parity_child('echo "first "; flush(); usleep(200000); echo "line\nsecond line\n"; flush(); sleep(5);');

    $start = \microtime(true);
    $got   = phasync::run(function () use ($pipe) {
        $got = [];
        $a   = phasync::go(function () use ($pipe, &$got) {
            $got['A'] = \fgets($pipe);
        });
        $b = phasync::go(function () use ($pipe, &$got) {
            // Starts while A waits for the rest of its line
            phasync::sleep(0.1);
            try {
                $got['B'] = \fgets($pipe);
            } catch (LogicException $e) {
                $got['B'] = LogicException::class;
            }
        });
        phasync::await($a);
        phasync::await($b);
        \ksort($got);

        return $got;
    });
    $elapsed = \microtime(true) - $start;
    \proc_terminate($process);
    \fclose($pipe);
    \proc_close($process);

    // Without the extension A's fgets() blocks the process, so B can only start after it.
    // With it, B would wait on the same stream as A, which phasync refuses (IO-1).
    expect($got)->toBe([
        'A' => "first line\n",
        'B' => \extension_loaded('phasync') ? LogicException::class : "second line\n",
    ]);
    expect($elapsed)->toBeLessThan(1.0);
})->group('blocking-code');

/**
 * A connected TCP pair; the client end is blocking with a 0.3 s socket timeout.
 *
 * @return array{0: resource, 1: resource}
 */
function parity_tcp_pair(): array
{
    $server = \stream_socket_server('tcp://127.0.0.1:0');
    $client = \stream_socket_client('tcp://' . \stream_socket_get_name($server, false));
    $peer   = \stream_socket_accept($server);
    \fclose($server);
    \stream_set_timeout($client, 0, 300000);

    return [$client, $peer];
}

/**
 * Run $io inside phasync::run() and report what PHP code would observe.
 *
 * @return array{result: mixed, timed_out: bool, notices: string[], elapsed: float}
 */
function parity_observe(Closure $io): array
{
    return phasync::run(static function () use ($io) {
        $notices = [];
        \set_error_handler(static function (int $code, string $message) use (&$notices): bool {
            $notices[] = \preg_replace('/\d+ bytes/', 'N bytes', $message);

            return true;
        });
        try {
            $start  = \microtime(true);
            [$result, $stream] = $io();
            $elapsed = \microtime(true) - $start;
        } finally {
            \restore_error_handler();
        }

        return [
            'result'    => $result,
            'timed_out' => \stream_get_meta_data($stream)['timed_out'],
            'notices'   => $notices,
            'elapsed'   => $elapsed,
        ];
    });
}

test('fgets() on a socket timeout returns the partial line and sets timed_out, without an exception', function () {
    $seen = parity_observe(static function () {
        [$client, $peer] = parity_tcp_pair();
        \fwrite($peer, 'partial');

        return [\fgets($client), $client];
    });

    expect([$seen['result'], $seen['timed_out'], $seen['notices']])->toBe(['partial', true, []]);
    expect($seen['elapsed'])->toBeGreaterThan(0.25)->toBeLessThan(1.0);
});

test('fread() on a socket timeout with nothing to read returns false and sets timed_out, without an exception', function () {
    $seen = parity_observe(static function () {
        [$client, $peer] = parity_tcp_pair();

        return [\fread($client, 100), $client];
    });

    expect([$seen['result'], $seen['timed_out'], $seen['notices']])->toBe([false, true, []]);
    expect($seen['elapsed'])->toBeGreaterThan(0.25)->toBeLessThan(1.0);
});

test('fwrite() on a socket timeout returns what was written and sets timed_out, with PHP\'s notice, without an exception', function () {
    $seen = parity_observe(static function () {
        [$client, $peer] = parity_tcp_pair(); // the peer never reads

        return [\fwrite($client, \str_repeat('x', 64 << 20)), $client];
    });

    expect($seen['result'])->toBeInt()->toBeGreaterThan(0)->toBeLessThan(64 << 20);
    expect([$seen['timed_out'], $seen['notices']])->toBe([true, ['fwrite(): Send of N bytes failed with errno=11 Resource temporarily unavailable']]);
    expect($seen['elapsed'])->toBeGreaterThan(0.25)->toBeLessThan(1.5);
});
