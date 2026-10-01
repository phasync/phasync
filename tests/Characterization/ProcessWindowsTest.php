<?php

/*
 * Characterization tests for phasync\Util\Process and ProcessRunner on Windows
 * (docs/SEMANTICS.md section 14, PRC-1 .. PRC-6). The POSIX equivalents, run on the same PRC
 * IDs with POSIX commands (sh, true, printf), live in ProcessTest.php.
 *
 * There is no external command guaranteed to exist on every Windows runner, so every child here
 * is `php -r <script>` (PHP_BINARY itself, or a bare 'php' where the test is specifically about
 * PATH/PATHEXT resolution) -- never a shell, same as the POSIX file.
 *
 * These pin how the code behaves TODAY. A failing test means "stop and tell the
 * maintainer" (see tests/Characterization/README.md).
 */

use phasync\IOException;
use phasync\Util\Process;
use phasync\Util\ProcessInterface;
use phasync\Util\ProcessRunner;

uses()->group('characterization');

if (\PHP_OS_FAMILY !== 'Windows') {
    test('PRC-1: this file exercises Windows-only behaviour; see ProcessTest.php')->skip('Windows only');

    return;
}

if (\PHP_VERSION_ID < 80300) {
    test('PRC-1: on PHP before 8.3, starting a process throws LogicException', function () {
        expect(fn () => Process::run(\PHP_BINARY, ['-v']))->toThrow(LogicException::class, 'needs PHP 8.3 or later on Windows');
    });

    return;
}

/**
 * A `cat`-alike: forwards whatever it reads from STDIN to STDOUT immediately, byte for byte,
 * until STDIN reaches EOF.
 */
function pwt_cat(): array
{
    return [\PHP_BINARY, ['-r', 'while (!feof(STDIN)) { $c = fread(STDIN, 8192); if ($c === false || $c === "") break; fwrite(STDOUT, $c); }']];
}

/* ------------------------------------------------------------------ PRC-1 */

test('PRC-1: Process::run() returns a ProcessRunner, the same class as on POSIX', function () {
    $process = Process::run('php', ['-r', 'exit(0);']);
    expect($process)->toBeInstanceOf(ProcessRunner::class);
    expect($process)->toBeInstanceOf(ProcessInterface::class);
    $process->stop();
});

// PRC-1 on Windows: command resolution follows cmd.exe's own rule -- a literal path (with or
// without its extension) if the command contains a `\`, `/` or drive letter, otherwise a PATH
// search trying each PATHEXT extension in turn -- and throws before proc_open() is called at
// all if nothing launchable is found (see src/Process/ProcessRunner.php::resolveCommandWindows).

test('PRC-1: an absolute path that does not exist throws RuntimeException', function () {
    $path = 'C:\\nonexistent_phasync_dir\\binary_xyz.exe';
    expect(fn () => Process::run($path))
        ->toThrow(RuntimeException::class, "'{$path}' is not an executable file");
});

test('PRC-1: a relative path resolved against $cwd that does not exist throws RuntimeException', function () {
    expect(fn () => Process::run('.\\nope', [], \sys_get_temp_dir()))
        ->toThrow(RuntimeException::class, "'.\\nope' is not an executable file");
});

test('PRC-1: a bare command name not found in PATH throws RuntimeException', function () {
    expect(fn () => Process::run('nonexistent_bare_command_xyz_123'))
        ->toThrow(RuntimeException::class, "'nonexistent_bare_command_xyz_123' was not found in PATH");
});

test('PRC-1: a bare command name found in PATH is resolved via PATHEXT and runs', function () {
    $process = Process::run('php', ['-r', 'exit(0);']);
    expect($process)->toBeInstanceOf(ProcessRunner::class);
    $process->stop();
});

test('PRC-1: an existing file whose extension is not in PATHEXT throws RuntimeException', function () {
    $path = \tempnam(\sys_get_temp_dir(), 'prc-noexec') . '.txt';
    \file_put_contents($path, "not executable\n");
    try {
        expect(fn () => Process::run($path))->toThrow(RuntimeException::class, "'{$path}' is not an executable file");
    } finally {
        \unlink($path);
    }
});

test('PRC-1: a .bat script runs, through the cmd.exe that Windows starts for batch files [SURPRISE]', function () {
    $path = \tempnam(\sys_get_temp_dir(), 'prc-bat') . '.bat';
    \file_put_contents($path, "@echo off\r\necho hi\r\n");
    try {
        expect(\trim(Process::run($path)->read()))->toBe('hi');
    } finally {
        \unlink($path);
    }
})->group('surprise');

test('PRC-1: an empty PATH makes every bare command throw RuntimeException', function () {
    expect(fn () => Process::run('php', [], null, ['PATH' => '']))
        ->toThrow(RuntimeException::class, "'php' was not found (PATH is empty)");
});

test('PRC-1: a custom PATH is searched instead of the inherited one', function () {
    expect(fn () => Process::run('php', [], null, ['PATH' => \sys_get_temp_dir()]))
        ->toThrow(RuntimeException::class, "'php' was not found in PATH");
    $process = Process::run('php', ['-r', 'exit(0);'], null, ['PATH' => \dirname(\PHP_BINARY)]);
    $process->stop();
});

/* ------------------------------------------------------------------ PRC-2 */

test('PRC-2: read() returns what the process wrote to STDOUT, inside a coroutine', function () {
    phasync::run(function () {
        $process = Process::run(\PHP_BINARY, ['-r', 'fwrite(STDOUT, "hello world\n"); fflush(STDOUT);']);
        expect($process->read())->toBe("hello world\n");
    });
});

test('PRC-2: read() works outside a coroutine too', function () {
    $process = Process::run(\PHP_BINARY, ['-r', 'fwrite(STDOUT, "hi\n"); fflush(STDOUT);']);
    expect($process->read())->toBe("hi\n");
});

test('PRC-2: STDOUT and STDERR are separate streams', function () {
    phasync::run(function () {
        $process = Process::run(\PHP_BINARY, ['-r', 'fwrite(STDOUT, "out\n"); fflush(STDOUT); fwrite(STDERR, "err\n"); fflush(STDERR);']);
        expect($process->read(ProcessInterface::STDOUT))->toBe("out\n");
        expect($process->read(ProcessInterface::STDERR))->toBe("err\n");
    });
});

test('PRC-2: read() suspends the coroutine, returns what is available, and lets other coroutines run', function () {
    phasync::run(function () {
        $process = Process::run(\PHP_BINARY, ['-r', 'fwrite(STDOUT, "a\n"); fflush(STDOUT); usleep(300000); fwrite(STDOUT, "b\n"); fflush(STDOUT);']);
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
    // bypass_shell means CreateProcess() is called directly: '&&' reaches the child as a plain
    // argv entry, never a cmd.exe command separator.
    phasync::run(function () {
        $process = Process::run(\PHP_BINARY, ['-r', 'fwrite(STDOUT, $argv[1]); fflush(STDOUT);', '%HOMEPATH% && echo x']);
        expect($process->read())->toBe('%HOMEPATH% && echo x');
    });
});

test('PRC-2: cwd and env are applied to the child', function () {
    phasync::run(function () {
        $cwd                   = \sys_get_temp_dir();
        $process               = Process::run(\PHP_BINARY, ['-r', 'fwrite(STDOUT, getenv("PHASYNC_VALUE") . "|" . getcwd()); fflush(STDOUT);'], $cwd, ['PHASYNC_VALUE' => 'bar', 'PATH' => \getenv('PATH')]);
        [$value, $reportedCwd] = \explode('|', $process->read(), 2);
        expect($value)->toBe('bar');
        expect(\rtrim(\strtolower($reportedCwd), '\\/'))->toBe(\rtrim(\strtolower($cwd), '\\/'));
    });
});

/* ------------------------------------------------------------------ PRC-3 */

test('PRC-3: getExitCode() is false while the process runs, and the exit code afterwards', function () {
    phasync::run(function () {
        $process = Process::run(\PHP_BINARY, ['-r', 'usleep(200000); exit(3);']);
        expect($process->isRunning())->toBeTrue();
        expect($process->getExitCode())->toBeFalse();

        phasync::sleep(0.5);
        expect($process->isRunning())->toBeFalse();
        expect($process->getExitCode())->toBe(3);
    });
});

test('PRC-3: a process that exits normally reports exit code 0', function () {
    phasync::run(function () {
        $process = Process::run(\PHP_BINARY, ['-r', 'exit(0);']);
        phasync::sleep(0.2);
        expect($process->getExitCode())->toBe(0);
    });
});

/* ------------------------------------------------------------------ PRC-4 */

test('PRC-4: write() feeds STDIN, and the child answers on STDOUT', function () {
    phasync::run(function () {
        [$cmd, $args]  = pwt_cat();
        $process       = Process::run($cmd, $args);
        expect($process->write("ping\n"))->toBe(5);
        expect($process->read())->toBe("ping\n");
        $process->stop();
    });
});

test('PRC-4: closing the STDIN stream makes the child exit with code 0', function () {
    phasync::run(function () {
        [$cmd, $args]  = pwt_cat();
        $process       = Process::run($cmd, $args);
        \fclose($process->getStream(ProcessInterface::STDIN));
        phasync::sleep(0.3);
        expect($process->isRunning())->toBeFalse();
        expect($process->getExitCode())->toBe(0);
    });
});

test('PRC-4: getStream() returns the socket for each standard descriptor and null for anything else', function () {
    [$cmd, $args]  = pwt_cat();
    $process       = Process::run($cmd, $args);
    expect(\is_resource($process->getStream(ProcessInterface::STDIN)))->toBeTrue();
    expect(\is_resource($process->getStream(ProcessInterface::STDOUT)))->toBeTrue();
    expect(\is_resource($process->getStream(ProcessInterface::STDERR)))->toBeTrue();
    expect($process->getStream(7))->toBeNull();
    expect(\stream_get_meta_data($process->getStream(ProcessInterface::STDOUT))['blocked'])->toBeFalse();
    $process->stop();
});

/* ------------------------------------------------------------------ PRC-5 */

test('PRC-5: stop() ends a running process at once, and later calls are harmless', function () {
    phasync::run(function () {
        [$cmd, $args]  = pwt_cat();
        $process       = Process::run($cmd, $args);
        $start         = \microtime(true);
        expect($process->stop())->toBeTrue();
        expect(\microtime(true) - $start)->toBeLessThan(1.0);
        expect($process->isRunning())->toBeFalse();

        expect($process->write('x'))->toBeFalse();
        expect($process->sendSignal())->toBeFalse();
        expect($process->stop())->toBeTrue();
    });
});

test('PRC-5: sendSignal() forcibly ends the process regardless of the signal value', function () {
    // proc_terminate() on Windows cannot deliver a specific signal: every value hard-kills.
    phasync::run(function () {
        $process = Process::run(\PHP_BINARY, ['-r', 'sleep(5);']);
        expect($process->sendSignal(15))->toBeTrue(); // "SIGTERM" -- still a hard kill here
        phasync::sleep(0.3);
        expect($process->isRunning())->toBeFalse();
        expect($process->sendSignal(15))->toBeFalse();
    });
});

test('PRC-5: sigstop() cannot pause a process on Windows -- it ends it instead, and isStopped() stays false', function () {
    phasync::run(function () {
        $process = Process::run(\PHP_BINARY, ['-r', 'sleep(5);']);
        expect($process->isRunning())->toBeTrue();
        $process->sigstop();
        phasync::sleep(0.3);
        expect($process->isStopped())->toBeFalse();
        expect($process->isRunning())->toBeFalse();
    });
})->group('surprise');

/* ------------------------------------------------------------------ PRC-6 */

test('PRC-6: once the exit has been observed the pipes are closed, so unread output is lost and read() throws IOException', function () {
    phasync::run(function () {
        $process = Process::run(\PHP_BINARY, ['-r', 'fwrite(STDOUT, "lost output\n"); fflush(STDOUT);']);
        phasync::sleep(0.3);
        expect($process->isRunning())->toBeFalse();
        expect(fn () => $process->read())->toThrow(IOException::class);
        expect(\is_resource($process->getStream(ProcessInterface::STDOUT)))->toBeFalse();
    });
})->group('surprise');
