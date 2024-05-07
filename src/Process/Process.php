<?php
namespace phasync\Process;

use phasync\Process\PosixProcessRunner;
use phasync\Process\ProcessInterface;
use phasync\Process\WindowsProcessRunner;

/**
 * The factory class for launching phasync background processes.
 * 
 * @package phasync
 */
final class Process {
    /**
     * Launch a process that will run in the background. The process can be interacted with via the
     * STDIN, STDOUT and STDERR streams.
     * 
     * @param string $command The command to execute
     * @param array $arguments An array of arguments (will be escaped with {@see escapeshellarg()})
     * @param null|string $cwd The current working directory for the child process
     * @param null|array $env The environment variables for the child process (if null, the current env is inherited)
     * @return ProcessInterface 
     */
    public static function background(string $command, array $arguments=[], ?string $cwd=null, ?array $env=null): ProcessInterface {
        if (\PHP_OS_FAMILY === 'Windows') {
            return new WindowsProcessRunner([$command, ...$arguments], $cwd, $env);
        } else {
            return new PosixProcessRunner([$command, ...$arguments], $cwd, $env);
        }
    }
}