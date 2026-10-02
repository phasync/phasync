<?php

namespace phasync\Util;

/**
 * Starts a child process whose standard streams are used from coroutines without blocking the others.
 *
 * ```php
 * phasync::run(function () {
 *     $process = phasync\Util\Process::run('echo', ['hello']);
 *
 *     echo $process->read();                 // hello
 *     while ($process->isRunning()) {
 *         phasync::sleep(0.01);
 *     }
 *     var_dump($process->getExitCode());     // int(0)
 * });
 * ```
 *
 * @see phasync\Util\ProcessInterface
 * @see phasync\Util\ProcessRunner
 */
final class Process
{
    /**
     * Starts `$command` in the background and returns the object that controls it.
     *
     * Nothing goes through a shell: `$command` is searched for in `PATH` (or taken as a path when it contains a slash) and the arguments are passed as they are. Works the same on POSIX and Windows; the signals are the difference, see {@see ProcessRunner::sendSignal()}.
     *
     * @param string                    $command   the executable
     * @param array                     $arguments the arguments, one element each
     * @param string|null               $cwd       the working directory of the child; null: the current one
     * @param array<string,string>|null $env       the environment of the child, replacing the current one; null: inherit it
     *
     * @throws \RuntimeException if the command is not found or not executable, or cannot be started
     *
     * @return ProcessInterface the running process
     *
     * @see ProcessInterface::stop
     */
    public static function run(string $command, array $arguments=[], ?string $cwd=null, ?array $env=null): ProcessInterface
    {
        return new ProcessRunner([$command, ...$arguments], $cwd, $env);
    }
}
