<?php

use phasync\Util\Process;
use phasync\Util\ProcessRunner;

/**
 * Call a private static method of ProcessRunner (the Windows resolvers are plain string/file
 * logic and can be exercised on any platform).
 */
function prr_call(string $method, mixed ...$args): mixed
{
    return (new ReflectionMethod(ProcessRunner::class, $method))->invoke(null, ...$args);
}

/**
 * A fresh temp directory; removed with prr_rmtree().
 */
function prr_tmpdir(): string
{
    $dir = \sys_get_temp_dir() . '/prr-' . \bin2hex(\random_bytes(6));
    \mkdir($dir);

    return $dir;
}

function prr_rmtree(string $dir): void
{
    foreach (\scandir($dir) as $entry) {
        if ('.' === $entry || '..' === $entry) {
            continue;
        }
        $path = $dir . '/' . $entry;
        \is_dir($path) ? prr_rmtree($path) : \unlink($path);
    }
    \rmdir($dir);
}

test('proc_open failing on a file the kernel cannot execute is reported as a start failure', function () {
    $dir = prr_tmpdir();
    try {
        // Executable, but neither a binary nor a #! script: posix_spawn() fails with ENOEXEC
        \file_put_contents($dir . '/noshebang', "exit 0\n");
        \chmod($dir . '/noshebang', 0755);
        expect(fn () => new ProcessRunner([$dir . '/noshebang']))
            ->toThrow(RuntimeException::class, 'Process could not be started: Errno: 2; proc_open(): posix_spawn() failed');

        // A #! line pointing at a missing interpreter
        \file_put_contents($dir . '/badshebang', "#!/nonexistent/interpreter\n");
        \chmod($dir . '/badshebang', 0755);
        expect(fn () => new ProcessRunner([$dir . '/badshebang']))
            ->toThrow(RuntimeException::class, 'posix_spawn() failed');
    } finally {
        prr_rmtree($dir);
    }
});

test('a missing working directory is reported as a start failure', function () {
    expect(fn () => new ProcessRunner(['true'], '/nonexistent/working/dir'))
        ->toThrow(RuntimeException::class, 'Process could not be started');
});

test('the error handler used around proc_open is removed again after a failure', function () {
    $seen = [];
    \set_error_handler(function (int $code, string $message) use (&$seen) {
        $seen[] = $message;

        return true;
    });
    try {
        try {
            new ProcessRunner(['true'], '/nonexistent/working/dir');
        } catch (RuntimeException) {
        }
        \trigger_error('after', \E_USER_NOTICE);
    } finally {
        \restore_error_handler();
    }
    expect($seen)->toBe(['after']);
});

test('an empty entry in PATH is skipped and the following entries are searched', function () {
    phasync::run(function () {
        $process = Process::run('sleep', ['5'], null, ['PATH' => ':/nonexistent:/usr/bin:/bin']);
        expect($process->isRunning())->toBeTrue();
        expect($process->stop())->toBeTrue();
    });
});

test('an empty entry in PATH is not treated as the current directory', function () {
    $dir = prr_tmpdir();
    try {
        \file_put_contents($dir . '/prr-only-here', "#!/bin/sh\nexit 0\n");
        \chmod($dir . '/prr-only-here', 0755);
        expect(fn () => Process::run('prr-only-here', [], $dir, ['PATH' => ':']))
            ->toThrow(RuntimeException::class, "'prr-only-here' was not found in PATH");
    } finally {
        prr_rmtree($dir);
    }
});

test('a child that exits 127 before the parent first polls it is reported as a failed start', function () {
    // The exit is a race against the constructor's first poll. Pinning this process to one CPU
    // makes the child run first, which makes the race go the same way nearly every time.
    $taskset = \trim((string) \shell_exec('command -v taskset'));
    if ('' === $taskset) {
        $this->markTestSkipped('taskset is not available');
    }
    $pid  = \getmypid();
    $info = \shell_exec(\escapeshellarg($taskset) . ' -c -p ' . $pid . ' 2>&1');
    if (!\preg_match('/:\s*(\d+)\S*\s*$/', \trim((string) $info), $first) || !\preg_match('/:\s*(\S+)\s*$/', \trim((string) $info), $list)) {
        $this->markTestSkipped('cannot read the CPU affinity');
    }
    $original = $list[1];
    \shell_exec(\escapeshellarg($taskset) . ' -c -p ' . $first[1] . ' ' . $pid . ' 2>&1');

    $failure   = null;
    $processes = [];
    try {
        for ($i = 0; $i < 100 && null === $failure; ++$i) {
            try {
                $processes[] = new ProcessRunner(['sh', '-c', 'exit 127']);
            } catch (RuntimeException $e) {
                $failure = $e;
            }
        }
    } finally {
        \shell_exec(\escapeshellarg($taskset) . ' -c -p ' . $original . ' ' . $pid . ' 2>&1');
        foreach ($processes as $p) {
            $p->stop();
        }
    }
    expect($failure)->toBeInstanceOf(RuntimeException::class);
    expect($failure->getMessage())->toBe("Process could not be started: 'sh' exited immediately with code 127");
})->skip(\PHP_OS_FAMILY !== 'Linux', 'needs taskset');

test('stop escalates to SIGKILL when the process does not end within a second of SIGTERM', function () {
    phasync::run(function () {
        $process = Process::run('sleep', ['100']);
        // A stopped process cannot act on SIGTERM, only on SIGKILL
        expect($process->sigstop())->toBeTrue();
        // SIGSTOP is asynchronous: until it has taken effect a SIGTERM would still be acted on.
        // proc_get_status() reports the stop once only, so remember it.
        $stopped = false;
        for ($i = 0; $i < 100 && !$stopped; ++$i) {
            phasync::sleep(0.01);
            $stopped = $process->isStopped();
        }
        expect($stopped)->toBeTrue();
        $start = \microtime(true);
        expect($process->stop())->toBeTrue();
        expect(\microtime(true) - $start)->toBeGreaterThanOrEqual(1.0);
        expect($process->isRunning())->toBeFalse();
    });
});

test('the pipes of a process are closed once it is found to have ended', function () {
    phasync::run(function () {
        $process = Process::run('true');
        $stdout  = $process->getStream(1);
        for ($i = 0; $i < 100 && $process->isRunning(); ++$i) {
            phasync::sleep(0.01);
        }
        expect($process->isRunning())->toBeFalse();
        expect(\is_resource($stdout))->toBeFalse();
        expect(\is_resource($process->getStream(0)))->toBeFalse();
        expect(\is_resource($process->getStream(2)))->toBeFalse();
    });
});

test('polling a process whose pipes the caller already closed does not fail', function () {
    phasync::run(function () {
        $process = Process::run('sleep', ['5']);
        \fclose($process->getStream(0));
        expect($process->sigkill())->toBeTrue();
        for ($i = 0; $i < 100 && $process->isRunning(); ++$i) {
            phasync::sleep(0.01);
        }
        expect($process->isRunning())->toBeFalse();
    });
});

// Windows branches. The resolver is pure path/file logic, so it runs on every platform; on a POSIX
// host a "Windows path" is a file whose name contains a backslash.

test('windowsPathExt falls back to the default extensions', function () {
    $old = \getenv('PATHEXT');
    \putenv('PATHEXT');
    try {
        expect(prr_call('windowsPathExt'))->toBe(['.COM', '.EXE', '.BAT', '.CMD']);
    } finally {
        false === $old ? \putenv('PATHEXT') : \putenv('PATHEXT=' . $old);
    }
});

test('windowsPathExt reads PATHEXT and drops empty entries', function () {
    $old = \getenv('PATHEXT');
    \putenv('PATHEXT=.FOO;;.BAR;');
    try {
        expect(\array_values(prr_call('windowsPathExt')))->toBe(['.FOO', '.BAR']);
    } finally {
        false === $old ? \putenv('PATHEXT') : \putenv('PATHEXT=' . $old);
    }
});

test('windowsPathExt falls back to the defaults when PATHEXT holds only separators', function () {
    $old = \getenv('PATHEXT');
    \putenv('PATHEXT=;;');
    try {
        expect(prr_call('windowsPathExt'))->toBe(['.COM', '.EXE', '.BAT', '.CMD']);
    } finally {
        false === $old ? \putenv('PATHEXT') : \putenv('PATHEXT=' . $old);
    }
});

test('Windows path classification helpers', function () {
    expect(prr_call('hasWindowsPathSeparator', 'a\\b'))->toBeTrue();
    expect(prr_call('hasWindowsPathSeparator', 'a/b'))->toBeTrue();
    expect(prr_call('hasWindowsPathSeparator', 'ab'))->toBeFalse();

    expect(prr_call('hasWindowsDrive', 'C:foo'))->toBeTrue();
    expect(prr_call('hasWindowsDrive', 'a'))->toBeFalse();
    expect(prr_call('hasWindowsDrive', 'ab'))->toBeFalse();

    expect(prr_call('isWindowsAbsolute', 'C:\\foo'))->toBeTrue();
    expect(prr_call('isWindowsAbsolute', '\\foo'))->toBeTrue();
    expect(prr_call('isWindowsAbsolute', '/foo'))->toBeTrue();
    expect(prr_call('isWindowsAbsolute', 'foo\\bar'))->toBeFalse();
});

test('findWindowsExecutable honours the extension list', function () {
    $dir = prr_tmpdir();
    try {
        \touch($dir . '/a.exe');
        \touch($dir . '/b.EXE');
        \touch($dir . '/c.txt');
        \touch($dir . '/d.CMD');

        // Own extension is in the list: must exist as given
        expect(prr_call('findWindowsExecutable', $dir . '/a.exe', ['.EXE']))->toBe($dir . '/a.exe');
        expect(prr_call('findWindowsExecutable', $dir . '/b.EXE', ['.exe']))->toBe($dir . '/b.EXE');
        expect(prr_call('findWindowsExecutable', $dir . '/missing.exe', ['.EXE']))->toBeNull();

        // Extension-less: the list is tried in order and the first existing one wins
        expect(prr_call('findWindowsExecutable', $dir . '/a', ['.COM', '.exe']))->toBe($dir . '/a.exe');
        expect(prr_call('findWindowsExecutable', $dir . '/d', ['.EXE', '.CMD']))->toBe($dir . '/d.CMD');
        expect(prr_call('findWindowsExecutable', $dir . '/nothing', ['.EXE', '.CMD']))->toBeNull();

        // An existing file with an extension outside the list is not executable
        expect(prr_call('findWindowsExecutable', $dir . '/c.txt', ['.EXE']))->toBeNull();
    } finally {
        prr_rmtree($dir);
    }
});

test('resolveCommandWindows resolves a literal absolute path', function () {
    $dir = prr_tmpdir();
    try {
        \touch($dir . '/tool.EXE');
        expect(prr_call('resolveCommandWindows', $dir . '/tool', null, null))->toBe($dir . '/tool.EXE');
        expect(prr_call('resolveCommandWindows', $dir . '/tool.EXE', '/ignored', null))->toBe($dir . '/tool.EXE');
        expect(fn () => prr_call('resolveCommandWindows', $dir . '/gone', null, null))
            ->toThrow(RuntimeException::class, 'is not an executable file');
        expect(fn () => prr_call('resolveCommandWindows', 'C:gone', null, null))
            ->toThrow(RuntimeException::class, "'C:gone' is not an executable file");
    } finally {
        prr_rmtree($dir);
    }
});

test('resolveCommandWindows joins a relative path with the working directory using a backslash', function () {
    $dir = prr_tmpdir();
    try {
        // On POSIX the "directory" $dir\sub is one path component
        \mkdir($dir . '\\sub');
        \touch($dir . '\\sub/tool.EXE');
        expect(prr_call('resolveCommandWindows', 'sub/tool', $dir . '/', null))->toBe($dir . '\\sub/tool.EXE');
        expect(fn () => prr_call('resolveCommandWindows', 'sub/other', $dir, null))
            ->toThrow(RuntimeException::class, "'sub/other' is not an executable file");
    } finally {
        \unlink($dir . '\\sub/tool.EXE');
        \rmdir($dir . '\\sub');
        prr_rmtree($dir);
    }
});

test('resolveCommandWindows searches PATH for a bare name', function () {
    $dir = prr_tmpdir();
    $old = \getenv('PATHEXT');
    \putenv('PATHEXT');
    try {
        \mkdir($dir . '/bin');
        // Directory entry "<dir>/bin" + backslash + name is the file "bin\tool.EXE" on POSIX
        \touch($dir . '/bin\\tool.EXE');
        $searchPath = ':' . $dir . '/nothere:' . $dir . '/bin';

        expect(prr_call('resolveCommandWindows', 'tool', null, ['PATH' => $searchPath]))->toBe($dir . '/bin\\tool.EXE');
        // The env may spell it "Path"
        expect(prr_call('resolveCommandWindows', 'tool', null, ['Path' => $searchPath]))->toBe($dir . '/bin\\tool.EXE');
        expect(fn () => prr_call('resolveCommandWindows', 'absent', null, ['PATH' => $searchPath]))
            ->toThrow(RuntimeException::class, "'absent' was not found in PATH");
        expect(fn () => prr_call('resolveCommandWindows', 'tool', null, ['PATH' => '']))
            ->toThrow(RuntimeException::class, "'tool' was not found (PATH is empty)");
        // No PATH in the given env: left to the platform
        expect(prr_call('resolveCommandWindows', 'tool', null, []))->toBe('tool');
    } finally {
        false === $old ? \putenv('PATHEXT') : \putenv('PATHEXT=' . $old);
        \unlink($dir . '/bin\\tool.EXE');
        prr_rmtree($dir);
    }
});

test('resolveCommandWindows without env searches the PATH of this process', function () {
    expect(fn () => prr_call('resolveCommandWindows', 'prr-surely-not-a-command', null, null))
        ->toThrow(RuntimeException::class, "'prr-surely-not-a-command' was not found in PATH");
});

test('isStopped() stays true while the process is paused and turns false after sigcont()', function () {
    phasync::run(function () {
        $process = Process::run('sleep', ['100']);
        expect($process->sigstop())->toBeTrue();
        for ($i = 0; $i < 100 && !$process->isStopped(); ++$i) {
            phasync::sleep(0.01);
        }
        expect($process->isStopped())->toBeTrue();
        expect($process->isStopped())->toBeTrue();
        expect($process->sigcont())->toBeTrue();
        expect($process->isStopped())->toBeFalse();
        $process->stop();
    });
})->skip(\PHP_OS_FAMILY === 'Windows', 'no SIGSTOP on Windows');
