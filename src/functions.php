<?php
namespace phasync;

use Closure;
use Exception;
use Fiber;
use FiberError;
use Throwable;

/**
 * Run a coroutine synchronously, and await the result. You can
 * nest calls to run() within other coroutines.
 * 
 * @param Closure $coroutine 
 * @param array $args 
 * @return mixed 
 * @throws FiberError 
 * @throws Throwable 
 */
function run(Closure $coroutine, mixed ...$args): mixed {
    return Loop::run($coroutine, ...$args);
}

/**
 * Run a coroutine asynchronously. The return value is a {@see \Fiber}
 * instance. You can use {@see await()} to resolve the return value.
 * 
 * @param Closure $coroutine 
 * @param array $args 
 * @return Fiber 
 * @throws FiberError 
 * @throws Throwable 
 */
function go(Closure $coroutine, mixed ...$args): Fiber {
    return Loop::go($coroutine, ...$args);
}

/**
 * Wait for a {@see Fiber} to complete and return the result.
 * 
 * @param Fiber $fiber 
 * @return mixed 
 * @throws Throwable 
 */
function await(Fiber $fiber): mixed {
    return Loop::await($fiber);
}

/**
 * Pause execution from within a coroutine, allowing other coroutines
 * to act.
 * 
 * @param float $seconds 
 * @return void 
 * @throws FiberError 
 * @throws Throwable 
 */
function sleep(float $seconds): void {
    Loop::sleep($seconds);
}

/**
 * Pause execution until there are no immediately pending coroutines
 * that need to work.
 * 
 * @return void 
 */
function wait_idle(): void {
    Loop::idle();
}

/**
 * Asynchronously read a file contents, similar to {@see \file_get_contents()}.
 * Whenever IO blocks, other coroutines will be allowed to continue processing.
 * 
 * @param string $filename 
 * @return string 
 * @throws Exception 
 * @throws FiberError 
 * @throws Throwable 
 */
function file_get_contents(string $filename): string|false {
    $fp = fopen($filename, 'r');
    if (!$fp) {
        throw new Exception("Unable to open file '$filename'");
    }

    stream_set_blocking($fp, false);
    $content = '';

    try {
        while (!feof($fp)) {
            // Assume `readable` is a function that waits until the file pointer is readable.
            Loop::readable($fp);
            $buffer = fread($fp, 8192);
            if ($buffer === false) {
                throw new Exception("Read error with file '$filename'");
            }
            $content .= $buffer;
        }
        return $content;
    } finally {
        fclose($fp); // Ensure the file pointer is always closed.
    }
}

/**
 * Writes data to a file asynchronously.
 * 
 * This function is modeled after file_put_contents(), but it performs
 * the write operation in a non-blocking manner using the event loop.
 * 
 * @param string $filename Path to the file where to write the data.
 * @param mixed $data The data to write. Can be a string, an array or a stream resource.
 * @param int $flags Flags to modify the behavior of the write operation (e.g., FILE_APPEND).
 * @return void
 * @throws Exception if unable to open the file or write fails.
 */
function file_put_contents(string $filename, mixed $data, int $flags = 0): void {
    $context = stream_context_create();
    $mode = ($flags & FILE_APPEND) ? 'a' : 'w';
    
    $fp = fopen($filename, $mode, false, $context);
    if (!$fp) {
        throw new Exception("Unable to open file '$filename' for writing.");
    }
    
    stream_set_blocking($fp, false);

    // If $data is a resource, get the content from the resource.
    if (is_resource($data)) {
        $data = stream_get_contents($data);
    }

    // Convert $data to a string if it is an array.
    if (is_array($data)) {
        $data = implode('', $data);
    }

    try {
        $len = strlen($data);
        $written = 0;

        while ($written < $len) {
            Loop::writable($fp);

            $fwrite = fwrite($fp, substr($data, $written));
            if ($fwrite === false) {
                throw new Exception("Failed to write to file '$filename'.");
            }
            $written += $fwrite;
        }
    } finally {
        fclose($fp); // Ensure the file pointer is always closed.
    }
}

/**
 * Asynchronously reads until EOF from a given stream resource, similar to {@see \stream_get_contents()}.
 * This function allows the event loop to continue processing other tasks whenever IO would block.
 *
 * @param resource $stream The stream resource from which to read.
 * @param int|null $maxLength Maximum bytes to read. Null for no limit, until EOF.
 * @param int $offset Seek to the specified offset before reading (if the stream supports seeking).
 * @return string|false The read data on success, or false on failure.
 * @throws Exception If unable to seek in the stream or other reading errors occur.
 * @throws FiberError If called outside a coroutine context where necessary.
 * @throws Throwable For any unexpected errors during operation.
 */
function stream_get_contents($stream, ?int $maxLength = null, int $offset = 0): string|false {
    if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
        throw new Exception("The provided argument is not a valid stream resource.");
    }

    // If offset is specified and valid, seek to it before reading
    if ($offset !== 0) {
        if (!fseek($stream, $offset)) {
            throw new Exception("Failed to seek to offset $offset in the stream.");
        }
    }

    stream_set_blocking($stream, false);
    $content = '';
    $bytesRead = 0;

    while (!feof($stream)) {
        Loop::readable($stream);
        $buffer = ($maxLength === null) ? fread($stream, 8192) : fread($stream, min(8192, $maxLength - $bytesRead));
        if ($buffer === false) {
            // Depending on the implementation, you may want to return false or throw an exception
            throw new Exception("Failed to read from stream.");
        }
        $content .= $buffer;
        $bytesRead += strlen($buffer);

        // If a maxLength is set and we've read that many bytes, stop reading
        if ($maxLength !== null && $bytesRead >= $maxLength) {
            break;
        }
    }
    return $content;
}

/**
 * Performs a non-blocking read operation on a given stream.
 *
 * @param resource $stream The stream resource to read from.
 * @param int $length Maximum number of bytes to read.
 * @return string|false The read data on success, or false on failure.
 * @throws Exception If the read operation fails.
 */
function fread($stream, int $length): string|false {
    if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
        throw new Exception("Invalid stream resource provided.");
    }

    stream_set_blocking($stream, false);

    Loop::readable($stream);

    $data = fread($stream, $length);
    if ($data === false) {
        throw new Exception("Failed to read from the stream.");
    }

    return $data;
}

/**
 * Performs a non-blocking write operation on a given stream.
 *
 * @param resource $stream The stream resource to write to.
 * @param string $data The data to write.
 * @return int|false The number of bytes written, or false on failure.
 * @throws Exception If the write operation fails.
 */
function fwrite($stream, string $data): int|false {
    if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
        throw new Exception("Invalid stream resource provided.");
    }

    stream_set_blocking($stream, false);

    $totalWritten = 0;
    $length = strlen($data);

    while ($totalWritten < $length) {
        Loop::writable($stream);

        $written = fwrite($stream, substr($data, $totalWritten));
        if ($written === false) {
            throw new Exception("Failed to write to the stream.");
        }

        $totalWritten += $written;
    }

    return $totalWritten;
}