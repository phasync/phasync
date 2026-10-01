<?php

use phasync\Util\Process;

/**
 * A child process built from `php -r <script>`, so these tests run identically on POSIX and
 * Windows without depending on external commands (sleep, cat, echo, ...) that are not
 * guaranteed to exist on every platform. This also runs with no shell involved, same as any
 * other command Process::run() launches.
 *
 * @return array{0: string, 1: string[]}
 */
function pt_php(string $script): array
{
    return [\PHP_BINARY, ['-r', $script]];
}

/**
 * A `cat`-alike: forwards whatever it reads from STDIN to STDOUT immediately, byte for byte,
 * until STDIN reaches EOF.
 */
function pt_cat(): array
{
    return pt_php('while (!feof(STDIN)) { $c = fread(STDIN, 8192); if ($c === false || $c === "") break; fwrite(STDOUT, $c); }');
}

test('launch background process and check running status', function () {
    [$cmd, $args]  = pt_php('sleep(10);');
    $process       = Process::run($cmd, $args);
    expect($process->isRunning())->toBeTrue();
    $process->stop();
});

test('stop process with SIGTERM', function () {
    [$cmd, $args]  = pt_php('sleep(10);');
    $process       = Process::run($cmd, $args);
    expect($process->isRunning())->toBeTrue();
    $process->sendSignal(15); // SIGTERM
    phasync::sleep(0.2); // Allow some time for the process to terminate
    expect($process->isRunning())->toBeFalse();
});

test('force stop process with SIGKILL', function () {
    [$cmd, $args]  = pt_php('sleep(10);');
    $process       = Process::run($cmd, $args);
    expect($process->isRunning())->toBeTrue();
    $process->sendSignal(9); // SIGKILL
    phasync::sleep(0.2); // Allow some time for the process to terminate
    expect($process->isRunning())->toBeFalse();
});

test('send SIGINT to process', function () {
    [$cmd, $args]  = pt_php('sleep(10);');
    $process       = Process::run($cmd, $args);
    expect($process->isRunning())->toBeTrue();
    $process->sendSignal(2); // SIGINT
    phasync::sleep(0.2); // Allow some time for the process to handle the signal
    expect($process->isRunning())->toBeFalse();
});

test('send SIGSTOP and SIGCONT to process', function () {
    [$cmd, $args]  = pt_php('sleep(10);');
    $process       = Process::run($cmd, $args);
    expect($process->isRunning())->toBeTrue();
    $process->sendSignal(19); // SIGSTOP
    phasync::sleep(0.1); // Allow some time for the process to stop
    expect($process->isStopped())->toBeTrue();
    $process->sendSignal(18); // SIGCONT
    phasync::sleep(0.1); // Allow some time for the process to continue
    expect($process->isStopped())->toBeFalse();
    expect($process->isRunning())->toBeTrue();
    $process->stop();
})->skip(\PHP_OS_FAMILY === 'Windows', 'Windows has no pause/resume primitive: proc_terminate() always hard-kills');

test('read and write to process', function () {
    [$cmd, $args]  = pt_cat();
    $process       = Process::run($cmd, $args);
    expect($process->isRunning())->toBeTrue();

    // Write data to the process
    $writeData = 'Hello, Process!';
    $process->write($writeData);

    // Read the data back from the process
    $readData = $process->read();
    expect(\trim($readData))->toBe($writeData);

    $process->stop();
});

test('check exit code after process completes', function () {
    [$cmd, $args]  = pt_php('usleep(200000); exit(0);');
    $process       = Process::run($cmd, $args);
    \usleep(700000); // Wait for the process to complete
    expect($process->isRunning())->toBeFalse();
    expect($process->getExitCode())->toBe(0);
});
