<?php

namespace phasync\Util;

/**
 * The factory class for launching phasync background processes.
 */
final class Process
{
    /**
     * Launch a process that will run in the background. The process can be interacted with via the
     * STDIN, STDOUT and STDERR streams.
     *
     * Works the same way on POSIX and Windows; see {@see ProcessRunner} for the platform
     * differences that remain (mainly around signals).
     *
     * @param string      $command   The command to execute
     * @param array       $arguments An array of arguments (will be escaped with {@see escapeshellarg()})
     * @param string|null $cwd       The current working directory for the child process
     * @param array|null  $env       The environment variables for the child process (if null, the current env is inherited)
     */
    public static function run(string $command, array $arguments=[], ?string $cwd=null, ?array $env=null): ProcessInterface
    {
        return new ProcessRunner([$command, ...$arguments], $cwd, $env);
    }
}
