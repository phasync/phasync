<?php

namespace phasync\Services;

use mysqli;

/**
 * Waits for a mysqli asynchronous query without blocking other coroutines.
 *
 * Start the query with `MYSQLI_ASYNC`, then `MySQLiPoll::poll($mysqli)` suspends the calling coroutine
 * until the result is ready, which the caller takes with `reap_async_query()`. Use it where the phasync
 * extension is not loaded, or to be independent of it; with the extension, an ordinary blocking query
 * waits the same way and this class is not needed.
 *
 * ```php
 * phasync::run(function () use ($mysqli) {
 *     $mysqli->query('SELECT SLEEP(1)', MYSQLI_ASYNC);
 *     MySQLiPoll::poll($mysqli);
 *     $result = $mysqli->reap_async_query();
 * });
 * ```
 *
 * One coroutine may poll a connection at a time, because mysqli keeps one result per connection: a
 * second waiter gets a LogicException rather than the first one's result. Connections are pooled one
 * coroutine at a time by {@see phasync\Util\Pool}, which is how a query that failed should be handed
 * back.
 *
 * mysqli offers no sockets for the loop to wait on, so a service coroutine - one for the process, not
 * one per connection - asks `mysqli::poll()` with a zero timeout, never holding up the process: again
 * at once while queries are landing, backing off from 0.5 ms to 10 ms while they are not. A finished
 * query is therefore waited for no longer than that delay, and one that lands in a busy moment is
 * noticed on the spot.
 *
 * Must be called from a coroutine: unlike {@see CurlMulti::await()}, which starts one when it has to,
 * there is nothing to start a query with here.
 *
 * **When the wait is cut short** - `TimeoutException`, or the coroutine being cancelled - the query is
 * still in flight on the connection, and mysqli takes no second query before the first result is
 * reaped. This is left as mysqli put it rather than discarded, so the caller may still wait that
 * result out and reap it; otherwise the connection is spent, and should be closed or given to
 * {@see phasync\Util\Pool::discard()}. Reaping it is enough to use the connection again.
 *
 * @see phasync::go Start the coroutines that query side by side
 *
 * @topic blocking-calls-and-the-extension
 */
final class MySQLiPoll
{
    /**
     * What awaitFlag() is given for a wait that does not run out.
     */
    private const NO_TIMEOUT = \PHP_FLOAT_MAX;

    /**
     * MySQLi connections
     *
     * @var array<int,\mysqli>
     */
    private static array $connections = [];

    /**
     * Errors or exceptions associated with the connection.
     *
     * @var array<int, array{0: string, 1: int}>
     */
    private static array $errors = [];

    /**
     * The connections the service is done with, each settled by a completed query or by a failure.
     *
     * What poll() waits for. The flag each coroutine waits on is only half of it: a service
     * coroutine is run up to its first suspension when it is created (see EventLoop::create()), so
     * the query of the coroutine that just started the service can finish before that coroutine got
     * around to wait - and a flag raised with nobody waiting on it is forgotten, not remembered.
     *
     * @var array<int, true>
     */
    private static array $settled = [];

    /**
     * Indicates if the service is currently running.
     */
    private static bool $running = false;

    /**
     * Suspends the calling coroutine until the connection's asynchronous query has finished.
     *
     * The result is not taken here: `reap_async_query()` after this returns, as mysqli's own
     * asynchronous API requires. What a query that fails throws is mysqli's decision, made there.
     *
     * See the class docblock for what one coroutine at a time means, and for a wait that ends early.
     *
     * @param \mysqli    $connection the MySQLi connection to be polled
     * @param float|null $timeout    the most seconds to wait for the query to complete; null, the
     *                               default, waits for as long as it takes
     *
     * @throws \LogicException           outside a coroutine, or when another coroutine is polling this
     *                                   connection already
     * @throws \phasync\TimeoutException when `$timeout` runs out, leaving the query in flight
     * @throws \RuntimeException         if the service waiting for the query ended without it, or the
     *                                   connection was closed while it was waited for
     *
     * @return \mysqli the connection that has a result to reap
     */
    public static function poll(\mysqli $connection, ?float $timeout = null): \mysqli
    {
        \phasync::getFiber(); // a coroutine to wait in: this throws a LogicException outside one

        $connectionId = \spl_object_id($connection);
        if (isset(self::$connections[$connectionId])) {
            throw new \LogicException("Multiple coroutines can't poll the same mysqli connection");
        }
        self::$connections[$connectionId] = $connection;
        unset(self::$settled[$connectionId], self::$errors[$connectionId]);

        self::runService();

        try {
            // The query may be over before this coroutine waits for it, so it is the state the
            // service leaves behind that is waited for and the flag only the way back in
            while (!isset(self::$settled[$connectionId]) && !isset(self::$errors[$connectionId])) {
                \phasync::awaitFlag($connection, $timeout ?? self::NO_TIMEOUT);
            }

            if (isset(self::$errors[$connectionId])) {
                throw new \RuntimeException(self::$errors[$connectionId][0], self::$errors[$connectionId][1]);
            }

            return $connection;
        } finally {
            unset(self::$connections[$connectionId], self::$errors[$connectionId], self::$settled[$connectionId]);
        }
    }

    /**
     * Starts the service coroutine that drives mysqli::poll(), unless one runs or is waiting to.
     */
    private static function runService(): void
    {
        if (self::$running) {
            return;
        }
        // before the service is created rather than as its first statement: creating a coroutine runs
        // it up to its first suspension (EventLoop::create()), which for a service with one query
        // already served is its end - and a flag set after that would never be cleared again
        self::$running = true;

        try {
            \phasync::service(static function () {
                $delay = 0.0;

                try {
                    while (!empty(self::$connections)) {
                        if (0 === self::pollCompleted()) {
                            // mysqli exposes no sockets for the loop to wait on, so it has to be asked:
                            // back off from 0.5 ms to 10 ms while nothing completes, as CurlMulti does
                            // (#133). A query that lands ends the backoff, so a busy connection is
                            // asked again at once and the 10 ms only applies to a waiting one.
                            $delay = \min(0.01, \max(0.0005, $delay * 2));
                            \phasync::sleep($delay);
                        } else {
                            $delay = 0.0;
                        }
                    }
                } finally {
                    self::$running = false;
                    foreach (self::$connections as $connectionId => $mysqli) {
                        self::$errors[$connectionId]  = ['The MySQLiPoll service terminated without resolving the MySQLi handle', 1];
                        self::$settled[$connectionId] = true;
                        \phasync::raiseFlag($mysqli);
                    }
                }
            });
        } catch (\Throwable $failure) {
            self::$running = false; // no service to run, and none would ever be started again

            throw $failure;
        }
    }

    /**
     * Asks mysqli, without waiting, which of the polled connections have a query finished, and wakes
     * the coroutine waiting on each. Returns how many it woke.
     *
     * The zero timeout is the point: a poll() that waited here would hold up the process, which is
     * what this service exists to avoid. Asking costs a microsecond per connection.
     */
    private static function pollCompleted(): int
    {
        $read   = $error = $reject = \array_values(self::$connections);
        try {
            $result = \mysqli::poll($read, $error, $reject, 0, 0);
        } catch (\Throwable $failure) {
            return self::reportClosed($failure);
        }

        if (empty($result)) {
            // 0: no query has finished yet. false: poll() failed, which it does for a connection
            // that is gone; its waiter's own timeout is the way out of that.
            return 0;
        }

        $woke = 0;
        foreach ([...$read, ...$error, ...$reject] as $mysqli) {
            $connectionId = \spl_object_id($mysqli);
            if (isset(self::$connections[$connectionId])) {
                self::$settled[$connectionId] = true; // before the flag: see the property
                \phasync::raiseFlag($mysqli);
                unset(self::$connections[$connectionId]);
                ++$woke;
            }
        }

        return $woke;
    }

    /**
     * mysqli::poll() threw, which it does for a connection the application closed while this service
     * was watching it - and it throws for the whole call, not only for that connection, so the
     * connections beside it would go unserved and their waiters be told the service had ended.
     * Report to the waiters of the connections that are gone and go on watching the rest.
     *
     * @throws \Throwable itself when no connection turns out to be closed, for the service to end on
     */
    private static function reportClosed(\Throwable $failure): int
    {
        $reported = 0;

        foreach (self::$connections as $connectionId => $connection) {
            if (!self::isClosed($connection)) {
                continue;
            }
            self::$errors[$connectionId]  = ['The mysqli connection was closed while a coroutine waited for its query', 1];
            self::$settled[$connectionId] = true;
            unset(self::$connections[$connectionId]);
            \phasync::raiseFlag($connection);
            ++$reported;
        }

        if (0 === $reported) {
            throw $failure;
        }

        return $reported;
    }

    /**
     * Whether a connection is closed: mysqli has no way to ask, it throws from every call instead.
     */
    private static function isClosed(\mysqli $connection): bool
    {
        try {
            $connection->thread_id;

            return false;
        } catch (\Throwable) {
            return true;
        }
    }
}
