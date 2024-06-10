<?php

namespace phasync\Services;

use mysqli;

/**
 * Provides asynchronous running of MySQLi queries within the phasync framework.
 * To run a MySQLi query asynchronously, use `MySQLiPoll::poll($mysqli)` from inside a coroutine.
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
     * Polls the provided MySQLi connection asynchronously.
     *
     * @param \mysqli  $connection the MySQLi connection to be polled
     * @param int|null $timeout    The maximum time to wait for the query to complete, in seconds.
     *                             If null, waits indefinitely.
     *
     * @throws \LogicException   if the same MySQLi connection is polled by multiple coroutines
     * @throws \RuntimeException if an error occurs during polling
     *
     * @return \mysqli returns the MySQLi connection upon completion
     */
    public static function poll(\mysqli $connection, ?int $timeout = null): \mysqli
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
