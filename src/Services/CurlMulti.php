<?php

namespace phasync\Services;

/**
 * The old name of {@see CurlAwaiter}.
 *
 * @deprecated use {@see CurlAwaiter::await()}
 */
final class CurlMulti
{
    /**
     * @deprecated use {@see CurlAwaiter::await()}
     */
    public static function await(\CurlHandle $ch): mixed
    {
        return CurlAwaiter::await($ch);
    }
}
