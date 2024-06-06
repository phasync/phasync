<?php

namespace phasync\Internal;

use phasync;

/**
 * This class can be used to make any stream resource automatically non-blocking
 * inside phasync coroutines. The stream resource will also continue to work outside
 * of phasync, but will then block the entire script.
 */
class AsyncStream
{
    public $context;
    private $resource;

    /**
     * Make a non-blocking stream resource from an existing normal stream resource.
     */
    public static function wrap($resource): mixed
    {
        if (!\is_resource($resource) || 'stream' !== \get_resource_type($resource)) {
            return $resource;
        }

        $metadata = \stream_get_meta_data($resource);
        if (isset($metadata['wrapper_type']) && 'user-space' === $metadata['wrapper_type']) {
            return $resource;
        }

        // Extend options
        $options = \stream_context_get_options($resource);
        if (isset($options['phasyncio'])) {
            // No point in double wrapping the stream resource
            return $resource;
        }
        $options['phasyncio'] = ['resource' => $resource];

        \stream_wrapper_register('phasyncio', self::class);

        $wrappedResource = \fopen('phasyncio://' . ($metadata['uri'] ?? 'void'), '', false, \stream_context_create($options));

        \stream_wrapper_unregister('phasyncio');

        return $wrappedResource;
    }

    public function stream_open($path, $mode, $options, &$opened_path)
    {
        if (!$this->context) {
            return false;
        }
        $ctxOptions = \stream_context_get_options($this->context);
        if (!isset($ctxOptions['phasyncio']['resource'])) {
            if ($options & \STREAM_REPORT_ERRORS) {
                \trigger_error('Invalid use of PhasyncStream wrapper', \E_USER_WARNING);
            }

            return false;
        }

        // Use the underlying stream resource
        $this->resource = $ctxOptions['phasyncio']['resource'];
        if (!\is_resource($this->resource) || 'stream' !== \get_resource_type($this->resource)) {
            if ($options & \STREAM_REPORT_ERRORS) {
                \trigger_error('Can only wrap a stream resource', \E_USER_WARNING);
            }

            return false;
        }

        \stream_set_blocking($this->resource, false);
        $metadata = \stream_get_meta_data($this->resource);
        if (isset($metadata['uri'])) {
            $opened_path = $metadata['uri'];
        }

        return false !== $this->resource;
    }

    public function stream_read($count)
    {
        \phasync::readable($this->resource);

        return \fread($this->resource, $count);
    }

    public function stream_write($data)
    {
        \phasync::writable($this->resource);

        return \fwrite($this->resource, $data);
    }

    public function stream_cast(int $cast_as)
    {
        // Do not allow double-polling of the stream resource
        if (\STREAM_CAST_FOR_SELECT === $cast_as) {
            return $this->resource;
        }

        return false;
    }

    public function stream_close()
    {
        return \fclose($this->resource);
    }

    public function stream_eof()
    {
        return \feof($this->resource);
    }

    public function stream_stat()
    {
        return \fstat($this->resource);
    }

    public function url_stat($path, $flags)
    {
        // Perform the stat operation
        return ($flags & \STREAM_URL_STAT_LINK) ? \lstat($path) : \stat($path);
    }

    public function stream_seek($offset, $whence = \SEEK_SET)
    {
        return 0 === \fseek($this->resource, $offset, $whence);
    }

    public function stream_tell()
    {
        return \ftell($this->resource);
    }

    public function stream_flush()
    {
        \phasync::writable($this->resource);

        return \fflush($this->resource);
    }

    public function stream_set_option($option, $arg1, $arg2)
    {
        // This method is not required for basic file operations
        return false;
    }

    public function stream_lock($operation)
    {
        return \flock($this->resource, $operation);
    }

    public function stream_metadata($path, $option, $var)
    {
        // Perform the metadata operation
        $result = false;
        switch ($option) {
            case \STREAM_META_TOUCH:
                \phasync::idle(0.5);
                $result = \touch($path, $var[0], $var[1]);
                break;
            case \STREAM_META_OWNER_NAME:
            case \STREAM_META_OWNER:
                \phasync::idle(0.5);
                $result = \chown($path, $var);
                break;
            case \STREAM_META_GROUP_NAME:
            case \STREAM_META_GROUP:
                \phasync::idle(0.5);
                $result = \chgrp($path, $var);
                break;
            case \STREAM_META_ACCESS:
                \phasync::idle(0.5);
                $result = \chmod($path, $var);
                break;
        }

        return $result;
    }
}
