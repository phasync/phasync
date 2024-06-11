<?php

namespace phasync;

/**
 * This file provides an interface with functions having the same name as their native PHP
 * equivalents. By importing these functions into your file, you can easily replace the native
 * function with a coroutine aware version. Simply do:
 *
 * use function phasync\{
 *     fread,
 *     fwrite,
 *     file_get_contents
 * };
 *
 * and you can use `fread`, `fwrite` and `file_get_contents` in your files, and your code will
 * be coroutine aware.
 */

/**
 * Run a coroutine synchronously, and await the result. You can
 * nest calls to run() within other coroutines.
 *
 * @param array $args
 *
 * @deprecated Use {@see \phasync::run()} instead
 *
 * @throws \FiberError
 * @throws \Throwable
 */
function run(\Closure $coroutine, mixed ...$args): mixed
{
    return \phasync::run($coroutine, $args);
}

/**
 * Run a coroutine asynchronously. The return value is a {@see Fiber}
 * instance. You can use {@see await()} to resolve the return value.
 *
 * @param array $args
 *
 * @deprecated Use {@see \phasync::go()} instead
 *
 * @throws \FiberError
 * @throws \Throwable
 */
function go(\Closure $coroutine, mixed ...$args): \Fiber
{
    return \phasync::go($coroutine, ...$args);
}

/**
 * Wait for a coroutine to complete and return the result. If exceptions
 * are thrown in the coroutine, they will be thrown here.
 *
 * @deprecated Use {@see \phasync::await()} instead
 *
 * @throws \Throwable
 */
function await(\Fiber $fiber): mixed
{
    return \phasync::await($fiber);
}

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
 * Pause the coroutine until there are no coroutines that will run immediately,
 * effectively waiting until the entire application is waiting for IO operations
 * or timers to complete.
 */
function idle(?float $timeout=null): void
{
    \phasync::idle($timeout);
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
 * Coroutine aware read until EOF from a given stream resource, similar to {@see \stream_get_contents()}.
 * This function allows the event loop to continue processing other tasks whenever IO would block.
 *
 * @param resource $stream    the stream resource from which to read
 * @param int|null $maxLength Maximum bytes to read. Null for no limit, until EOF.
 * @param int      $offset    seek to the specified offset before reading (if the stream supports seeking)
 *
 * @throws \Exception  if unable to seek in the stream or other reading errors occur
 * @throws \FiberError if called outside a coroutine context where necessary
 * @throws \Throwable  for any unexpected errors during operation
 *
 * @return string|false the read data on success, or false on failure
 */
function stream_get_contents($stream, ?int $maxLength = null, int $offset = -1): string|false
{
    return stream_get_contents($stream, $maxLength, $offset);
}

/**
 * Coroutine aware binary-safe file read
 *
 * @param resource $stream the stream resource to read from
 * @param int      $length maximum number of bytes to read
 *
 * @return string|false the read data on success, or false on failure
 */
function fread($stream, int $length): string|false
{
    return io::fread($stream, $length);
}

/**
 * Coroutine aware get line from file pointer
 *
 * @param resource $stream the stream resource to read from
 * @param int      $length maximum number of bytes to read
 *
 * @return string|false the read data on success, or false on failure
 */
function fgets($stream, ?int $length = null): string|false
{
    return io::fgets($stream, $length);
}

/**
 * Coroutine aware get character from file pointer.
 *
 * @param resource $stream the stream resource to read from
 *
 * @return string|false the read data on success, or false on failure
 */
function fgetc($stream): string|false
{
    return io::fgetc($stream);
}

/**
 * Coroutine aware get line from file pointer and parse for CSV fields
 *
 * @param resource $stream the stream resource to read from
 * @param int      $length maximum number of bytes to read
 *
 * @throws \Exception if the read operation fails
 *
 * @return string|false the read data on success, or false on failure
 */
function fgetcsv($stream, ?int $length = null, string $separator = ',', string $enclosure = '"', string $escape = '\\'): array|false
{
    return io::fgetcsv($stream, $length, $separator, $enclosure, $escape);
}

/**
 * Coroutine aware format line as CSV and write to file pointer
 *
 * @param resource $stream
 *
 * @throws \TypeError
 */
function fputcsv($stream, array $fields, string $separator = ',', string $enclosure = '"', string $escape = '\\', string $eol = "\n"): int|false
{
    return io::fputcsv($stream, $fields, $separator, $enclosure, $escape, $eol);
}

/**
 * Coroutine aware version of {@see \fwrite()}
 *
 * @param resource $stream the stream resource to write to
 * @param string   $data   the data to write
 *
 * @throws \Exception if the write operation fails
 *
 * @return int|false the number of bytes written, or false on failure
 */
function fwrite($stream, string $data): int|false
{
    return io::fwrite($stream, $data);
}

/**
 * Coroutine aware version of {@see \ftruncate()}
 *
 * @param resource $stream the stream resource to write to
 * @param int      $size   the size to truncate to
 *
 * @return int|false returns true on success or false on failure
 */
function ftruncate($stream, int $size): int|false
{
    return io::ftruncate($stream, $size);
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
