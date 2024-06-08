<?php

namespace phasync;

class io
{
    /**
     * Asynchronously read file contents, similar to {@see file_get_contents()}.
     */
    public static function file_get_contents(string $filename): string|false
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
                $buffer = \fread($fp, 65536);
                if (false === $buffer) {
                    throw new \Exception("Read error with file '$filename'");
                }
                $content .= $buffer;
            }

            return $content;
        } finally {
            \fclose($fp);
        }
    }

    /**
     * Writes data to a file asynchronously.
     */
    public static function file_put_contents(string $filename, mixed $data, int $flags = 0): int|false
    {
        if (!\Fiber::getCurrent()) {
            return \file_put_contents($filename, $data, $flags);
        }
        $context = \stream_context_create();
        $mode    = ($flags & \FILE_APPEND) ? 'a' : 'w';

        $fp = \fopen($filename, $mode, false, $context);
        if (!$fp) {
            throw new \Exception("Unable to open file '$filename' for writing.");
        }

        if ($flags & \LOCK_EX) {
            self::flock($fp, \LOCK_EX);
        }

        \stream_set_blocking($fp, false);

        if (\is_resource($data)) {
            return self::_ensure_nonblocking([$data, $filename, $fp], function ($data, $filename, $fp) {
                while (!\feof($data)) {
                    \phasync::readable($data);
                    $chunk = \fread($data, 65536);
                    if (false === $chunk) {
                        throw new \RuntimeException('Unable to read from stream resource ' . \get_resource_id($data));
                    }
                    while (true) {
                        \phasync::writable($fp);
                        $written = \fwrite($fp, $chunk);
                        if (false === $written) {
                            throw new \RuntimeException("Unable to write to $filename");
                        } elseif ($written < \strlen($chunk)) {
                            $chunk = \substr($chunk, $written);
                        } else {
                            break;
                        }
                    }
                }
            });
        }

        if (\is_array($data)) {
            $data = \implode('', $data);
        }

        try {
            $len     = \strlen($data);
            $written = 0;

            while ($written < $len) {
                \phasync::writable($fp);
                $fwrite = \fwrite($fp, \substr($data, $written));
                if (false === $fwrite) {
                    throw new \RuntimeException("Failed to write to file '$filename'.");
                }
                $written += $fwrite;
            }

            return $written;
        } finally {
            \fclose($fp);
        }
    }

    /**
     * Asynchronously reads until EOF from a given stream resource.
     */
    public static function stream_get_contents($stream, ?int $maxLength = null, int $offset = -1): string|false
    {
        if (!\Fiber::getCurrent()) {
            return \stream_get_contents($stream, $maxLength, $offset);
        }
        if (!\is_resource($stream) || 'stream' !== \get_resource_type($stream)) {
            throw new \TypeError('Argument #1 ($stream) must be of type resource, ' . \get_debug_type($stream) . ' given');
        }

        if (-1 !== $offset) {
            if (-1 === \fseek($stream, $offset)) {
                throw new \RuntimeException("Failed to seek to offset $offset in the stream.");
            }
        }

        return self::_ensure_nonblocking([$stream, $maxLength], function ($stream, $maxLength): string|false {
            $content   = '';
            $bytesRead = 0;

            while (!\feof($stream)) {
                \phasync::readable($stream);
                $buffer = (null === $maxLength) ? \fread($stream, 8192) : \fread($stream, \min(8192, $maxLength - $bytesRead));
                if (false === $buffer) {
                    throw new \RuntimeException('Failed to read from stream.');
                }
                $content .= $buffer;
                $bytesRead += \strlen($buffer);

                if (null !== $maxLength && $bytesRead >= $maxLength) {
                    break;
                }
            }

            return $content;
        });
    }

    /**
     * Non-blocking binary-safe file read.
     */
    public static function fread($stream, int $length): string|false
    {
        if (!\Fiber::getCurrent()) {
            return \fread($stream, $length);
        }

        return self::_ensure_nonblocking([$stream, $length], function ($stream, $length) {
            \phasync::readable($stream);

            return \fread($stream, $length);
        });
    }

    /**
     * Non-blocking get line from file pointer
     *
     * @param resource $stream the stream resource to read from
     * @param int      $length maximum number of bytes to read
     *
     * @return string|false the read data on success, or false on failure
     */
    public static function fgets($stream, ?int $length = null): string|false
    {
        if (!\Fiber::getCurrent()) {
            return \fgets($stream, $length);
        }
        if (!\is_resource($stream) || 'stream' !== \get_resource_type($stream)) {
            throw new \TypeError('Argument #1 ($stream) must be of type resource, ' . \get_debug_type($stream) . ' given');
        }

        return self::_ensure_nonblocking([$stream, $length], function ($stream, $length) {
            \phasync::readable($stream);

            return \fgets($stream, $length);
        });
    }

    /**
     * Non-blocking get character from file pointer.
     *
     * @param resource $stream the stream resource to read from
     *
     * @return string|false the read data on success, or false on failure
     */
    public static function fgetc($stream): string|false
    {
        return \fread($stream, 1);
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
    public static function fgetcsv($stream, ?int $length = null, string $separator = ',', string $enclosure = '"', string $escape = '\\'): array|false
    {
        if (!\Fiber::getCurrent()) {
            return \fgetcsv($stream, $length, $separator, $enclosure, $escape);
        }
        if (!\is_resource($stream) || 'stream' !== \get_resource_type($stream)) {
            throw new \TypeError('Argument #1 ($stream) must be of type resource, ' . \get_debug_type($stream) . ' given');
        }

        return self::_ensure_nonblocking([$stream, $length, $separator, $enclosure, $escape], function ($stream, $length, $separator, $enclosure, $escape): array|false {
            \phasync::readable($stream);

            return \fgetcsv($stream, $length, $separator, $enclosure, $escape);
        });
    }

    /**
     * Format line as CSV and write to file pointer
     *
     * @param resource $stream
     *
     * @throws \TypeError
     */
    public static function fputcsv($stream, array $fields, string $separator = ',', string $enclosure = '"', string $escape = '\\', string $eol = "\n"): int|false
    {
        if (!\Fiber::getCurrent()) {
            return \fputcsv($stream, $fields, $separator, $enclosure, $escape, $eol);
        }

        if (!\is_resource($stream) || 'stream' !== \get_resource_type($stream)) {
            throw new \TypeError('Argument #1 ($stream) must be of type resource, ' . \get_debug_type($stream) . ' given');
        }

        return self::_ensure_nonblocking([$stream, $fields, $separator, $enclosure, $escape, $eol], function ($stream, $fields, $separator, $enclosure, $escape, $eol): array|false {
            \phasync::writable($stream);

            return \fputcsv($stream, $fields, $separator, $enclosure, $escape, $eol);
        });
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
    public static function fwrite($stream, string $data): int|false
    {
        if (!\Fiber::getCurrent()) {
            return \fwrite($stream, $data);
        }
        if (!\is_resource($stream) || 'stream' !== \get_resource_type($stream)) {
            throw new \TypeError('Argument #1 ($stream) must be of type resource, ' . \get_debug_type($stream) . ' given');
        }

        return self::_ensure_nonblocking([$stream, $data], function ($stream, $data) {
            \phasync::writable($stream);

            return \fwrite($stream, $data);
        });
    }

    /**
     * Async version of {@see \ftruncate()}
     *
     * @param resource $stream the stream resource to write to
     * @param int      $size   the size to truncate to
     *
     * @return int|false returns true on success or false on failure
     */
    public static function ftruncate($stream, int $size): int|false
    {
        if (!\Fiber::getCurrent()) {
            return \ftruncate($stream, $size);
        }
        if (!\is_resource($stream) || 'stream' !== \get_resource_type($stream)) {
            throw new \TypeError('Argument #1 ($stream) must be of type resource, ' . \get_debug_type($stream) . ' given');
        }

        return self::_ensure_nonblocking([$stream, $size], function ($stream, $size): int|false {
            \phasync::writable($stream);

            return \ftruncate($stream, $size);
        });
    }

    /**
     * Async version of {@see \flock()}
     *
     * @throws \Exception
     * @throws \Throwable
     */
    public static function flock($stream, int $operation, ?int &$would_block = null): bool
    {
        if (!\Fiber::getCurrent()) {
            return \flock($stream, $operation, $would_block);
        }

        if (!\is_resource($stream) || 'stream' !== \get_resource_type($stream)) {
            throw new \TypeError('Argument #1 ($stream) must be of type resource, ' . \get_debug_type($stream) . ' given');
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

    /**
     * Helper function to make stream operations non-blocking.
     */
    protected static function _ensure_nonblocking(array $args, \Closure $function): mixed
    {
        $md = \stream_get_meta_data($args[0]);
        try {
            \stream_set_blocking($args[0], false);

            return $function(...$args);
        } finally {
            if ($md['blocked']) {
                \stream_set_blocking($args[0], true);
            }
        }
    }
}
