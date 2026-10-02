<?php

namespace phasync\Util;

/**
 * A child process started with `Process::run()`, with its standard streams usable from coroutines.
 *
 * `read()` and `write()` wait for their stream without blocking the other coroutines. When the process has ended and `isRunning()` or another status call has noticed it, its streams are closed, so read the output before asking.
 *
 * ```php
 * phasync::run(function () {
 *     $cat = phasync\Util\Process::run('cat');
 *     $cat->write("ping\n");
 *     echo $cat->read();            // ping
 *     $cat->stop();
 * });
 * ```
 *
 * @see phasync\Util\Process::run
 */
interface ProcessInterface
{
    public const STDIN  = 0;
    public const STDOUT = 1;
    public const STDERR = 2;

    /**
     * Ends the process: SIGTERM, then SIGKILL after one second, and waits up to five seconds in all.
     *
     * @return bool true if the process is no longer running
     *
     * @see ProcessInterface::sendSignal
     */
    public function stop(): bool;

    /**
     * Returns true if the process is running.
     *
     * @see ProcessInterface::getExitCode
     */
    public function isRunning(): bool;

    /**
     * Returns true if the process is paused by a stop signal. Always false on Windows.
     *
     * @see ProcessInterface::isRunning
     */
    public function isStopped(): bool;

    /**
     * Returns the exit code of the process, or false while it runs.
     *
     * @return int|false false if the process is still running
     *
     * @see ProcessInterface::isRunning
     */
    public function getExitCode(): int|false;

    /**
     * Sends a signal to the process, and returns false without sending it if the process is not running.
     *
     * On Windows the signal cannot be chosen: every call ends the process at once.
     *
     * @param int $signal the signal number; 15 is SIGTERM
     *
     * @return bool true if the signal was sent
     *
     * @see ProcessInterface::stop
     */
    public function sendSignal(int $signal = 15): bool;

    /**
     * Waits until the stream has something to read, and returns what is there.
     *
     * Returns an empty string at the end of the stream. The stream is closed once the process has ended and was noticed to have: a read then throws an IOException.
     *
     * @param int $fd `ProcessInterface::STDOUT` or `STDERR`
     *
     * @return string|false what was read
     *
     * @throws \phasync\IOException if the stream is closed
     *
     * @see ProcessInterface::write
     */
    public function read(int $fd = self::STDOUT): string|false;

    /**
     * Waits until the stream can take data, and writes as much as it takes.
     *
     * @param string $data what to write
     * @param int    $fd   `ProcessInterface::STDIN`
     *
     * @return int|false the number of bytes written, which may be less than all of `$data`; false if the process is not running
     *
     * @throws \phasync\IOException if the stream is closed
     *
     * @see ProcessInterface::read
     */
    public function write(string $data, int $fd = self::STDIN): int|false;

    /**
     * Returns the stream of a standard descriptor, for `stream_select()`, `phasync::readable()` and the like.
     *
     * @param int $fd `ProcessInterface::STDIN`, `STDOUT` or `STDERR`
     *
     * @return resource|null null for any other number
     */
    public function getStream(int $fd): mixed;
}
