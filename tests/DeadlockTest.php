<?php

/*
 * DLK-3 (docs/SEMANTICS.md): a global stall makes the outermost run() throw DeadlockException,
 * exactly and without timers. Each case runs in a child process, so a stall that is not
 * detected shows as a process still running after a bounded wait instead of a hanging suite.
 */

if (!\function_exists('dlkChild')) {
    /**
     * Run $body as the main coroutine of phasync::run() in a child process, for up to $seconds.
     * The child prints what run() threw ("Class: message") or "returned". Returns whether the
     * child was still running when the wait ended (it is killed then), and what it printed.
     *
     * @return array{running: bool, stdout: string}
     */
    function dlkChild(string $body, float $seconds = 5.0): array
    {
        $file = \tempnam(\sys_get_temp_dir(), 'phasync-dlk-');
        \file_put_contents($file, "<?php\nrequire " . \var_export(\dirname(__DIR__) . '/vendor/autoload.php', true) . ";\n"
            . "use phasync\\Util\\WaitGroup;\n"
            . "try {\n    phasync::run(function () {\n$body\n    });\n    echo \"returned\\n\";\n"
            . "} catch (Throwable \$e) {\n    echo \$e::class, ': ', \$e->getMessage(), \"\\n\";\n}\n");
        $process = \proc_open([\PHP_BINARY, '-d', 'display_errors=stderr', $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        \stream_set_blocking($pipes[1], false);
        $stdout   = '';
        $deadline = \microtime(true) + $seconds;
        try {
            while (($running = \proc_get_status($process)['running']) && \microtime(true) < $deadline) {
                $stdout .= \stream_get_contents($pipes[1]);
                \usleep(10000);
            }
            $stdout .= \stream_get_contents($pipes[1]);

            return ['running' => $running, 'stdout' => $stdout];
        } finally {
            \proc_terminate($process, 9);
            \fclose($pipes[1]);
            \fclose($pipes[2]);
            \proc_close($process);
            \unlink($file);
        }
    }
}

// ---------------------------------------------------------------------------
// Detected
// ---------------------------------------------------------------------------

test('DLK-3: two coroutines reading a channel whose writer is kept but never writes: DeadlockException, finally blocks run, each blocked coroutine named', function () {
    $r = dlkChild(<<<'PHP'
        phasync::channel($r, $w);
        $a = phasync::go(function () use ($r) { try { $r->read(); } finally { echo "finally a\n"; } });
        $b = phasync::go(function () use ($r) { try { $r->read(); } finally { echo "finally b\n"; } });
        try {
            phasync::await($a);
        } finally {
            echo "finally main\n";
        }
        PHP);

    expect($r['running'])->toBeFalse();
    expect($r['stdout'])->toContain("finally a\n", "finally b\n", "finally main\n");
    expect($r['stdout'])->toContain('phasync\DeadlockException: ');
    expect($r['stdout'])->toContain('3 coroutines');
    // Each blocked coroutine with what it waits in and where (the child script's own lines)
    expect(\substr_count($r['stdout'], 'phasync\Internal\ReadChannel->read() at '))->toBe(2);
    expect($r['stdout'])->toContain('phasync::await() at ');
    expect($r['stdout'])->toMatch('/ReadChannel->read\(\) at \S+phasync-dlk-\w+:7\b/');
    expect($r['stdout'])->toMatch('/phasync::await\(\) at \S+phasync-dlk-\w+:10, for coroutine #\d+/');
});

test('DLK-3: a flag nobody raises: DeadlockException', function () {
    $r = dlkChild(<<<'PHP'
        $flag = new stdClass();
        phasync::go(function () use ($flag) { phasync::awaitFlag($flag); });
        phasync::awaitFlag($flag);
        PHP);

    expect($r['running'])->toBeFalse();
    expect($r['stdout'])->toContain('phasync\DeadlockException: ', '2 coroutines', 'phasync::awaitFlag() at ');
});

test('DLK-3: a WaitGroup never done: DeadlockException', function () {
    $r = dlkChild(<<<'PHP'
        $wg = new WaitGroup();
        $wg->add();
        $worker = phasync::go(function () use ($wg) { phasync::sleep(0.05); });
        $wg->await();
        PHP);

    expect($r['running'])->toBeFalse();
    expect($r['stdout'])->toContain('phasync\DeadlockException: ', '1 coroutine ', 'phasync\Util\WaitGroup->await() at ');
});

test('DLK-3: the main coroutine blocked alone: DeadlockException', function () {
    $r = dlkChild(<<<'PHP'
        $flag = new stdClass();
        try {
            phasync::awaitFlag($flag);
        } finally {
            echo "finally main\n";
        }
        PHP);

    expect($r['running'])->toBeFalse();
    expect($r['stdout'])->toStartWith("finally main\nphasync\\DeadlockException: ");
    expect($r['stdout'])->toContain('1 coroutine ');
});

test('DLK-3: a stall inside a nested run(): the outermost run() throws DeadlockException', function () {
    $r = dlkChild(<<<'PHP'
        try {
            phasync::run(function () {
                $flag = new stdClass();
                try {
                    phasync::awaitFlag($flag);
                } finally {
                    echo "finally inner\n";
                }
            });
        } catch (Throwable $e) {
            echo "inner run threw ", $e::class, "\n";
        }
        PHP);

    expect($r['running'])->toBeFalse();
    expect($r['stdout'])->toContain("finally inner\n", "inner run threw phasync\\CancelledException\n", 'phasync\DeadlockException: ');
});

test('DLK-3: a stall after a timer ran out is detected then, not before', function () {
    $r = dlkChild(<<<'PHP'
        $flag = new stdClass();
        $start = microtime(true);
        phasync::go(function () { phasync::sleep(0.3); });
        try {
            phasync::awaitFlag($flag);
        } finally {
            echo microtime(true) - $start >= 0.3 ? "after the timer\n" : "too early\n";
        }
        PHP);

    expect($r['running'])->toBeFalse();
    expect($r['stdout'])->toContain("after the timer\n", 'phasync\DeadlockException: ');
});

test('DLK-3: a stall while the coroutines of the failed run unwind fails it again, and drops the coroutines still waiting', function () {
    $r = dlkChild(<<<'PHP'
        $flag = new stdClass();
        phasync::go(function () use ($flag) {
            try {
                phasync::awaitFlag($flag);
            } finally {
                try {
                    phasync::shielded(function () use ($flag) { phasync::awaitFlag($flag); });
                } finally {
                    echo "dropped\n";
                }
            }
        });
        phasync::awaitFlag($flag);
        PHP);

    expect($r['running'])->toBeFalse();
    expect($r['stdout'])->toContain("dropped\n", "phasync\\DeadlockException: 2 coroutines wait and nothing can wake them:\n");
});

// ---------------------------------------------------------------------------
// Not a stall: something can still wake the waiters
// ---------------------------------------------------------------------------

test('DLK-3: a sleep elsewhere that raises the flag is no stall', function () {
    $r = dlkChild(<<<'PHP'
        $flag = new stdClass();
        phasync::go(function () use ($flag) { phasync::sleep(0.2); phasync::raiseFlag($flag); });
        phasync::awaitFlag($flag);
        PHP);

    expect($r)->toBe(['running' => false, 'stdout' => "returned\n"]);
});

test('DLK-3: a wait with a finite timeout is no stall', function () {
    $r = dlkChild(<<<'PHP'
        $flag = new stdClass();
        $wg   = new WaitGroup();
        $wg->add();
        try {
            phasync::awaitFlag($flag, 0.2);
        } catch (phasync\TimeoutException) {
            echo "flag timed out\n";
        }
        try {
            $wg->await(0.2);
        } catch (phasync\TimeoutException) {
            echo "wait group timed out\n";
        }
        PHP);

    expect($r)->toBe(['running' => false, 'stdout' => "flag timed out\nwait group timed out\nreturned\n"]);
});

test('DLK-3: a coroutine waiting on a socket is a possible wake-up: a stream_socket_pair() deadlock is not detected', function () {
    $r = dlkChild(<<<'PHP'
        [$a, $b] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        echo "waiting\n";
        phasync::readable($a);
        PHP, 1.0);

    expect($r)->toBe(['running' => true, 'stdout' => "waiting\n"]);
});

test('DLK-3: a child process that writes later is no stall', function () {
    $r = dlkChild(<<<'PHP'
        $flag = new stdClass();
        $p = phasync\Util\Process::run(PHP_BINARY, ['-r', 'usleep(300000); echo "from child";']);
        phasync::go(function () use ($p, $flag) {
            $out = '';
            while ('' !== ($chunk = $p->read())) {
                $out .= $chunk;
            }
            echo $out, "\n";
            phasync::raiseFlag($flag);
        });
        phasync::awaitFlag($flag);
        PHP);

    expect($r)->toBe(['running' => false, 'stdout' => "from child\nreturned\n"]);
});

test('DLK-3: blocking calls, which ext-phasync turns into waits on the loop, are no stall', function () {
    $r = dlkChild(<<<'PHP'
        $flag = new stdClass();
        phasync::go(function () use ($flag) { phasync::awaitFlag($flag); echo "woken\n"; });
        usleep(100000);
        $p = proc_open([PHP_BINARY, '-r', 'usleep(200000); echo "line\n";'], [1 => ['pipe', 'w']], $pipes);
        echo fgets($pipes[1]);
        echo 'exit ', proc_close($p), "\n";
        phasync::raiseFlag($flag);
        PHP);

    expect($r)->toBe(['running' => false, 'stdout' => "line\nexit 0\nwoken\nreturned\n"]);
});

test('DLK-3: a flag raised from a pcntl_signal() handler of the application\'s own is no stall', function () {
    $r = dlkChild(<<<'PHP'
        pcntl_async_signals(true);
        $stop = new stdClass();
        pcntl_signal(SIGUSR1, function () use ($stop) { phasync::raiseFlag($stop); });
        $kill = proc_open([PHP_BINARY, '-r', 'usleep(200000); posix_kill(' . getmypid() . ', SIGUSR1);'], [], $pipes);
        phasync::awaitFlag($stop);
        proc_close($kill);
        PHP);

    expect($r)->toBe(['running' => false, 'stdout' => "returned\n"]);
})->skip(!\function_exists('pcntl_signal') || !\function_exists('posix_kill'), 'needs pcntl and posix');

test('DLK-3: an idle() waiter is no stall', function () {
    $r = dlkChild(<<<'PHP'
        $flag = new stdClass();
        phasync::go(function () use ($flag) { phasync::idle(0.1); phasync::raiseFlag($flag); });
        phasync::awaitFlag($flag);
        PHP);

    expect($r)->toBe(['running' => false, 'stdout' => "returned\n"]);
});

test('DLK-3: a service that raises the flag later is no stall', function () {
    $r = dlkChild(<<<'PHP'
        $flag = new stdClass();
        phasync::service(function () use ($flag) { phasync::sleep(0.2); phasync::raiseFlag($flag); });
        phasync::awaitFlag($flag);
        PHP);

    expect($r)->toBe(['running' => false, 'stdout' => "returned\n"]);
});

test('DLK-3: a server waiting on its listener, with every other coroutine blocked, is no stall', function () {
    $r = dlkChild(<<<'PHP'
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $port   = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
        $flag   = new stdClass();
        $loop   = phasync::go(function () use ($server, $flag) {
            while (true) {
                phasync::readable($server);
                $conn = stream_socket_accept($server, 0);
                phasync::go(function () use ($conn, $flag) {
                    phasync::readable($conn);
                    echo 'got ', fread($conn, 100), "\n";
                    phasync::raiseFlag($flag);
                });
            }
        });
        // A client in another process, after a while: nothing in this one can wake anyone
        $client = proc_open([PHP_BINARY, '-r', 'usleep(300000); fwrite(stream_socket_client("tcp://127.0.0.1:' . $port . '"), "hello");'], [], $pipes);
        phasync::awaitFlag($flag);
        phasync::cancel($loop);
        proc_close($client);
        PHP);

    expect($r)->toBe(['running' => false, 'stdout' => "got hello\nreturned\n"]);
});
