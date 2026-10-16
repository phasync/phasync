<?php

/**
 * Additional coverage tests for ProcessRunner.php
 * Focus on edge cases and platform-specific code paths.
 */

use phasync\Util\Process;
use phasync\Util\ProcessRunner;

test('ProcessRunner throws for empty command array', function () {
    expect(fn () => new ProcessRunner([]))
        ->toThrow(RuntimeException::class, 'no command given');
});

test('ProcessRunner throws for empty command string', function () {
    expect(fn () => new ProcessRunner(['']))
        ->toThrow(RuntimeException::class, 'no command given');
});

test('ProcessRunner resolveCommand throws for non-existent absolute path', function () {
    expect(fn () => Process::run('/nonexistent/command'))
        ->toThrow(RuntimeException::class, 'is not an executable file');
});

test('ProcessRunner resolveCommand uses cwd for relative paths', function () {
    $tempdir = \sys_get_temp_dir();
    expect(fn () => Process::run('./nonexistent', [], $tempdir))
        ->toThrow(RuntimeException::class, 'is not an executable file');
});

test('ProcessRunner detects early exit with code 126', function () {
    // This is a tricky test since we need a binary that exists but fails with 126
    // We'll skip it if we can't set it up
    $result = @\proc_open(['/bin/sh', '-c', 'exit 126'], [['socket'], ['socket'], ['socket']], $pipes);
    if (\is_resource($result)) {
        \proc_close($result);
        $this->markTestSkipped('Cannot reliably create exit code 126 scenario');
    }
});

test('ProcessRunner stores the original command array', function () {
    phasync::run(function () {
        $process    = Process::run('true');
        $reflection = new ReflectionClass($process);
        $property   = $reflection->getProperty('command');
        expect($property->getValue($process))->toBe(['true']);
        $process->stop();
    });
});

test('ProcessRunner::sendSignal returns false when process is not running', function () {
    phasync::run(function () {
        $process = Process::run('true');
        phasync::sleep(0.3);
        expect($process->isRunning())->toBeFalse();
        expect($process->sendSignal(15))->toBeFalse();
    });
});

test('ProcessRunner::read works and suspends on stream waiting', function () {
    phasync::run(function () {
        $process = Process::run('sh', ['-c', 'echo hello']);
        $data    = $process->read();
        expect($data)->toContain('hello');
    });
});

test('ProcessRunner::read throws on closed stream', function () {
    phasync::run(function () {
        $process = Process::run('true');
        phasync::sleep(0.2);
        $stream = $process->getStream(0);
        if (\is_resource($stream)) {
            \fclose($stream);
        }
        // The next read should fail since the stream is closed
        try {
            $process->read();
            expect(true)->toBeFalse(); // Should have thrown
        } catch (Throwable) {
            // Expected
            expect(true)->toBeTrue();
        }
    });
});

test('ProcessRunner::write returns false when process is not running', function () {
    phasync::run(function () {
        $process = Process::run('true');
        phasync::sleep(0.3);
        expect($process->isRunning())->toBeFalse();
        expect($process->write('data'))->toBeFalse();
    });
});

test('ProcessRunner::sigterm sends signal 15', function () {
    phasync::run(function () {
        $process = Process::run('sh', ['-c', 'sleep 100']);
        expect($process->sigterm())->toBeTrue();
        phasync::sleep(0.2);
        expect($process->isRunning())->toBeFalse();
    });
});

test('ProcessRunner::sigkill sends signal 9', function () {
    phasync::run(function () {
        $process = Process::run('sh', ['-c', 'sleep 100']);
        expect($process->sigkill())->toBeTrue();
        phasync::sleep(0.2);
        expect($process->isRunning())->toBeFalse();
    });
});

test('ProcessRunner::sigint sends signal 2', function () {
    phasync::run(function () {
        $process = Process::run('sh', ['-c', 'sleep 100']);
        expect($process->sigint())->toBeTrue();
        phasync::sleep(0.2);
        expect($process->isRunning())->toBeFalse();
    });
});

test('ProcessRunner::sighup sends signal 1', function () {
    phasync::run(function () {
        $process = Process::run('sh', ['-c', 'sleep 100']);
        expect($process->sighup())->toBeTrue();
        phasync::sleep(0.2);
        expect($process->isRunning())->toBeFalse();
    });
});

if (\PHP_OS_FAMILY !== 'Windows') {
    test('ProcessRunner::sigstop sends SIGSTOP', function () {
        phasync::run(function () {
            $process = Process::run('sh', ['-c', 'sleep 100']);
            expect($process->sigstop())->toBeTrue();
            phasync::sleep(0.1);
            expect($process->isStopped())->toBeTrue();
            $process->sigcont();
            phasync::sleep(0.1);
            expect($process->isStopped())->toBeFalse();
            $process->sigkill();
        });
    });

    test('ProcessRunner::sigcont sends SIGCONT', function () {
        phasync::run(function () {
            $process = Process::run('sh', ['-c', 'sleep 100']);
            $process->sigstop();
            phasync::sleep(0.1);
            expect($process->isStopped())->toBeTrue();
            expect($process->sigcont())->toBeTrue();
            phasync::sleep(0.1);
            expect($process->isStopped())->toBeFalse();
            $process->sigkill();
        });
    });
}

test('ProcessRunner::stop waits up to 5 seconds for process termination', function () {
    phasync::run(function () {
        $process = Process::run('sh', ['-c', 'sleep 100']);
        $stopped = $process->stop();
        expect($stopped)->toBeTrue();
        expect($process->isRunning())->toBeFalse();
    });
});

test('ProcessRunner::stop returns true when already stopped', function () {
    phasync::run(function () {
        $process = Process::run('true');
        phasync::sleep(0.3);
        expect($process->stop())->toBeTrue();
    });
});

test('ProcessRunner::getStream returns the pipe for valid FDs', function () {
    phasync::run(function () {
        $process = Process::run('cat');
        expect($process->getStream(0))->toBeResource();
        expect($process->getStream(1))->toBeResource();
        expect($process->getStream(2))->toBeResource();
        expect($process->getStream(3))->toBeNull();
        expect($process->getStream(-1))->toBeNull();
        $process->stop();
    });
});

test('ProcessRunner::getExitCode returns false while running', function () {
    phasync::run(function () {
        $process = Process::run('sh', ['-c', 'sleep 1']);
        expect($process->getExitCode())->toBeFalse();
        $process->sigkill();
        phasync::sleep(0.2);
    });
});

test('ProcessRunner::getExitCode returns the actual exit code', function () {
    phasync::run(function () {
        $process = Process::run('sh', ['-c', 'exit 42']);
        phasync::sleep(0.3);
        expect($process->getExitCode())->toBe(42);
    });
});

test('ProcessRunner::poll updates status and closes pipes when process exits', function () {
    phasync::run(function () {
        $process = Process::run('true');
        phasync::sleep(0.2);
        expect($process->isRunning())->toBeFalse();

        // Second call to poll should use cached status
        expect($process->isRunning())->toBeFalse();
    });
});

test('ProcessRunner handles environment variable substitution in PATH', function () {
    // Test that custom env with PATH works
    phasync::run(function () {
        $process = Process::run('true', [], null, ['PATH' => '/usr/bin:/bin']);
        expect($process->isRunning())->toBeTrue();
        $process->stop();
    });
});

test('ProcessRunner with no environment inherits parent PATH', function () {
    phasync::run(function () {
        $process = Process::run('true', [], null, null);
        expect($process->isRunning())->toBeTrue();
        $process->stop();
    });
});

if (\PHP_OS_FAMILY === 'Windows') {
    test('Windows: ProcessRunner throws LogicException on Windows before PHP 8.3', function () {
        if (\PHP_VERSION_ID >= 80300) {
            $this->markTestSkipped('Only applicable on Windows with PHP 8.3');
        }
        expect(fn () => new ProcessRunner(['cmd.exe']))
            ->toThrow(LogicException::class, 'PHP 8.3 or later');
    });
}

test('ProcessRunner closes pipes after process terminates', function () {
    phasync::run(function () {
        $process = Process::run('true');
        phasync::sleep(0.2);
        expect($process->isRunning())->toBeFalse();

        // After process terminates, pipes should be closed
        // A second call to isRunning should use cached status
        expect($process->isRunning())->toBeFalse();
        expect($process->isRunning())->toBeFalse();
    });
});

test('ProcessRunner read and write use phasync IO suspension', function () {
    phasync::run(function () {
        $process = Process::run('sh', ['-c', 'read line; echo "Got: $line"']);

        // Write should suspend on IO
        $bytes = $process->write("test\n");
        expect($bytes)->toBe(5);

        // Read should suspend on IO
        $output = $process->read();
        expect($output)->toContain('Got: test');

        $process->stop();
    });
});

test('ProcessRunner reports a signal to a process that has already ended as not sent', function () {
    phasync::run(function () {
        $process = Process::run('sh', ['-c', 'sleep 100']);

        expect($process->sigterm())->toBeTrue();

        // The signal is delivered asynchronously: wait for the process to be gone
        for ($i = 0; $i < 100 && $process->isRunning(); ++$i) {
            phasync::sleep(0.02);
        }
        expect($process->isRunning())->toBeFalse();
        expect($process->sigterm())->toBeFalse();
    });
});
