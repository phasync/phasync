<?php

/*
 * Characterization tests for phasync\Process\Process and the POSIX runner.
 *
 * docs/SEMANTICS.md mentions Process pipes only in its scope section (section 0) and has no
 * rule IDs for them yet, so these tests use the prefix PRC-.
 *
 * These pin how the code behaves TODAY. A failing test means "stop and tell the
 * maintainer" (see tests/Characterization/README.md).
 */

use phasync\IOException;
use phasync\Process\PosixProcessRunner;
use phasync\Process\Process;
use phasync\Process\ProcessInterface;

uses()->group('characterization');

if (\PHP_OS_FAMILY === 'Windows') {
    test('PRC-1: the POSIX process runner is not exercised on Windows')->skip('POSIX only');

    return;
}

/* ------------------------------------------------------------------ PRC-1 */

test('PRC-1: Process::run() returns a PosixProcessRunner on POSIX systems', function () {
    $process = Process::run('true');
    expect($process)->toBeInstanceOf(PosixProcessRunner::class);
    expect($process)->toBeInstanceOf(ProcessInterface::class);
    expect([ProcessInterface::STDIN, ProcessInterface::STDOUT, ProcessInterface::STDERR])->toBe([0, 1, 2]);
    $process->stop();
});

test('PRC-1: a command that does not exist makes Process::run() throw RuntimeException', function () {
    expect(fn () => Process::run('/nonexistent/binary_xyz'))
        ->toThrow(RuntimeException::class, 'Process could not be started');
});

/* ------------------------------------------------------------------ PRC-2 */

test('PRC-2: read() returns what the process wrote to STDOUT, inside a coroutine', function () {
    phasync::run(function () {
        $process = Process::run('echo', ['hello world']);
        expect($process->read())->toBe("hello world\n");
    });
});

test('PRC-2: read() works outside a coroutine too', function () {
    $process = Process::run('echo', ['hi']);
    expect($process->read())->toBe("hi\n");
});

test('PRC-2: STDOUT and STDERR are separate streams', function () {
    phasync::run(function () {
        $process = Process::run('sh', ['-c', 'echo out; echo err 1>&2']);
        expect($process->read(ProcessInterface::STDOUT))->toBe("out\n");
        expect($process->read(ProcessInterface::STDERR))->toBe("err\n");
    });
});

test('PRC-2: read() suspends the coroutine, returns what is available, and lets other coroutines run', function () {
    phasync::run(function () {
        $process = Process::run('sh', ['-c', 'echo a; sleep 0.3; echo b']);
        $ticks   = 0;
        $stop    = false;
        $ticker  = phasync::go(function () use (&$ticks, &$stop) {
            while (!$stop) {
                phasync::sleep(0.01);
                ++$ticks;
            }
        });
        expect($process->read())->toBe("a\n");
        expect($process->read())->toBe("b\n");
        $stop = true;
        phasync::await($ticker);
        expect($ticks)->toBeGreaterThan(5);
    });
});

test('PRC-2: arguments are passed as they are, without a shell', function () {
    phasync::run(function () {
        $process = Process::run('printf', ['%s', '$HOME && echo x']);
        expect($process->read())->toBe('$HOME && echo x');
    });
});

test('PRC-2: cwd and env are applied to the child', function () {
    phasync::run(function () {
        $process = Process::run('sh', ['-c', 'echo $PHASYNC_VALUE; pwd'], '/tmp', ['PHASYNC_VALUE' => 'bar', 'PATH' => \getenv('PATH')]);
        expect($process->read())->toBe("bar\n/tmp\n");
    });
});

/* ------------------------------------------------------------------ PRC-3 */

test('PRC-3: getExitCode() is false while the process runs, and the exit code afterwards', function () {
    phasync::run(function () {
        $process = Process::run('sh', ['-c', 'sleep 0.2; exit 3']);
        expect($process->isRunning())->toBeTrue();
        expect($process->getExitCode())->toBeFalse();
        expect($process->isStopped())->toBeFalse();

        phasync::sleep(0.5);
        expect($process->isRunning())->toBeFalse();
        expect($process->getExitCode())->toBe(3);
        expect($process->isStopped())->toBeFalse();
    });
});

test('PRC-3: a process that exits normally reports exit code 0', function () {
    phasync::run(function () {
        $process = Process::run('true');
        phasync::sleep(0.2);
        expect($process->getExitCode())->toBe(0);
    });
});

/* ------------------------------------------------------------------ PRC-4 */

test('PRC-4: write() feeds STDIN, and the child answers on STDOUT', function () {
    phasync::run(function () {
        $process = Process::run('cat');
        expect($process->write("ping\n"))->toBe(5);
        expect($process->read())->toBe("ping\n");
        $process->stop();
    });
});

test('PRC-4: closing the STDIN stream makes cat exit with code 0', function () {
    phasync::run(function () {
        $process = Process::run('cat');
        \fclose($process->getStream(ProcessInterface::STDIN));
        phasync::sleep(0.3);
        expect($process->isRunning())->toBeFalse();
        expect($process->getExitCode())->toBe(0);
    });
});

test('PRC-4: getStream() returns the pipe for each standard descriptor and null for anything else', function () {
    $process = Process::run('cat');
    expect(\is_resource($process->getStream(ProcessInterface::STDIN)))->toBeTrue();
    expect(\is_resource($process->getStream(ProcessInterface::STDOUT)))->toBeTrue();
    expect(\is_resource($process->getStream(ProcessInterface::STDERR)))->toBeTrue();
    expect($process->getStream(7))->toBeNull();
    expect(\stream_get_meta_data($process->getStream(ProcessInterface::STDOUT))['blocked'])->toBeFalse();
    $process->stop();
});

/* ------------------------------------------------------------------ PRC-5 */

test('PRC-5: stop() ends a running process with SIGTERM at once, and later calls are harmless', function () {
    phasync::run(function () {
        $process = Process::run('cat');
        $start   = \microtime(true);
        expect($process->stop())->toBeTrue();
        expect(\microtime(true) - $start)->toBeLessThan(1.0);
        expect($process->isRunning())->toBeFalse();
        expect($process->getExitCode())->toBe(-1);

        expect($process->write('x'))->toBeFalse();
        expect($process->sendSignal())->toBeFalse();
        expect($process->stop())->toBeTrue();
    });
});

test('PRC-5: stop() also works outside a coroutine', function () {
    $process = Process::run('cat');
    expect($process->stop())->toBeTrue();
    expect($process->isRunning())->toBeFalse();
});

test('PRC-5: sendSignal() delivers a signal to a running process and reports whether it was sent', function () {
    phasync::run(function () {
        $process = Process::run('sleep', ['5']);
        expect($process->sendSignal(15))->toBeTrue();
        phasync::sleep(0.2);
        expect($process->isRunning())->toBeFalse();
        expect($process->getExitCode())->toBe(-1);
        expect($process->sendSignal(15))->toBeFalse();
    });
});

/* ------------------------------------------------------------------ PRC-6 */

test('PRC-6: once the exit has been observed the pipes are closed, so unread output is lost and read() throws IOException', function () {
    // isRunning() and getExitCode() poll the process and close the pipes as soon as it has
    // terminated, even when output was never read.
    phasync::run(function () {
        $process = Process::run('echo', ['lost output']);
        phasync::sleep(0.2);
        expect($process->isRunning())->toBeFalse();
        expect(fn () => $process->read())->toThrow(IOException::class, 'Not a valid stream resource');
        expect(\is_resource($process->getStream(ProcessInterface::STDOUT)))->toBeFalse();
    });
})->group('surprise');
