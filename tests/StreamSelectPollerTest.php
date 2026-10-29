<?php

/*
 * The poller used without ext-phasync: it waits with stream_select(). With the extension loaded
 * the loop uses the extension's poller instead, so these tests do not apply.
 */

use phasync\IOException;
use phasync\StreamSelectPoller;

// Probing a stream stream_select() cannot wait on makes PHP warn (silenced with @ in the poller,
// which PHPUnit still reports): the warning is expected, the tests assert what the poller does with it.
beforeEach(function () {
    \set_error_handler(static fn (int $code, string $message): bool => \str_contains($message, 'select()able'));
});
afterEach(function () {
    \restore_error_handler();
});

test('waiting to read from a stream stream_select() cannot wait on throws the ValueError it throws', function () {
    $memory = \fopen('php://memory', 'r+');

    $thrown = phasync::run(function () use ($memory) {
        try {
            phasync::readable($memory, 1.0);
        } catch (ValueError $e) {
            return $e->getMessage();
        }

        return 'returned';
    });

    expect($thrown)->toBe('No stream arrays were passed');
})->skip(\extension_loaded('phasync'), 'the extension has its own poller');

test('waiting to write to a stream stream_select() cannot wait on throws the ValueError it throws', function () {
    $memory = \fopen('php://memory', 'r+');

    $thrown = phasync::run(function () use ($memory) {
        try {
            phasync::writable($memory, 1.0);
        } catch (ValueError $e) {
            return $e->getMessage();
        }

        return 'returned';
    });

    expect($thrown)->toBe('No stream arrays were passed');
})->skip(\extension_loaded('phasync'), 'the extension has its own poller');

test('a stream that cannot be waited on fails only its own waiter, the others keep waiting', function () {
    $memory        = \fopen('php://memory', 'r+');
    [$read, $send] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);

    $log = phasync::run(function () use ($memory, $read, $send) {
        $log    = [];
        $reader = phasync::go(function () use ($read, &$log) {
            phasync::readable($read, 1.0);
            $log[] = 'socket: ' . \fread($read, 10);
        });
        $bad = phasync::go(function () use ($memory, &$log) {
            try {
                phasync::readable($memory, 1.0);
            } catch (ValueError) {
                $log[] = 'memory: ValueError';
            }
        });
        phasync::await($bad);
        \fwrite($send, 'hello');
        phasync::await($reader);

        return $log;
    });

    expect($log)->toBe(['memory: ValueError', 'socket: hello']);
})->skip(\extension_loaded('phasync'), 'the extension has its own poller')
    ->skip(\PHP_OS_FAMILY === 'Windows', 'stream_socket_pair(STREAM_PF_UNIX) is not implemented on Windows');

test('two coroutines cannot wait to read from the same stream', function () {
    [$read, $send] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);

    $message = phasync::run(function () use ($read) {
        $first = phasync::go(fn () => phasync::readable($read, 0.2));
        try {
            phasync::readable($read, 0.2);
        } catch (LogicException $e) {
            $message = $e->getMessage();
        }
        phasync::cancel($first);

        return $message;
    });

    expect($message)->toBe('Another coroutine is already waiting to read from this stream');
})->skip(\extension_loaded('phasync'), 'the extension has its own poller')
    ->skip(\PHP_OS_FAMILY === 'Windows', 'stream_socket_pair(STREAM_PF_UNIX) is not implemented on Windows');

test('several coroutines may wait to write to one stream: each readiness wakes the one that waited longest', function () {
    [$write, $other] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
    \stream_set_blocking($write, false);
    while (@\fwrite($write, \str_repeat('x', 65536)) > 0) {
        // fill the socket buffer: writable() waits
    }

    $woken = phasync::run(function () use ($write, $other) {
        $woken   = [];
        $writers = [];
        foreach (['first', 'second', 'third'] as $name) {
            $writers[] = phasync::go(function () use ($write, $name, &$woken) {
                phasync::writable($write, 1.0);
                $woken[] = $name;
            });
        }
        $cancelled = phasync::go(fn () => phasync::writable($write, 1.0));
        phasync::sleep(0.02);
        expect($woken)->toBe([]);
        phasync::cancel($cancelled);
        \stream_set_blocking($other, false);
        while ('' !== (string) \fread($other, 1 << 20)) {
            // drain: the stream becomes writable
        }
        foreach ($writers as $writer) {
            phasync::await($writer);
        }

        return $woken;
    });

    expect($woken)->toBe(['first', 'second', 'third']);
})->skip(\extension_loaded('phasync'), 'the extension has its own poller')
    ->skip(\PHP_OS_FAMILY === 'Windows', 'stream_socket_pair(STREAM_PF_UNIX) is not implemented on Windows');

test('a stream past FD_SETSIZE makes writable() throw an IOException naming the cause', function () {
    $kept = [];
    for ($i = 0; $i < 1300; ++$i) {
        $kept[] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP)
            ?: throw new RuntimeException("stream_socket_pair() failed after $i pairs: ulimit -n is too low for this test");
    }
    $high = \end($kept)[0];

    $message = phasync::run(function () use ($high) {
        try {
            phasync::writable($high, 3.0);
        } catch (IOException $e) {
            return $e->getMessage();
        }

        return 'returned';
    });

    expect($message)->toStartWith('stream_select() failed for 1 watched stream(s): ');
})->skip(\extension_loaded('phasync'), 'the extension has no FD_SETSIZE limit')
    ->skip(\PHP_OS_FAMILY === 'Windows', 'stream_socket_pair(STREAM_PF_UNIX) is not implemented on Windows');

test('a ValueError that is not about the watched streams propagates from poll()', function () {
    [$read, $send] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);

    $thrown = phasync::run(function () use ($read) {
        $poller = new StreamSelectPoller(phasync::getLoop());
        $waiter = phasync::go(fn () => $poller->readable($read, 1.0));
        try {
            $poller->poll(-1.0);
        } catch (ValueError $e) {
            $thrown = $e;
        }
        phasync::cancel($waiter);

        return $thrown;
    });

    expect($thrown)->toBeInstanceOf(ValueError::class);
})->skip(\extension_loaded('phasync'), 'the extension has its own poller')
    ->skip(\PHP_OS_FAMILY === 'Windows', 'stream_socket_pair(STREAM_PF_UNIX) is not implemented on Windows');
