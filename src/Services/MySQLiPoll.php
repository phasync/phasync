<?php

namespace phasync\Services;

use mysqli;

/**
 * Waits for a mysqli asynchronous query without blocking other coroutines.
 *
 * Start the query with `MYSQLI_ASYNC`, then `MySQLiPoll::poll($mysqli)` suspends the calling coroutine
 * until the result is ready. One coroutine may poll a connection at a time.
 *
 * ```php
 * phasync::run(function () use ($mysqli) {
 *     $mysqli->query('SELECT SLEEP(1)', MYSQLI_ASYNC);
 *     MySQLiPoll::poll($mysqli);
 *     $result = $mysqli->reap_async_query();
 * });
 * ```
 *
 * @see phasync::go Start the coroutines that query side by side
 *
 * @topic blocking-calls-and-the-extension
 */
final class MySQLiPoll
{
    /**
     * MySQLi connections
     *
     * @var array<int,\mysqli>
     */
    private static array $connections = [];

    /**
     * Fibers associated with the MySQLi connections
     *
     * @var array<int,\Fiber>
     */
    private static array $fibers = [];

    /**
     * Errors or exceptions associated with the connection.
     *
     * @var array<int, array{0: string, 1: int}>
     */
    private static array $errors = [];

    /**
     * Indicates if the service is currently running.
     */
    private static bool $running = false;

    /**
     * Suspends the calling coroutine until the connection's asynchronous query has finished.
     *
     * @param \mysqli    $connection the MySQLi connection to be polled
     * @param float|null $timeout    The maximum time to wait for the query to complete, in seconds.
     *                               If null, waits indefinitely.
     *
     * @throws \LogicException   if the same MySQLi connection is polled by multiple coroutines
     * @throws \RuntimeException if an error occurs during polling
     *
     * @return \mysqli returns the MySQLi connection upon completion
     */
    public static function poll(\mysqli $connection, ?float $timeout = null): \mysqli
    {
        $connectionId = \spl_object_id($connection);
        if (isset(self::$connections[$connectionId])) {
            throw new \LogicException("Multiple coroutines can't poll the same mysqli connection");
        }
        $fiber = \phasync::getFiber();

        self::$connections[$connectionId] = $connection;
        self::$fibers[$connectionId]      = $fiber;

        self::runService();

        try {
            \phasync::awaitFlag($connection, $timeout);

            if (isset(self::$errors[$connectionId])) {
                throw new \RuntimeException(self::$errors[$connectionId][0], self::$errors[$connectionId][1]);
            }

            return $connection;
        } finally {
            unset(self::$connections[$connectionId]);
            unset(self::$fibers[$connectionId]);
            unset(self::$errors[$connectionId]);
        }
    }

    /**
     * Launches the service coroutine to handle MySQLi polling.
     * Ensures that the polling continues while there are active connections.
     */
    private static function runService(): void
    {
        if (self::$running) {
            return;
        }
        \phasync::service(static function () {
            try {
                self::$running = true;
                while (!empty(self::$connections)) {
                    \phasync::sleep(0.02);
                    $read   = $error = $reject = \array_values(self::$connections);
                    $result = \mysqli::poll($read, $error, $reject, 0, 20000); // 20ms
                    if (0 === $result || false === $result) {
                        continue;
                    }
                    foreach ([...$read, ...$error, ...$reject] as $mysqli) {
                        $connectionId = \spl_object_id($mysqli);
                        if (isset(self::$connections[$connectionId])) {
                            \phasync::raiseFlag($mysqli);
                            unset(self::$connections[$connectionId]);
                        }
                    }
                }
            } finally {
                self::$running = false;
                foreach (self::$connections as $connectionId => $mysqli) {
                    self::$errors[$connectionId] = ['The MySQLiPoll service terminated without resolving the MySQLi handle', 1];
                    \phasync::raiseFlag($mysqli);
                }
            }
        });
    }
}
