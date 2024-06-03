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
     */
    public static function run(string $command, array $arguments=[], ?string $cwd=null, ?array $env=null): ProcessInterface
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            return new WindowsProcessRunner([$command, ...$arguments], $cwd, $env);
        }

        return new PosixProcessRunner([$command, ...$arguments], $cwd, $env);
    }
}
