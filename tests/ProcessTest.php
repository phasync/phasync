<?php

use phasync\Process\Process;

test('launch background process and check running status', function () {
    $process = Process::run('sleep', ['10']);
    expect($process->isRunning())->toBeTrue();
    $process->stop();
});

test('stop process with SIGTERM', function () {
    $process = Process::run('sleep', ['10']);
    expect($process->isRunning())->toBeTrue();
    $process->sendSignal(\SIGTERM);
    phasync::sleep(0.1); // Allow some time for the process to terminate
    expect($process->isRunning())->toBeFalse();
});

test('force stop process with SIGKILL', function () {
    $process = Process::run('sleep', ['10']);
    expect($process->isRunning())->toBeTrue();
    $process->sendSignal(\SIGKILL);
    phasync::sleep(0.1); // Allow some time for the process to terminate
    expect($process->isRunning())->toBeFalse();
});

test('send SIGINT to process', function () {
    $process = Process::run('sleep', ['10']);
    expect($process->isRunning())->toBeTrue();
    $process->sendSignal(\SIGINT);
    phasync::sleep(0.1); // Allow some time for the process to handle the signal
    expect($process->isRunning())->toBeFalse();
});

test('send SIGSTOP and SIGCONT to process', function () {
    $process = Process::run('sleep', ['10']);
    expect($process->isRunning())->toBeTrue();
    $process->sendSignal(\SIGSTOP);
    phasync::sleep(0.1); // Allow some time for the process to stop
    expect($process->isStopped())->toBeTrue();
    $process->sendSignal(\SIGCONT);
    phasync::sleep(0.1); // Allow some time for the process to continue
    expect($process->isStopped())->toBeFalse();
    expect($process->isRunning())->toBeTrue();
    $process->stop();
});

test('read and write to process', function () {
    $process = Process::run('cat');
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
    $process = Process::run('sleep', ['1']);
    \usleep(2000000); // Wait for the process to complete
    expect($process->isRunning())->toBeFalse();
    expect($process->getExitCode())->toBe(0);
});
