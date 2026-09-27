<?php

namespace phasync\Util;

/**
 * Simple coroutine-safe mutex for synchronizing access to shared resources.
 *
 * This implementation is not reentrant - attempting to acquire the same lock
 * again from the coroutine that holds it will throw an exception. For reentrant
 * locking, use LockTrait instead. Other coroutines wait their turn, also those
 * of the same context (the coroutines of one request share it).
 *
 * Note: Not thread safe if PHP threading is enabled.
 */
final class Synchronized
{
    private static array $locks   = [];
    private static array $holders = [];

    /**
     * Run a function ensuring that the function will not be invoked by other
     * coroutines at the same time.
     *
     * @throws \LogicException if the coroutine that holds the lock asks for it again
     * @throws \Throwable      if the closure throws
     */
    public static function run(object|string $token, \Closure $closure): mixed
    {
        if (\is_object($token)) {
            $token = \spl_object_hash($token);
        }

        // The holder is a coroutine, not a context: the coroutines of one context are many
        $current = \Fiber::getCurrent();
        if (isset(self::$holders[$token]) && self::$holders[$token] === $current) {
            throw new \LogicException('Synchronized::run() is not reentrant; use LockTrait for reentrant locking');
        }

        while (isset(self::$locks[$token])) {
            \phasync::awaitFlag(self::$locks[$token]);
        }
        try {
            self::$locks[$token]   = new \stdClass();
            self::$holders[$token] = $current;

            return $closure();
        } finally {
            \phasync::raiseFlag(self::$locks[$token]);
            unset(self::$locks[$token], self::$holders[$token]);
        }
    }
}
