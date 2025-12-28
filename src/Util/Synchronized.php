<?php

namespace phasync\Util;

/**
 * Simple coroutine-safe mutex for synchronizing access to shared resources.
 *
 * This implementation is not reentrant - attempting to acquire the same lock
 * from within a locked context will throw an exception. For reentrant locking,
 * use LockTrait instead.
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
     * @throws \LogicException if attempting reentrant lock from same context
     * @throws \Throwable      if the closure throws
     */
    public static function run(object|string $token, \Closure $closure): mixed
    {
        if (\is_object($token)) {
            $token = \spl_object_hash($token);
        }

        $currentContext = \phasync::getContext();
        if (isset(self::$holders[$token]) && self::$holders[$token] === $currentContext) {
            throw new \LogicException('Synchronized::run() is not reentrant; use LockTrait for reentrant locking');
        }

        while (isset(self::$locks[$token])) {
            \phasync::awaitFlag(self::$locks[$token]);
        }
        try {
            self::$locks[$token]   = new \stdClass();
            self::$holders[$token] = $currentContext;

            return $closure();
        } finally {
            \phasync::raiseFlag(self::$locks[$token]);
            unset(self::$locks[$token], self::$holders[$token]);
        }
    }
}
