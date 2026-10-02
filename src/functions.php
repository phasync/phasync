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
 * Suspends the current coroutine for `$seconds` seconds, letting the others run.
 *
 * The same as {@see \phasync::sleep()}, under the name of the native function: with
 * `use function phasync\sleep;` in a file, its `sleep()` calls wait without blocking the process. The
 * native `sleep()` takes whole seconds; this takes a float. Outside a coroutine it blocks, as
 * `usleep()` does.
 *
 * ```php
 * use function phasync\sleep;
 *
 * phasync::run(function () {
 *     phasync::go(fn() => print("tick\n"));
 *     sleep(0.5);                        // the coroutine above runs meanwhile
 * });
 * ```
 *
 * @param float $seconds how long to wait; 0 or less only lets the other coroutines run first
 *
 * @see \phasync::sleep
 */
function sleep(float $seconds=0): void
{
    \phasync::sleep($seconds);
}

/**
 * Reads a whole file, letting other coroutines run while it waits.
 *
 * Inside a coroutine, the file is read in 64 KiB chunks, waiting with `phasync::readable()` between
 * them. Outside one, it is the native `file_get_contents()`. Unlike the native function it takes no
 * other arguments, and it throws instead of returning false when the file cannot be opened.
 *
 * ```php
 * use function phasync\file_get_contents;
 *
 * $config = file_get_contents(__DIR__ . '/config.json');
 * ```
 *
 * @param string $filename the file to read
 *
 * @throws \Exception when the file cannot be opened or a read fails
 *
 * @see file_put_contents
 */
function file_get_contents(string $filename): string|false
{
    return io::file_get_contents($filename);
}

/**
 * Writes a file, letting other coroutines run while it waits.
 *
 * Inside a coroutine, it truncates the file (or appends, with `FILE_APPEND`) and writes `$data` with
 * `phasync::writable()` between the writes. Outside one, it is the native `file_put_contents()`.
 * Only `FILE_APPEND` and `LOCK_EX` of the native flags are handled.
 *
 * A stream resource as `$data` fails inside a coroutine with a `TypeError`, after the file was truncated.
 *
 * ```php
 * use function phasync\file_put_contents;
 *
 * file_put_contents('/tmp/log.txt', "started\n", FILE_APPEND);
 * ```
 *
 * @param string $filename the file to write
 * @param mixed  $data     a string, or an array of strings that are joined
 * @param int    $flags    `FILE_APPEND` and `LOCK_EX`
 *
 * @return int|false the number of bytes written
 *
 * @throws \Exception when the file cannot be opened
 * @throws \RuntimeException when a write fails
 *
 * @see file_get_contents
 * @see flock
 */
function file_put_contents(string $filename, mixed $data, int $flags = 0): int|false
{
    return io::file_put_contents($filename, $data, $flags);
}

/**
 * Locks a file, letting other coroutines run while it waits for the lock.
 *
 * Inside a coroutine, a blocking lock request is retried with `LOCK_NB` and a `phasync::yield()`
 * between the attempts, until it is granted or fails for another reason than the file being locked.
 * With `LOCK_NB`, and outside a coroutine, it is the native `flock()`.
 *
 * ```php
 * use function phasync\flock;
 *
 * $fp = fopen('/tmp/job.lock', 'c');
 * flock($fp, LOCK_EX);    // other coroutines run while another process holds the lock
 * ```
 *
 * @param resource $stream       a stream resource
 * @param int      $operation    `LOCK_SH`, `LOCK_EX` or `LOCK_UN`, optionally with `LOCK_NB`
 * @param ?int     $would_block  set by the native `flock()` when `LOCK_NB` is given, or outside a coroutine; left alone otherwise
 *
 * @throws \TypeError when `$stream` is not a stream resource
 *
 * @see file_put_contents
 */
function flock($stream, int $operation, ?int &$would_block = null): bool
{
    return io::flock($stream, $operation, $would_block);
}
