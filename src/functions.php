<?php

namespace phasync;

use Exception;
use Fiber;
use phasync;

/**
 * In addition to the functions defined here, the following classes
 * exist for managing the execution:
 *
 * {@see WaitGroup} is an effective way for waiting until
 * multiple coroutines have completed a task. It provides a similar
 * feature as Promise::all() for promise based asynchronous
 * applications.
 *
 * {@see Channel} is a utility for passing information between
 * coroutines. Channel provides a readable and a writable end. Multiple
 * coroutines can write and read, but messages will only be received
 * by one of the readers (the first available reader). Writers and readers
 * will block if there are no available readers or writers. Channels can
 * be buffered, which will allow a limited number of messages to be
 * stored in queue for an available reader.
 *
 * {@see phasync\Channel::select()} to enable a single coroutine to read
 * from multiple channels via a simple switch statement. Example:
 *
 * ```
 * switch (Channel::select($reader1, $reader2, $writer1)) {
 *   case $reader1: // Data is available on reader1, or it is failed (no writers exist)
 *   case $reader2: // Data is available on reader2, or it is failed (no writers exist)
 *   case $writer1: // Writing to $writer1 will not block because a reader is available to read
 * }
 * ```
 *
 * {@see Publisher} is a utility similar to Channel but where
 * all messages are received by all readers. This can be used for example
 * to broadcast messages or events to subscribers. All messages published
 * will be buffered, so only readers will be blocked when trying to read
 * from a publisher that has no new messages.
 */

/**
 * Run a coroutine synchronously, and await the result. You can
 * nest calls to run() within other coroutines.
 *
 * @param array $args
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
 * Asynchronously read a file contents, similar to {@see \file_get_contents()}.
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
    if (!\Fiber::getCurrent()) {
        return \file_get_contents($filename);
    }
    $fp = \fopen($filename, 'r');
    if (!$fp) {
        throw new \Exception("Unable to open file '$filename'");
    }

    \stream_set_blocking($fp, false);
    $content = '';

    try {
        while (!\feof($fp)) {
            // Assume `readable` is a function that waits until the file pointer is readable.
            $buffer = fread($fp, 65536);
            if (false === $buffer) {
                throw new \Exception("Read error with file '$filename'");
            }
            $content .= $buffer;
        }

        return $content;
    } finally {
        \fclose($fp); // Ensure the file pointer is always closed.
    }
}

/**
 * Writes data to a file asynchronously.
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
    if (!\Fiber::getCurrent()) {
        return \file_put_contents($filename, $data, $flags);
    }
    $context = \stream_context_create();
    $mode    = ($flags & FILE_APPEND) ? 'a' : 'w';

    $fp = \fopen($filename, $mode, false, $context);
    if (!$fp) {
        throw new \Exception("Unable to open file '$filename' for writing.");
    }

    \stream_set_blocking($fp, false);

    // If $data is a resource, get the content from the resource.
    if (\is_resource($data)) {
        $data = stream_get_contents($data);
    }

    // Convert $data to a string if it is an array.
    if (\is_array($data)) {
        $data = \implode('', $data);
    }

    try {
        $len     = \strlen($data);
        $written = 0;

        while ($written < $len) {
            $fwrite = fwrite($fp, \substr($data, $written));
            if (false === $fwrite) {
                throw new IOException("Failed to write to file '$filename'.");
            }
            $written += $fwrite;
        }

        return $written;
    } finally {
        \fclose($fp); // Ensure the file pointer is always closed.
    }
}

/**
 * Asynchronously reads until EOF from a given stream resource, similar to {@see \stream_get_contents()}.
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
    if (!\Fiber::getCurrent()) {
        return \stream_get_contents($stream, $maxLength, $offset);
    }
    if (!\is_resource($stream) || 'stream' !== \get_resource_type($stream)) {
        throw new \Exception('The provided argument is not a valid stream resource.');
    }

    // If offset is specified and valid, seek to it before reading
    if (-1 !== $offset) {
        if (!\fseek($stream, $offset)) {
            throw new \Exception("Failed to seek to offset $offset in the stream.");
        }
    }

    \stream_set_blocking($stream, false);
    $content   = '';
    $bytesRead = 0;

    while (!\feof($stream)) {
        $buffer = (null === $maxLength) ? fread($stream, 8192) : fread($stream, \min(8192, $maxLength - $bytesRead));
        if (false === $buffer) {
            // Depending on the implementation, you may want to return false or throw an exception
            throw new \Exception('Failed to read from stream.');
        }
        $content .= $buffer;
        $bytesRead += \strlen($buffer);

        // If a maxLength is set and we've read that many bytes, stop reading
        if (null !== $maxLength && $bytesRead >= $maxLength) {
            break;
        }
    }

    return $content;
}

/**
 * Non-blocking binary-safe file read
 *
 * @param resource $stream the stream resource to read from
 * @param int      $length maximum number of bytes to read
 *
 * @return string|false the read data on success, or false on failure
 */
function fread($stream, int $length): string|false
{
    if (!\Fiber::getCurrent()) {
        return \fread($stream, $length);
    }
    if (!\is_resource($stream) || 'stream' !== \get_resource_type($stream)) {
        throw new \Exception('Invalid stream resource provided.');
    }

    \stream_set_blocking($stream, false);
    \phasync::readable($stream);

    return \fread($stream, $length);
}

/**
 * Non-blocking get line from file pointer
 *
 * @param resource $stream the stream resource to read from
 * @param int      $length maximum number of bytes to read
 *
 * @return string|false the read data on success, or false on failure
 */
function fgets($stream, ?int $length = null): string|false
{
    if (!\Fiber::getCurrent()) {
        return \fgets($stream, $length);
    }
    if (!\is_resource($stream) || 'stream' !== \get_resource_type($stream)) {
        throw new \Exception('Invalid stream resource provided.');
    }

    \stream_set_blocking($stream, false);
    \phasync::readable($stream);

    return \fgets($stream, $length);
}

/**
 * Non-blocking get character from file pointer
 *
 * @param resource $stream the stream resource to read from
 *
 * @return string|false the read data on success, or false on failure
 */
function fgetc($stream): string|false
{
    if (!\Fiber::getCurrent()) {
        return \fgets($stream);
    }
    if (!\is_resource($stream) || 'stream' !== \get_resource_type($stream)) {
        throw new \Exception('Invalid stream resource provided.');
    }

    \stream_set_blocking($stream, false);
    \phasync::readable($stream);

    return \fgetc($stream);
}
/**
 * Non-blocking get line from file pointer and parse for CSV fields
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
    if (!\Fiber::getCurrent()) {
        return \fgetcsv($stream, $length, $separator, $enclosure, $escape);
    }
    if (!\is_resource($stream) || 'stream' !== \get_resource_type($stream)) {
        throw new \Exception('Invalid stream resource provided.');
    }

    \stream_set_blocking($stream, false);
    \phasync::readable($stream);

    return \fgetcsv($stream, $length, $separator, $enclosure, $escape);
}

/**
 * Async version of {@see \fwrite()}
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
    if (!\Fiber::getCurrent()) {
        return \fwrite($stream, $data);
    }
    if (!\is_resource($stream) || 'stream' !== \get_resource_type($stream)) {
        throw new \Exception('Invalid stream resource provided.');
    }

    \stream_set_blocking($stream, false);
    \phasync::writable($stream);

    return \fwrite($stream, $data);
}

/**
 * Async version of {@see \ftruncate()}
 *
 * @param resource $stream the stream resource to write to
 * @param int      $size   the size to truncate to
 *
 * @return int|false returns true on success or false on failure
 */
function ftruncate($stream, int $size): int|false
{
    if (!\Fiber::getCurrent()) {
        return \ftruncate($stream, $size);
    }
    if (!\is_resource($stream) || 'stream' !== \get_resource_type($stream)) {
        throw new \Exception('Invalid stream resource provided.');
    }

    \stream_set_blocking($stream, false);
    \phasync::writable($stream);

    return \ftruncate($stream, $size);
}

/**
 * Async version of {@see \flock()}
 *
 * @throws \Exception
 * @throws \Throwable
 */
function flock($stream, int $operation, ?int &$would_block = null): bool
{
    if (!\Fiber::getCurrent()) {
        return \flock($stream, $operation, $would_block);
    }

    if (!\is_resource($stream) || 'stream' !== \get_resource_type($stream)) {
        throw new \Exception('Invalid stream resource provided.');
    }

    if ($operation & \LOCK_NB) {
        return \flock($stream, $operation, $would_block);
    }

    $operation |= \LOCK_NB; // Ensure non-blocking mode is always enabled.
    do {
        $result = \flock($stream, $operation, $blocked);
        if ($result) {
            return true; // Successfully acquired the lock.
        } elseif (!$blocked) {
            return false; // Failed to acquire the lock for a reason other than blocking.
        }
        \phasync::yield(); // Yield execution to allow other tasks to proceed.
    } while ($blocked);
}
