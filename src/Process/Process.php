<?php

namespace phasync\Process;

/**
 * The factory class for launching phasync background processes.
 */
final class Process
{
    /**
     * Launch a process that will run in the background. The process can be interacted with via the
     * STDIN, STDOUT and STDERR streams.
     *
     * @param string      $command   The command to execute
     * @param array       $arguments An array of arguments (will be escaped with {@see escapeshellarg()})
     * @param string|null $cwd       The current working directory for the child process
     * @param array|null  $env       The environment variables for the child process (if null, the current env is inherited)
     *
     * @throws \LogicException on Windows, which has no working implementation yet
     */
    public static function run(string $command, array $arguments=[], ?string $cwd=null, ?array $env=null): ProcessInterface
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            throw new \LogicException('phasync\Process\Process::run() is not supported on Windows yet');
        }

        return new PosixProcessRunner([$command, ...$arguments], $cwd, $env);
    }
}
