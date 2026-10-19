<?php

namespace phasync\Services;

/**
 * The old name of {@see MySQLiAwaiter}.
 *
 * @deprecated use {@see MySQLiAwaiter::await()}
 */
final class MySQLiPoll
{
    /**
     * @deprecated use {@see MySQLiAwaiter::await()}
     */
    public static function poll(\mysqli $connection, ?float $timeout = null): \mysqli
    {
        return MySQLiAwaiter::await($connection, $timeout);
    }
}
