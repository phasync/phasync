<?php

namespace phasync;

/**
 * This file provides an interface with functions having the same name as their native PHP
 * equivalents. By importing these functions into your file, you can easily replace the native
 * function with a coroutine aware version. Simply do:
 *
 * use function phasync\{
 *     sleep,
 *     file_get_contents,
 *     file_put_contents,
 *     flock
 * };
 *
 * and you can use `sleep`, `file_get_contents`, `file_put_contents` and `flock` in your files,
 * and your code will be coroutine aware.
 */

/**
 * Pause execution from within a coroutine, allowing other coroutines
 * to act.
 *
 * @throws \FiberError
 * @throws \Throwable
 */
function sleep(float $seconds=0): void
{
    \phasync::sleep($seconds);
}

/**
 * Coroutine aware read of file contents, similar to {@see \file_get_contents()}.
 * Whenever IO blocks, other coroutines will be allowed to continue processing.
 *
 * @throws \Exception
 * @throws \FiberError
 * @throws \Throwable
 *
 * @return string
 */
function file_get_contents(string $filename): string|false
{
    return io::file_get_contents($filename);
}

/**
 * Coroutine aware write of data to a file.
 *
 * This function is modeled after file_put_contents(), but it performs
 * the write operation in a non-blocking manner using the event loop.
 *
 * @param string $filename path to the file where to write the data
 * @param mixed  $data     The data to write. Can be a string, an array or a stream resource.
 * @param int    $flags    Flags to modify the behavior of the write operation (e.g., FILE_APPEND).
 *
 * @throws \Exception if unable to open the file or write fails
 *
 * @return void
 */
function file_put_contents(string $filename, mixed $data, int $flags = 0): int|false
{
    return io::file_put_contents($filename, $data, $flags);
}

/**
 * Coroutine aware version of {@see \flock()}
 *
 * @throws \Exception
 * @throws \Throwable
 */
function flock($stream, int $operation, ?int &$would_block = null): bool
{
    return io::flock($stream, $operation, $would_block);
}
