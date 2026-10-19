<?php

namespace phasync\Services;

use phasync;

/**
 * Runs cURL handles cooperatively, so other coroutines run while a request is in flight.
 *
 * `curl_exec()` blocks the whole process; `CurlAwaiter::await()` suspends only the calling coroutine.
 * Use it where the phasync extension is not loaded, or to be independent of it. All handles awaited
 * in a process share one `curl_multi` handle, driven by whichever coroutine is waiting.
 *
 * @see phasync::go Start the coroutines that make requests side by side
 *
 * @topic blocking-calls-and-the-extension
 *
 * @example examples/CurlAwaiter.await/1-two-requests-at-once.php Two requests at once
 */
final class CurlAwaiter
{
    private static ?\CurlMultiHandle $curlMulti = null;
    private static bool $isRunning              = false;
    /**
     * Raised by await() when it adds a handle while the loop backs off, to end that sleep.
     */
    private static ?\stdClass $wake            = null;
    /**
     * Stores all pending curl multi handles in case of error.
     *
     * @var \CurlHandle[]
     */
    private static array $curlHandles           = [];

    /**
     * @internal use {@see CurlAwaiter::await()}, which registers the handle itself
     */
    public static function register(\CurlHandle $ch): void
    {
        self::init();
        $curlHandleId                     = \spl_object_id($ch);
        $addHandleResult                  = \curl_multi_add_handle(self::$curlMulti, $ch);
        if (\CURLM_OK !== $addHandleResult) {
            throw new \RuntimeException('Failed to add curl handle: ' . \curl_multi_strerror($addHandleResult));
        }
        self::$curlHandles[$curlHandleId] = $ch;
    }

    /**
     * Runs `$ch` to completion and returns its body, without blocking other coroutines.
     *
     * Returns the response body, set `CURLOPT_RETURNTRANSFER` to get one: without it curl writes the body
     * to output and this returns `false`. A failed transfer does not throw and returns an empty string:
     * check `curl_errno($ch)`. Must be called from a coroutine. A cancelled coroutine removes its handle
     * from the multi handle.
     *
     * @param \CurlHandle $ch an initialized handle with its options set
     *
     * @throws \RuntimeException if the handle cannot be added to the multi handle
     */
    public static function await(\CurlHandle $ch): mixed
    {
        self::init();

        $curlHandleId = \spl_object_id($ch);

        if (!isset(self::$curlHandles[$curlHandleId])) {
            self::register($ch);
            if (self::$isRunning && null !== self::$wake) {
                // the loop may be asleep in its backoff, perhaps for 10 ms: the new transfer should not wait for that
                \phasync::raiseFlag(self::$wake);
            }
        }

        /*
         * This will ensure that the fiber that invoked CurlAwaiter::await() will
         * be blocked until the service coroutine raises the flag.
         */
        try {
            do {
                if (self::$isRunning) {
                    // We must be in a coroutine
                    \phasync::awaitFlag($ch, \PHP_FLOAT_MAX);
                } else {
                    \phasync::run(function () use ($ch) {
                        try {
                            self::$isRunning = true;
                            $delay           = 0.0;
                            do {
                                $status = \curl_multi_exec(self::$curlMulti, $active);
                                /*
                                    * @var array
                                    */
                                while (false !== ($info = \curl_multi_info_read(self::$curlMulti))) {
                                    if (\CURLMSG_DONE === $info['msg']) {
                                        if ($info['handle'] === $ch) {
                                            unset(self::$curlHandles[\spl_object_id($ch)]);
                                            break;
                                        }
                                        // Activate the fiber that invoked the await() function
                                        \phasync::raiseFlag($info['handle']);
                                        unset(self::$curlHandles[\spl_object_id($info['handle'])]);
                                    }
                                }
                                if ($active && \CURLM_OK === $status) {
                                    // curl exposes no sockets to wait on: run again at once while it has work ready,
                                    // otherwise back off from 0.5 ms to 10 ms
                                    $delay = \curl_multi_select(self::$curlMulti, 0) > 0 ? 0.0 : \min(0.01, \max(0.0005, $delay * 2));
                                    if ($delay > 0.0) {
                                        // the sleep is a coroutine of its own that raises the flag when it ends, since
                                        // a flag timeout this short is only delivered in 10 ms steps; await() raises
                                        // the same flag to cut the sleep short
                                        $tick        = self::$wake = new \stdClass();
                                        $tick->slept = false;
                                        \phasync::service(static function () use ($delay, $tick) {
                                            \phasync::sleep($delay);
                                            $tick->slept = true;
                                            \phasync::raiseFlag($tick);
                                        });
                                        \phasync::awaitFlag($tick, \PHP_FLOAT_MAX);
                                        if (!$tick->slept) {
                                            $delay = 0.0; // cut short by a new transfer: start the backoff over
                                        }
                                    } else {
                                        \phasync::sleep();
                                    }
                                }
                            } while ($active && \CURLM_OK === $status);
                        } finally {
                            self::$isRunning = false;
                            self::$wake      = null;
                            // Ensure that another await is able to continue the service
                            foreach (self::$curlHandles as $id => $handle) {
                                \phasync::raiseFlag($handle);
                            }
                        }
                    });
                }
                // Start over if we are still monitoring
            } while (isset(self::$curlHandles[$curlHandleId]));

            return \curl_multi_getcontent($ch) ?? false;
        } finally {
            // Ensure cleanup
            unset(self::$curlHandles[$curlHandleId]);
            \curl_multi_remove_handle(self::$curlMulti, $ch);
        }
    }

    private static function init(): void
    {
        if (null === self::$curlMulti) {
            self::$curlMulti = \curl_multi_init();
        }
    }
}
