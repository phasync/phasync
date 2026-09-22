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
                $buffer = \fread(\phasync::readable($fp), 65536);
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
                    $chunk = \fread(\phasync::readable($data), 65536);
                    if (false === $chunk) {
                        throw new \RuntimeException('Unable to read from stream resource ' . \get_resource_id($data));
                    }
                    while (true) {
                        $written = \fwrite(\phasync::writable($fp), $chunk);
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
                $fwrite = \fwrite(\phasync::writable($fp), \substr($data, $written));
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
