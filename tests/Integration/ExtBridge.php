<?php

namespace phasync\Ext;

/**
 * Bridges phasync-ext's raw-fd read/write/sleep handlers to phasync's own cooperative
 * scheduler, so that hooked sockets/pipes/files suspend through phasync's real driver --
 * not just through the extension's own isolated test harness.
 *
 * Built against phasync\ext\manage(Closure $code, Closure $readHandler, Closure
 * $writeHandler, Closure $sleepHandler): mixed -- the extension's scoped, stacking hook
 * API (replaced the earlier global enable_hooks()/register_*_handler() design, which
 * required manual install()/uninstall() pairing and could not compose). Handlers are
 * active only for the dynamic extent of $code and are removed automatically when it
 * returns or throws, which is exactly the lifetime we need here -- no separate
 * install()/uninstall() step, no risk of the run()-waits-on-a-still-alive-poller deadlock
 * we hit with the old API (docs/-- see git history on this file for that investigation).
 *
 * phasync-ext's handlers receive a raw int fd, not a stream resource, so they can't call
 * phasync::readable()/writable() directly (those require a resource). This bridge instead
 * runs ONE poller coroutine (scoped to the same manage() call, via phasync::go(), and
 * explicitly awaited before returning) that polls every currently-pending fd together via
 * \phasync\ext\stream_select() (accepts raw fds -- PHP's native stream_select() does not)
 * and wakes each waiter through phasync's normal flag mechanism. No busy-polling: the
 * poller itself is parked on a flag whenever nothing is pending, and is woken immediately
 * the moment something new is registered.
 *
 * This is proof-of-concept integration code, not a decided part of phasync's public API.
 */
final class ExtBridge
{
    private static array $pending = []; // fd => ['flag' => object, 'mode' => 'read'|'write']
    private static ?object $activity = null;
    private static bool $stop = false;

    public static function pendingCount(): int
    {
        return \count(self::$pending);
    }

    /**
     * Runs $fn inside phasync::run() with phasync-ext's hooks active (via manage()) for
     * $fn's whole duration, wiring hooked I/O through phasync's real scheduler.
     */
    public static function run(\Closure $fn, ?array $args = []): mixed
    {
        if (!\extension_loaded('phasync')) {
            throw new \LogicException('The phasync extension is not loaded');
        }

        return \phasync::run(function () use ($fn, $args) {
            return \phasync\ext\manage(
                function () use ($fn, $args) {
                    self::$pending  = [];
                    self::$activity = new \stdClass();
                    self::$stop     = false;

                    $poller = \phasync::go(static function () {
                        self::pollLoop();
                    });

                    try {
                        return $fn(...($args ?? []));
                    } finally {
                        // Stop the poller and wait for it to actually exit before manage()
                        // (and thus the hooks) go out of scope -- it must not outlive $code.
                        self::$stop = true;
                        \phasync::raiseFlag(self::$activity);
                        \phasync::await($poller);
                    }
                },
                static fn (int $fd) => self::await($fd, 'read'),
                static fn (int $fd) => self::await($fd, 'write'),
                static fn (int $usec) => \phasync::sleep($usec / 1_000_000),
            );
        });
    }

    private static function await(int $fd, string $mode): void
    {
        $flag = new \stdClass();
        self::$pending[$fd] = ['flag' => $flag, 'mode' => $mode];
        \phasync::raiseFlag(self::$activity);
        try {
            \phasync::awaitFlag($flag, 30.0);
        } finally {
            unset(self::$pending[$fd]);
        }
    }

    private static function pollLoop(): void
    {
        while (!self::$stop) {
            if (empty(self::$pending)) {
                try {
                    \phasync::awaitFlag(self::$activity, 5.0);
                } catch (\phasync\TimeoutException) {
                    // No activity for a while; loop back around and check self::$stop.
                }
                continue;
            }

            $read = $write = [];
            foreach (self::$pending as $fd => $entry) {
                if ('read' === $entry['mode']) {
                    $read[] = $fd;
                } else {
                    $write[] = $fd;
                }
            }
            $r = $read ?: null;
            $w = $write ?: null;
            $e = null;

            // \phasync\ext\stream_select() is a raw blocking poll() call -- it is NOT a
            // phasync suspension point. A non-zero timeout here would block the whole
            // single-threaded process (including the timer that resolves other coroutines'
            // phasync::sleep() calls), starving every other fiber for the full duration.
            // Poll instantly (0 timeout) instead.
            $ready = \phasync\ext\stream_select($r, $w, $e, 0, 0);

            if (false === $ready || 0 === $ready) {
                // Nothing ready yet -- yield with a real (short) delay so this doesn't spin.
                \phasync::sleep(0.001);
                continue;
            }

            foreach (\array_merge($r ?? [], $w ?? []) as $fd) {
                if (isset(self::$pending[$fd])) {
                    \phasync::raiseFlag(self::$pending[$fd]['flag']);
                }
            }

            // Raising a flag only marks the waiting fiber runnable -- it does not actually
            // resume it. Nothing else can run until THIS fiber (the poller) yields, so
            // without a suspension point here, this loop just re-polls the same still-full
            // socket buffer and re-raises the same flag forever, and the waiter never
            // actually gets a scheduler turn to consume the data and remove itself from
            // $pending. phasync::yield() is the documented non-busy-loop way to give other
            // runnable fibers a turn before continuing.
            \phasync::yield();
        }
    }
}
